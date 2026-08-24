<?php

/**
 * EX-20 — Bulk batch limits.
 *
 * Empty and over-limit batches rejected against `Endpoints::MAX_BULK_EINS`, plus
 * chunking a larger list yourself.
 *
 * Run:  php examples/ex-20-bulk-batch-limits.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Endpoints;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Exception\ValidationException;

$context = ExampleContext::fixtures();

Output::heading('The limit is exported, not hard-coded in your app');
Output::field('Endpoints::MAX_BULK_EINS', Endpoints::MAX_BULK_EINS);

Output::heading('Rejected locally, before anything is sent');

try {
    $context->client->nonprofits->checkBulk([]);
} catch (ValidationException $error) {
    Output::field('empty batch', $error->getMessage());
}

// 51 well-formed EINs.
$overLimit = [];

for ($i = 0; $i <= Endpoints::MAX_BULK_EINS; ++$i) {
    $overLimit[] = str_pad((string) (400000000 + $i), 9, '0', STR_PAD_LEFT);
}

try {
    $context->client->nonprofits->checkBulk($overLimit);
} catch (ValidationException $error) {
    Output::field('51 EINs', $error->getMessage());
}

// Chunking is yours to do, deliberately: the SDK will not silently turn one call
// into several billable requests behind your back.
Output::heading('Chunking a larger list yourself');

$portfolio = array_merge(
    array_fill(0, 0, ''),
    [
        Fixtures::EINS['publicCharity'],
        Fixtures::EINS['publicCharitySecond'],
        Fixtures::EINS['privateFoundation'],
        Fixtures::EINS['reinstated'],
        Fixtures::EINS['staleData'],
    ],
);

// A chunk size below the limit leaves headroom if the limit ever tightens.
$chunkSize = 2;
$organizations = [];
$notFound = [];

foreach (array_chunk($portfolio, $chunkSize) as $index => $chunk) {
    $result = $context->client->nonprofits->checkBulk($chunk);

    $organizations = [...$organizations, ...$result->organizations];
    $notFound = [...$notFound, ...$result->notFoundEins];

    Output::field(
        sprintf('chunk %d (%d EINs)', $index + 1, count($chunk)),
        sprintf('%d matched, checkCount now %s', count($result->organizations), Output::format($result->checkCount)),
    );
}

Output::field('total matched', count($organizations));
Output::field('total not found', count($notFound));

Output::note(
    "Each chunk is a separate billable request. Sizing them is a decision about cost\n"
    . 'and latency, which is why the SDK leaves it to you.',
);

$context->close();
