<?php

/**
 * EX-19 — Partial success and item-level errors.
 *
 * Mixed outcomes on one HTTP 200: usable records, item-level errors, and a full
 * input reconciliation.
 *
 * Run:  php examples/ex-19-bulk-partial-success.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;

$context = ExampleContext::fixtures();

$submitted = [
    Fixtures::EINS['publicCharity'],
    Fixtures::EINS['noRecord'],
    Fixtures::EINS['publicCharitySecond'],
    '123456789',
];

$result = $context->client->nonprofits->checkBulk($submitted);

// Some matched and some did not, which is a success.
Output::heading('One response, mixed outcomes');
Output::field('status', $result->status);
Output::field('organizations', count($result->organizations));
Output::field('errors', count($result->errors));
Output::field('notFoundEins', implode(', ', $result->notFoundEins));

Output::heading('Item-level errors, in full');

foreach ($result->errors as $detail) {
    echo json_encode($detail->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}

// Reconcile every input against an outcome. This is the loop that keeps a
// portfolio import honest: nothing about a sibling failure degrades a record
// that matched, and nothing may be quietly dropped.
Output::heading('Reconciliation — every input accounted for');

$matched = array_map(
    static fn ($organization): string => Output::text($organization->get('ein')),
    $result->organizations,
);
$missing = $result->notFoundEins;
$unaccounted = 0;

foreach ($submitted as $ein) {
    $outcome = match (true) {
        in_array($ein, $matched, true) => 'matched',
        in_array($ein, $missing, true) => 'no record — reported in errors',
        default => 'UNACCOUNTED FOR — do not treat as checked',
    };

    if (str_starts_with($outcome, 'UNACCOUNTED')) {
        ++$unaccounted;
    }

    Output::field($ein, $outcome);
}

Output::field('unaccounted for', $unaccounted);

Output::note(
    "An EIN the API has no record for is a gap in the data, not a negative finding\n"
    . 'about the organization. Route it to review; do not record it as "screened".',
);

$context->close();
