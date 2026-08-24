<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Exception;

use Pactman\NonprofitCheckPlus\Model\ApiErrorDetail;

/**
 * An error returned by the Pactman API.
 *
 * Thrown directly when the status maps to no more specific subclass; response
 * metadata is preserved even when the body could not be deserialized.
 */
class ApiException extends PactmanException
{
    /** HTTP status code. */
    public readonly int $status;

    /** `message` from the Pactman response envelope, when present. */
    public readonly ?string $apiMessage;

    /** `code` from the Pactman response envelope, when present. */
    public readonly ?int $apiCode;

    /**
     * Item-level failures from the envelope's `errors`.
     *
     * @var list<ApiErrorDetail>
     */
    public readonly array $apiErrors;

    /** Correlation identifier from the response headers, when the server sent one. */
    public readonly ?string $requestId;

    /** `Retry-After` in seconds, when the server supplied a valid value. */
    public readonly ?float $retryAfterSeconds;

    /**
     * The parsed response body, or the raw text when it was not JSON.
     *
     * @var array<string, mixed>|string|null
     */
    public readonly array|string|null $raw;

    /** How many attempts were made before this error was surfaced. */
    public readonly int $attempts;

    public function __construct(
        string $message,
        ApiErrorInit $init,
        ErrorCategory $category = ErrorCategory::Api,
    ) {
        parent::__construct($message, $category, ErrorOrigin::Api);

        $this->status = $init->status;
        $this->apiMessage = $init->apiMessage;
        $this->apiCode = $init->apiCode;
        $this->apiErrors = $init->apiErrors;
        $this->requestId = $init->requestId;
        $this->retryAfterSeconds = $init->retryAfterSeconds;
        $this->raw = $init->raw;
        $this->attempts = $init->attempts;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'status' => $this->status,
            'apiMessage' => $this->apiMessage,
            'apiCode' => $this->apiCode,
            'apiErrors' => array_map(
                static fn (ApiErrorDetail $detail): array => $detail->toArray(),
                $this->apiErrors,
            ),
            'requestId' => $this->requestId,
            'retryAfterSeconds' => $this->retryAfterSeconds,
            'attempts' => $this->attempts,
        ];
    }

    /** Builds the exception subclass that matches an HTTP status code. */
    public static function fromStatus(ApiErrorInit $init): self
    {
        $message = trim($init->apiMessage ?? '');

        if ($message === '') {
            $message = self::defaultMessageForStatus($init->status);
        }

        return match (true) {
            $init->status === 400 => new BadRequestException($message, $init),
            $init->status === 401 => new AuthenticationException($message, $init),
            $init->status === 403 => new AuthorizationException($message, $init),
            $init->status === 404 => new NotFoundException($message, $init),
            $init->status === 429 => new RateLimitException($message, $init),
            $init->status >= 500 => new ServerException($message, $init),
            default => new self($message, $init),
        };
    }

    private static function defaultMessageForStatus(int $status): string
    {
        return match (true) {
            $status === 400 => 'The Pactman API rejected the request.',
            $status === 401 => 'The Pactman API key was rejected.',
            $status === 403 => 'This Pactman API key is not permitted to access that resource.',
            $status === 404 => 'No matching record was found.',
            $status === 429 => 'The Pactman API rate limit was exceeded.',
            $status >= 500 => "The Pactman API returned a server error (HTTP {$status}).",
            default => "The Pactman API returned an unexpected response (HTTP {$status}).",
        };
    }
}
