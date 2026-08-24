<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Exception;

use Pactman\NonprofitCheckPlus\Model\ApiErrorDetail;

/**
 * Fields shared by every exception derived from an HTTP response.
 *
 * Collected once by the transport and handed to {@see ApiException::fromStatus()}.
 */
final class ApiErrorInit
{
    /**
     * @param int                   $status             HTTP status code.
     * @param string|null           $apiMessage         `message` from the Pactman response envelope, when present.
     * @param int|null              $apiCode            `code` from the Pactman response envelope, when present.
     * @param list<ApiErrorDetail>  $apiErrors          `errors` from the envelope, normalized to a list.
     * @param string|null           $requestId          Correlation identifier from the response headers, when present.
     * @param float|null            $retryAfterSeconds  `Retry-After` in seconds, when the server supplied a valid value.
     * @param array<string, mixed>|string|null $raw     The parsed response body, or the raw text when it was not JSON.
     * @param int                   $attempts           How many attempts were made before this error was surfaced.
     */
    public function __construct(
        public readonly int $status,
        public readonly ?string $apiMessage = null,
        public readonly ?int $apiCode = null,
        public readonly array $apiErrors = [],
        public readonly ?string $requestId = null,
        public readonly ?float $retryAfterSeconds = null,
        public readonly array|string|null $raw = null,
        public readonly int $attempts = 1,
    ) {
    }
}
