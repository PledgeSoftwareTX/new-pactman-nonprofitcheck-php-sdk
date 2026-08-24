<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Http;

/**
 * One outbound HTTP request, fully prepared.
 *
 * The `Authorization` header is written here at send time and is never stored on
 * a client, an exception, or any diagnostic output.
 */
final class HttpRequest
{
    /**
     * @param string                $method  `GET` or `POST`.
     * @param string                $url     Absolute URL.
     * @param array<string, string> $headers Header name => value.
     * @param string|null           $body    Encoded request body, or `null` for a bodyless request.
     * @param float                 $timeout Deadline for this attempt, in seconds.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly float $timeout,
    ) {
    }

    /**
     * The headers as `Name: value` lines, the form cURL and most clients want.
     *
     * @return list<string>
     */
    public function headerLines(): array
    {
        $lines = [];

        foreach ($this->headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return $lines;
    }
}
