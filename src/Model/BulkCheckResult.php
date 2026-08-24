<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Model;

/** The result of {@see \Pactman\NonprofitCheckPlus\NonprofitsResource::checkBulk()}. */
final class BulkCheckResult extends PactmanResult
{
    /**
     * @param list<ApiErrorDetail>             $errors
     * @param array<string, mixed>|string|null $raw
     * @param list<Nonprofit>                  $organizations Organizations the API matched, in the
     *     order it returned them — which is not guaranteed to follow the order you supplied.
     *     Index by `ein` with {@see byEin()}.
     * @param list<string>                     $notFoundEins EINs the API reported no record for,
     *     collected from `errors`. A bulk request where some EINs miss is a successful HTTP 200,
     *     not an error.
     */
    public function __construct(
        ?int $checkCount,
        ?float $timeTakenMs,
        array $errors,
        ?string $requestId,
        int $status,
        array|string|null $raw,
        public readonly array $organizations,
        public readonly array $notFoundEins,
    ) {
        parent::__construct($checkCount, $timeTakenMs, $errors, $requestId, $status, $raw);
    }

    /**
     * The matched organizations keyed by the EIN the API echoed back.
     *
     * This is the pairing that always holds. The response is a set of matched
     * records, not a row-for-row answer to your input list, so never pair
     * `organizations` positionally with the EINs you sent.
     *
     * Records the API returned without an `ein` are skipped, since there is no
     * key to file them under; read `organizations` directly if you need them.
     *
     * **On key types.** PHP canonicalizes an array key that looks like an
     * integer, so `'411787097'` is stored as `int` while `'042103594'` stays a
     * `string`. Lookup applies the same rule, so `$byEin[$yourEin]` always finds
     * the right record whichever form it took — but when you *iterate*, read the
     * EIN from `$organization->ein`, which is always the string the API sent,
     * rather than from the key.
     *
     * @return array<array-key, Nonprofit>
     */
    public function byEin(): array
    {
        $indexed = [];

        foreach ($this->organizations as $organization) {
            $ein = $organization->get('ein');

            if (is_string($ein) && $ein !== '') {
                $indexed[$ein] = $organization;
            }
        }

        return $indexed;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'organizations' => array_map(
                static fn (Nonprofit $organization): array => $organization->toArray(),
                $this->organizations,
            ),
            'notFoundEins' => $this->notFoundEins,
        ];
    }
}
