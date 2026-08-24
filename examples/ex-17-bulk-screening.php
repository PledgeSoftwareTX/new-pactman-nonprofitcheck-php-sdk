<?php

/**
 * EX-17 — Bulk screening of a list.
 *
 * Screening a grantee list, iterating organization-level results and reading the
 * response envelope.
 *
 * Run:  php examples/ex-17-bulk-screening.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Sources;

$context = ExampleContext::fixtures();

$portfolio = [
    ['ein' => Fixtures::EINS['publicCharity'], 'name' => 'Meals Today'],
    ['ein' => Fixtures::EINS['publicCharitySecond'], 'name' => 'Aborjaily'],
    ['ein' => Fixtures::EINS['revoked'], 'name' => 'Lapsed Filings'],
    ['ein' => Fixtures::EINS['ofacMatch'], 'name' => 'Overseas Relief'],
    ['ein' => Fixtures::EINS['noRecord'], 'name' => 'Unknown Org'],
];

// One bulk request is one round trip and one rate-limit slot. Prefer it to a
// loop of single checks.
$result = $context->client->nonprofits->checkBulk(array_column($portfolio, 'ein'));

Output::heading('The response envelope');
Output::field('status', $result->status);
Output::field('raw[code]', is_array($result->raw) ? ($result->raw['code'] ?? null) : null);
Output::field('timeTakenMs', $result->timeTakenMs);
Output::field('checkCount', $result->checkCount);
Output::field('organizations', count($result->organizations));
Output::field('errors', count($result->errors));
Output::field('notFoundEins', implode(', ', $result->notFoundEins));

// Index by EIN. The response is a set of matched records, not a row-for-row
// answer to your input list — see EX-18.
$byEin = $result->byEin();

Output::heading('Per-organization findings');

foreach ($portfolio as $entry) {
    $organization = $byEin[$entry['ein']] ?? null;

    if ($organization === null) {
        // No record returned. That is not a pass.
        Output::field($entry['name'], 'no record returned — route to review');

        continue;
    }

    $bmf = Sources::bmf($organization);
    $pub78 = Sources::pub78($organization);
    $aroe = Sources::aroe($organization);
    $ofac = Sources::ofac($organization);

    Output::field($entry['name'], sprintf(
        'bmf=%s pub78=%s revoked=%s ofac=%s',
        Output::format($bmf?->get('status')),
        Output::format($pub78?->get('verified')),
        Output::format(($aroe?->get('revocation_date') ?? null) !== null),
        $ofac === null ? 'not returned' : (str_contains(Output::text($ofac->get('status')), 'UID:') ? 'POSSIBLE MATCH' : 'no match'),
    ));
}

Output::heading('Item-level errors');

foreach ($result->errors as $detail) {
    Output::field((string) $detail->resource, sprintf(
        '%s (code %s) for %s',
        (string) $detail->reason,
        Output::format($detail->code),
        implode(', ', $detail->eins),
    ));
}

Output::note('One request, one round trip, and every outcome accounted for.');

$context->close();
