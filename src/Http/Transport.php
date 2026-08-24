<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Http;

use Closure;
use Pactman\NonprofitCheckPlus\Config\ClientConfig;
use Pactman\NonprofitCheckPlus\Config\RetryOptions;
use Pactman\NonprofitCheckPlus\Exception\ApiErrorInit;
use Pactman\NonprofitCheckPlus\Exception\ApiException;
use Pactman\NonprofitCheckPlus\Exception\NetworkException;
use Pactman\NonprofitCheckPlus\Exception\TimeoutException;
use Pactman\NonprofitCheckPlus\Model\ApiErrorDetail;

/**
 * HTTP transport: authentication headers, timeouts, retries with jittered
 * backoff, `Retry-After` handling, and mapping responses to the error taxonomy.
 *
 * The API key is held in a closure and written into the `Authorization` header
 * at send time. It is never stored as a property, so no diagnostic that walks
 * this object — `print_r`, `var_dump`, `json_encode` — can reach it.
 *
 * Internal. Nothing here is part of the public API.
 */
final class Transport
{
    /** @var Closure(float): void */
    private readonly Closure $sleep;

    /** @var Closure(): float */
    private readonly Closure $random;

    /** @var Closure(): float */
    private readonly Closure $monotonic;

    private float $nextRequestAt = 0.0;

    /** @param Closure(): string $credential Returns the `Authorization` header value. */
    public function __construct(
        private readonly Closure $credential,
        private readonly ClientConfig $config,
        private readonly HttpClientInterface $httpClient,
        ?TransportHooks $hooks = null,
    ) {
        $hooks ??= new TransportHooks();

        $this->sleep = $hooks->sleep ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };

        $this->random = $hooks->random ?? static fn (): float => mt_rand() / mt_getrandmax();
        $this->monotonic = $hooks->monotonic ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    /**
     * Sends a request, retrying it under the resolved policy.
     *
     * @param 'GET'|'POST'                                $method
     * @param list<string>|null                           $body    JSON-encoded when present.
     * @param RetryOptions|array<string, mixed>|bool|null $retry
     * @param array<string, string>                       $headers
     *
     * @throws ApiException     for a non-2xx response the policy will not retry.
     * @throws TimeoutException when the deadline expired on the final attempt.
     * @throws NetworkException when no response was produced on the final attempt.
     */
    public function send(
        string $method,
        string $path,
        ?array $body = null,
        ?float $timeout = null,
        RetryOptions|array|bool|null $retry = null,
        array $headers = [],
    ): TransportResponse {
        $policy = RetryOptions::resolve($this->config->retry, $retry);
        $request = new HttpRequest(
            method: $method,
            url: $this->config->baseUrl . $path,
            headers: $this->buildHeaders($headers, $body !== null),
            body: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
            timeout: $timeout ?? $this->config->timeout,
        );

        $attempts = 0;

        while (true) {
            ++$attempts;
            $this->throttle();

            try {
                $response = $this->httpClient->send($request);
            } catch (TransportException $failure) {
                $error = $this->classify($failure, $request->timeout, $attempts);

                if ($attempts > $policy->maxRetries) {
                    throw $error;
                }

                ($this->sleep)(self::computeRetryDelay($attempts, $policy, null, $this->random));

                continue;
            }

            $parsed = self::parseBody($response);

            if ($response->isSuccess()) {
                return new TransportResponse(
                    status: $response->status,
                    requestId: self::readRequestId($response),
                    body: $parsed,
                    attempts: $attempts,
                );
            }

            $retryAfter = self::readRetryAfter($response);
            $error = ApiException::fromStatus(self::buildApiErrorInit(
                status: $response->status,
                parsed: $parsed,
                requestId: self::readRequestId($response),
                retryAfterSeconds: $retryAfter,
                attempts: $attempts,
            ));

            if ($attempts > $policy->maxRetries || !$policy->isRetryableStatus($response->status)) {
                throw $error;
            }

            ($this->sleep)(self::computeRetryDelay($attempts, $policy, $retryAfter, $this->random));
        }
    }

    /**
     * @param array<string, string> $perRequest
     *
     * @return array<string, string>
     */
    private function buildHeaders(array $perRequest, bool $hasBody): array
    {
        $headers = [...$this->config->defaultHeaders, ...$perRequest];

        // Set last so neither the client defaults nor a per-request header can
        // displace the credential or misdeclare the payload.
        $headers['Accept'] = 'application/json';
        $headers['User-Agent'] = $this->config->userAgent;
        $headers['Authorization'] = ($this->credential)();

        if ($hasBody) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }

    /** Spaces requests when `maxRequestsPerSecond` is configured. */
    private function throttle(): void
    {
        $limit = $this->config->maxRequestsPerSecond;

        if ($limit === null) {
            return;
        }

        $interval = 1.0 / $limit;
        $now = ($this->monotonic)();
        $scheduledAt = max($now, $this->nextRequestAt);
        $this->nextRequestAt = $scheduledAt + $interval;

        if ($scheduledAt > $now) {
            ($this->sleep)($scheduledAt - $now);
        }
    }

    private function classify(
        TransportException $failure,
        float $timeout,
        int $attempts,
    ): TimeoutException|NetworkException {
        if ($failure->isTimeout) {
            return new TimeoutException(
                "The request timed out after {$timeout}s.",
                $timeout,
                $attempts,
                $failure,
            );
        }

        return new NetworkException(
            "The request to the Pactman API failed: {$failure->getMessage()}",
            $attempts,
            $failure,
        );
    }

    /**
     * Delay before the next attempt, in seconds.
     *
     * A valid `Retry-After` wins outright. Otherwise the delay grows
     * exponentially from `initialDelay`, is capped at `maxDelay`, and — with
     * jitter on — is randomized across the whole range so concurrent clients
     * spread out.
     *
     * @param (Closure(): float)|null $random
     */
    public static function computeRetryDelay(
        int $attempt,
        RetryOptions $retry,
        ?float $retryAfterSeconds,
        ?Closure $random = null,
    ): float {
        if ($retry->respectRetryAfter && $retryAfterSeconds !== null && $retryAfterSeconds >= 0) {
            return round($retryAfterSeconds, 3);
        }

        $exponential = $retry->initialDelay * $retry->backoffFactor ** ($attempt - 1);
        $capped = min($exponential, $retry->maxDelay);

        if (!$retry->jitter) {
            return round($capped, 3);
        }

        $random ??= static fn (): float => mt_rand() / mt_getrandmax();

        return round($random() * $capped, 3);
    }

    /** Reads `Retry-After` as either a delay in seconds or an HTTP date. */
    public static function readRetryAfter(HttpResponse $response, ?float $now = null): ?float
    {
        $raw = $response->header('retry-after');

        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);

        if ($trimmed === '') {
            return null;
        }

        if (is_numeric($trimmed)) {
            $seconds = (float) $trimmed;

            return $seconds >= 0 ? $seconds : null;
        }

        $timestamp = strtotime($trimmed);

        if ($timestamp === false) {
            return null;
        }

        return max(0.0, $timestamp - ($now ?? microtime(true)));
    }

    /**
     * Normalizes the envelope's `errors` into a list.
     *
     * @return list<ApiErrorDetail>
     */
    public static function normalizeApiErrors(mixed $errors): array
    {
        if ($errors === null) {
            return [];
        }

        if (is_string($errors)) {
            return trim($errors) === '' ? [] : [ApiErrorDetail::fromArray(['reason' => $errors])];
        }

        if (!is_array($errors)) {
            return [];
        }

        // A single error object, rather than a list of them.
        if ($errors !== [] && !array_is_list($errors)) {
            /** @var array<string, mixed> $errors */
            return [ApiErrorDetail::fromArray($errors)];
        }

        $details = [];

        foreach ($errors as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $details[] = ApiErrorDetail::fromArray($entry);
            }
        }

        return $details;
    }

    /**
     * Collects the response metadata an API error carries.
     *
     * @param array<string, mixed>|string|null $parsed
     */
    public static function buildApiErrorInit(
        int $status,
        array|string|null $parsed,
        ?string $requestId,
        ?float $retryAfterSeconds,
        int $attempts,
    ): ApiErrorInit {
        $envelope = is_array($parsed) ? $parsed : null;
        $apiErrors = self::normalizeApiErrors($envelope['errors'] ?? null);

        $reasons = [];

        foreach ($apiErrors as $detail) {
            if ($detail->reason !== null && trim($detail->reason) !== '') {
                $reasons[] = $detail->reason;
            }
        }

        $envelopeMessage = $envelope['message'] ?? null;
        $apiCode = $envelope['code'] ?? null;

        $apiMessage = match (true) {
            $reasons !== [] => implode('; ', $reasons),
            is_string($envelopeMessage) => $envelopeMessage,
            is_string($parsed) && trim($parsed) !== '' => substr(trim($parsed), 0, 500),
            default => null,
        };

        return new ApiErrorInit(
            status: $status,
            apiMessage: $apiMessage,
            apiCode: is_int($apiCode) ? $apiCode : null,
            apiErrors: $apiErrors,
            requestId: $requestId,
            retryAfterSeconds: $retryAfterSeconds,
            raw: $parsed,
            attempts: $attempts,
        );
    }

    /**
     * Parses a response body, preserving unparseable text as evidence.
     *
     * @return array<string, mixed>|string|null
     */
    public static function parseBody(HttpResponse $response): array|string|null
    {
        $text = $response->body;

        if (trim($text) === '') {
            return null;
        }

        try {
            $decoded = json_decode($text, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Preserve whatever the server sent; an unparseable body is still evidence.
            return $text;
        }

        if (!is_array($decoded)) {
            // A bare JSON scalar is not an envelope; keep the text as evidence.
            return $text;
        }

        // The single point where a decoded body is taken to be a JSON object.
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** Reads the correlation identifier from the response headers. */
    public static function readRequestId(HttpResponse $response): ?string
    {
        foreach (['x-request-id', 'x-correlation-id', 'request-id'] as $name) {
            $value = $response->header($name);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
