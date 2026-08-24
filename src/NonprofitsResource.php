<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus;

use Pactman\NonprofitCheckPlus\Config\RetryOptions;
use Pactman\NonprofitCheckPlus\Exception\ValidationException;
use Pactman\NonprofitCheckPlus\Http\Transport;
use Pactman\NonprofitCheckPlus\Http\TransportResponse;
use Pactman\NonprofitCheckPlus\Model\ApiErrorDetail;
use Pactman\NonprofitCheckPlus\Model\BulkCheckResult;
use Pactman\NonprofitCheckPlus\Model\Nonprofit;
use Pactman\NonprofitCheckPlus\Model\SingleCheckResult;

/** Nonprofit lookups. Reached through {@see PactmanClient::$nonprofits}. */
final class NonprofitsResource
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Checks a single nonprofit by EIN.
     *
     * The EIN is normalized and validated locally first; a malformed EIN throws
     * {@see ValidationException} without sending a request.
     *
     * ```php
     * $result = $client->nonprofits->check('41-1787097');
     * echo $result->nonprofit?->organization_name, $result->checkCount;
     * ```
     *
     * @param float|null $timeout Overrides the client's timeout for this request, in seconds.
     * @param RetryOptions|array<string, mixed>|bool|null $retry Overrides the client's retry policy.
     *     An array merges onto the policy in force; a {@see RetryOptions} replaces it; `false`
     *     disables retrying for this request.
     * @param array<string, string> $headers Extra headers. Cannot override `Authorization`.
     *
     * @throws ValidationException                                     if the EIN is malformed. Nothing is sent.
     * @throws \Pactman\NonprofitCheckPlus\Exception\PactmanException  for any other failure.
     */
    public function check(
        string $ein,
        ?float $timeout = null,
        RetryOptions|array|bool|null $retry = null,
        array $headers = [],
    ): SingleCheckResult {
        $path = str_replace('{ein}', rawurlencode(Ein::normalize($ein)), Endpoints::SINGLE_CHECK_PATH);

        $response = $this->transport->send(
            method: 'GET',
            path: $path,
            timeout: $timeout,
            retry: $retry,
            headers: $headers,
        );

        $envelope = $response->envelope();
        $base = $this->baseFields($response);

        return new SingleCheckResult(
            checkCount: $base['checkCount'],
            timeTakenMs: $base['timeTakenMs'],
            errors: $base['errors'],
            requestId: $base['requestId'],
            status: $base['status'],
            raw: $base['raw'],
            nonprofit: $this->extractNonprofit($envelope['data'] ?? null),
        );
    }

    /**
     * Checks up to {@see Endpoints::MAX_BULK_EINS} nonprofits in one request.
     *
     * Every EIN is normalized and validated before anything is sent; if any one
     * fails, the whole call throws {@see ValidationException} identifying the
     * offending index, and no request is made. EINs are sent in the order
     * supplied and duplicates are kept unless `dedupe` is set — but the API
     * matches by set membership, so the response is not ordered to match and a
     * repeated EIN comes back once. Index `organizations` by `ein` with
     * {@see BulkCheckResult::byEin()} rather than pairing positionally.
     *
     * EINs the API has no record for are not an error: they arrive as HTTP 200
     * with the missing values in `notFoundEins`.
     *
     * ```php
     * $result = $client->nonprofits->checkBulk(['41-1787097', '996589560']);
     * foreach ($result->organizations as $org) { echo $org->ein; }
     * print_r($result->notFoundEins);
     * ```
     *
     * @param list<string> $eins
     * @param bool  $dedupe Remove duplicate EINs before sending, keeping first-seen order. Off by
     *     default: duplicates are sent exactly as supplied, because each one consumes quota and
     *     silently dropping them would misreport what was checked.
     * @param float|null $timeout Overrides the client's timeout for this request, in seconds.
     * @param RetryOptions|array<string, mixed>|bool|null $retry Overrides the client's retry policy.
     * @param array<string, string> $headers Extra headers. Cannot override `Authorization`.
     *
     * @throws ValidationException                                     if the batch is empty, over the
     *     limit, or contains a malformed EIN. Nothing is sent.
     * @throws \Pactman\NonprofitCheckPlus\Exception\PactmanException  for any other failure.
     */
    public function checkBulk(
        array $eins,
        bool $dedupe = false,
        ?float $timeout = null,
        RetryOptions|array|bool|null $retry = null,
        array $headers = [],
    ): BulkCheckResult {
        $response = $this->transport->send(
            method: 'POST',
            path: Endpoints::BULK_CHECK_PATH,
            body: $this->bulkPayload($eins, $dedupe),
            timeout: $timeout,
            retry: $retry,
            headers: $headers,
        );

        $envelope = $response->envelope();
        $base = $this->baseFields($response);

        return new BulkCheckResult(
            checkCount: $base['checkCount'],
            timeTakenMs: $base['timeTakenMs'],
            errors: $base['errors'],
            requestId: $base['requestId'],
            status: $base['status'],
            raw: $base['raw'],
            organizations: $this->extractOrganizations($envelope['data'] ?? null),
            notFoundEins: $this->extractNotFoundEins($base['errors']),
        );
    }

    /**
     * @param list<string> $eins
     *
     * @return list<string>
     */
    private function bulkPayload(array $eins, bool $dedupe): array
    {
        if ($eins === []) {
            throw new ValidationException('checkBulk requires at least one EIN.');
        }

        $normalized = Ein::normalizeMany($eins);
        $payload = $dedupe ? array_values(array_unique($normalized)) : $normalized;

        if (count($payload) > Endpoints::MAX_BULK_EINS) {
            throw new ValidationException(sprintf(
                'checkBulk accepts at most %d EINs per request, received %d. Split the input into '
                . 'batches; this SDK does not chunk automatically.',
                Endpoints::MAX_BULK_EINS,
                count($payload),
            ));
        }

        return $payload;
    }

    /**
     * @return array{
     *     checkCount: int|null,
     *     timeTakenMs: float|null,
     *     errors: list<ApiErrorDetail>,
     *     requestId: string|null,
     *     status: int,
     *     raw: array<string, mixed>|string|null
     * }
     */
    private function baseFields(TransportResponse $response): array
    {
        $envelope = $response->envelope();
        $checkCount = $envelope['nonprofit_check_count'] ?? null;
        $timeTaken = $envelope['timeTaken'] ?? null;

        return [
            'checkCount' => is_int($checkCount) || is_float($checkCount) ? (int) $checkCount : null,
            'timeTakenMs' => is_int($timeTaken) || is_float($timeTaken) ? (float) $timeTaken : null,
            'errors' => Transport::normalizeApiErrors($envelope['errors'] ?? null),
            'requestId' => $response->requestId,
            'status' => $response->status,
            'raw' => $response->body,
        ];
    }

    private function extractNonprofit(mixed $data): ?Nonprofit
    {
        if (!is_array($data) || $data === []) {
            return null;
        }

        if (array_is_list($data)) {
            $first = $data[0] ?? null;

            if (!is_array($first)) {
                return null;
            }

            /** @var array<string, mixed> $first */
            return new Nonprofit($first);
        }

        /** @var array<string, mixed> $data */
        return new Nonprofit($data);
    }

    /**
     * The published schema returns `data` as an array. Some deployments wrap it
     * as `{"organizations": [...]}`, so both are accepted rather than silently
     * yielding an empty list.
     *
     * @return list<Nonprofit>
     */
    private function extractOrganizations(mixed $data): array
    {
        if (is_array($data) && !array_is_list($data) && is_array($data['organizations'] ?? null)) {
            $data = $data['organizations'];
        }

        if (!is_array($data) || !array_is_list($data)) {
            return [];
        }

        $organizations = [];

        foreach ($data as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $organizations[] = new Nonprofit($entry);
            }
        }

        return $organizations;
    }

    /**
     * @param list<ApiErrorDetail> $errors
     *
     * @return list<string>
     */
    private function extractNotFoundEins(array $errors): array
    {
        $found = [];

        foreach ($errors as $detail) {
            foreach ($detail->eins as $ein) {
                $found[] = $ein;
            }
        }

        return $found;
    }
}
