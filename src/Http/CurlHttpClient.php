<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Http;

use CurlHandle;

/**
 * The default HTTP client, built on ext-curl.
 *
 * One cURL handle is kept for the life of the client and reset between requests,
 * so connections are reused across calls. Build one {@see \Pactman\NonprofitCheckPlus\PactmanClient}
 * per process and share it, rather than one per request, to get the benefit.
 *
 * Not safe to share across threads or processes; each needs its own client.
 */
final class CurlHttpClient implements HttpClientInterface
{
    /**
     * `CURLE_OPERATION_TIMEDOUT`, named here rather than referenced through the
     * ext-curl constant, whose spelling has varied between PHP builds.
     */
    private const CURL_TIMEOUT_ERRNO = 28;

    private ?CurlHandle $handle = null;

    /**
     * @param bool     $followRedirects Follow up to five redirects, as the other Pactman SDKs do.
     * @param string|null $caBundle     Path to a CA bundle, when the system store is not the one to trust.
     * @param array<int, mixed> $curlOptions Extra cURL options, applied before the SDK's own.
     *     The SDK's URL, method, headers, body and timeout always win.
     */
    public function __construct(
        private readonly bool $followRedirects = true,
        private readonly ?string $caBundle = null,
        private readonly array $curlOptions = [],
    ) {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $handle = $this->handle();
        curl_reset($handle);

        $headers = [];
        $timeoutMs = max(1, (int) round($request->timeout * 1000));

        $options = [
            ...$this->curlOptions,
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $request->headerLines(),
            CURLOPT_FOLLOWLOCATION => $this->followRedirects,
            CURLOPT_MAXREDIRS => 5,
            // Millisecond timeouts need the signal handler disabled to be safe
            // under threads; the connect budget is bounded by the overall one.
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $_handle, string $line) use (&$headers): int {
                $length = strlen($line);
                $trimmed = trim($line);

                // Each response in a redirect chain restarts the header block.
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $headers = [];

                    return $length;
                }

                $parts = explode(':', $trimmed, 2);

                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ];

        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }

        if ($this->caBundle !== null) {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }

        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);

        if ($body === false) {
            $errno = curl_errno($handle);

            throw new TransportException(
                curl_error($handle) ?: "cURL failed with error {$errno}.",
                isTimeout: $errno === self::CURL_TIMEOUT_ERRNO,
            );
        }

        /** @var array<string, string> $headers */
        return new HttpResponse(
            status: (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            headers: $headers,
            body: is_string($body) ? $body : '',
        );
    }

    private function handle(): CurlHandle
    {
        return $this->handle ??= curl_init();
    }

    /**
     * Releases the connection this client is holding.
     *
     * Dropping the handle is the release: since PHP 8.0 a `CurlHandle` is an
     * object freed with its last reference, and `curl_close()` has done nothing
     * since — it is deprecated as of 8.5. A client is usable again after this;
     * the next request opens a fresh connection.
     */
    public function close(): void
    {
        $this->handle = null;
    }
}
