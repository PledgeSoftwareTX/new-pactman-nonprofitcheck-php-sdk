<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Http;

/** A parsed HTTP response plus the metadata callers need. */
final class TransportResponse
{
    /**
     * @param int                              $status    HTTP status of the response.
     * @param string|null                      $requestId Correlation identifier from the headers, when present.
     * @param array<string, mixed>|string|null $body      The decoded envelope, the raw text when it
     *     was not JSON, or `null` for an empty body.
     * @param int                              $attempts  How many attempts were made.
     */
    public function __construct(
        public readonly int $status,
        public readonly ?string $requestId,
        public readonly array|string|null $body,
        public readonly int $attempts,
    ) {
    }

    /**
     * The response envelope, or an empty array when the body was not a JSON object.
     *
     * @return array<string, mixed>
     */
    public function envelope(): array
    {
        return is_array($this->body) ? $this->body : [];
    }
}
