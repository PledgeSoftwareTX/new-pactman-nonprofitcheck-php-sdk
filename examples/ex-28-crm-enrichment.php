<?php

/**
 * EX-28 — CRM enrichment.
 *
 * Enriching stored constituent records from the API, writing back only what the
 * API actually returned, and leaving a field the API omitted untouched.
 *
 * Run:  php examples/ex-28-crm-enrichment.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ApiDate;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Model\Nonprofit;

$context = ExampleContext::fixtures();

// Rows as they sit in the CRM today, with the gaps a real database has.
//
// The EIN is a field on the row, not the array key. Keying by EIN would work for
// lookups but hand you `int` keys back — PHP canonicalizes a numeric-string key —
// and an EIN that reached the SDK as an int would have lost any leading zero.
// The SDK rejects a non-string EIN for exactly that reason.
$crm = [
    [
        'ein' => Fixtures::EINS['publicCharity'],
        'display_name' => 'Meals Today',
        'city' => 'WESTFIELD',
        'state' => 'MA',
        'zip' => null,
        'profile_url' => null,
    ],
    [
        'ein' => Fixtures::EINS['sparseIdentity'],
        'display_name' => 'Quiet Harbor Trust',
        'city' => 'ROCKPORT',
        'state' => 'ME',
        'zip' => '04856',
        'profile_url' => null,
    ],
];

// The CRM column each API field feeds.
const ENRICHMENT_MAP = [
    'display_name' => 'organization_name',
    'city' => 'city',
    'state' => 'state',
    'zip' => 'zip',
    'profile_url' => 'pactman_org_url',
];

$result = $context->client->nonprofits->checkBulk(array_column($crm, 'ein'));
$byEin = $result->byEin();

foreach ($crm as $row) {
    $organization = $byEin[$row['ein']] ?? null;

    Output::heading((string) $row['display_name']);

    if (!$organization instanceof Nonprofit) {
        Output::field('action', 'skip — no record returned; the stored row is left alone');

        continue;
    }

    $updates = [];
    $skipped = [];

    foreach (ENRICHMENT_MAP as $column => $field) {
        // The distinction that keeps an enrichment job honest: a field the API
        // did not return must never overwrite a value you already hold. Writing
        // null over good data because "the API said null" is the classic bug —
        // and here the API did not say anything at all.
        if (!$organization->has($field)) {
            $skipped[] = "{$column} (not returned)";

            continue;
        }

        $value = $organization->get($field);

        if ($value === null) {
            $skipped[] = "{$column} (returned as null)";

            continue;
        }

        if ($value !== $row[$column]) {
            $updates[$column] = $value;
        }
    }

    foreach ($updates as $column => $value) {
        Output::field("update {$column}", Output::format($row[$column]) . '  →  ' . Output::format($value));
    }

    if ($updates === []) {
        Output::bullet('Nothing to update.');
    }

    foreach ($skipped as $note) {
        Output::field('left untouched', $note);
    }

    // Record where the data came from and when, so a later reviewer can tell an
    // enriched value from one a human typed.
    Output::field('provenance', json_encode([
        'source' => 'pactman-nonprofit-check-plus',
        'request_id' => $result->requestId,
        'enriched_at' => ApiDate::checkedAt(),
        'fields' => array_keys($updates),
    ]));
}

Output::note(
    "\"The API returned null\" and \"the API returned no such field\" are different\n"
    . 'facts. Only one of them is evidence that your stored value is wrong.',
);

$context->close();
