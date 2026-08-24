<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Model;

/** The result of {@see \Pactman\NonprofitCheckPlus\NonprofitsResource::check()}. */
final class SingleCheckResult extends PactmanResult
{
    /**
     * @param list<ApiErrorDetail>             $errors
     * @param array<string, mixed>|string|null $raw
     * @param Nonprofit|null                   $nonprofit The organization, or `null` when the API returned no record.
     */
    public function __construct(
        ?int $checkCount,
        ?float $timeTakenMs,
        array $errors,
        ?string $requestId,
        int $status,
        array|string|null $raw,
        public readonly ?Nonprofit $nonprofit,
    ) {
        parent::__construct($checkCount, $timeTakenMs, $errors, $requestId, $status, $raw);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [...parent::toArray(), 'nonprofit' => $this->nonprofit?->toArray()];
    }
}
