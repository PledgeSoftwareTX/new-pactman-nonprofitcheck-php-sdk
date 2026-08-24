<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Model;

use JsonSerializable;

/**
 * Fields shared by every result this SDK returns.
 *
 * Results are shapes this SDK defines, so they are typed objects with real
 * properties — unlike {@see Nonprofit}, whose shape the wire dictates.
 */
abstract class PactmanResult implements JsonSerializable
{
    /**
     * @param int|null $checkCount `nonprofit_check_count` from the envelope: checks consumed so
     *     far in the current billing cycle, including this request, resetting each cycle.
     *     Not the size of this request — take the delta between two responses if you need that.
     * @param float|null $timeTakenMs Server-side processing time in milliseconds, when reported.
     * @param list<ApiErrorDetail> $errors Item-level failures reported alongside a successful
     *     response. Empty when the API reported none.
     * @param string|null $requestId Correlation identifier from the response headers, when the
     *     server sent one.
     * @param int $status HTTP status of the response.
     * @param array<string, mixed>|string|null $raw The unmodified parsed response body, including
     *     any field not typed above. Normally the parsed envelope; a server that answers 200 with
     *     a non-JSON body leaves the raw text here rather than discarding the evidence.
     */
    public function __construct(
        public readonly ?int $checkCount,
        public readonly ?float $timeTakenMs,
        public readonly array $errors,
        public readonly ?string $requestId,
        public readonly int $status,
        public readonly array|string|null $raw,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'checkCount' => $this->checkCount,
            'timeTakenMs' => $this->timeTakenMs,
            'errors' => array_map(
                static fn (ApiErrorDetail $detail): array => $detail->toArray(),
                $this->errors,
            ),
            'requestId' => $this->requestId,
            'status' => $this->status,
            'raw' => $this->raw,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
