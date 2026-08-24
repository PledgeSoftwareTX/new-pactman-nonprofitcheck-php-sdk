<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Http;

/** One HTTP response, as the SDK's transport sees it. */
final class HttpResponse
{
    /** @var array<string, string> Header names lower-cased. */
    public readonly array $headers;

    /**
     * @param int                   $status  HTTP status code.
     * @param array<string, string> $headers Response headers; names are lower-cased on the way in.
     * @param string                $body    The raw response body, undecoded.
     */
    public function __construct(
        public readonly int $status,
        array $headers,
        public readonly string $body,
    ) {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        $this->headers = $normalized;
    }

    /** A header's value, or `null` when the server did not send it. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** True for 2xx. */
    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
