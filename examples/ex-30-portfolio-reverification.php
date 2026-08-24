<?php

/**
 * EX-30 — Portfolio re-verification.
 *
 * A standing portfolio re-checked on a schedule: chunked bulk requests, findings
 * compared against the last run, and a report of what moved.
 *
 * Run:  php examples/ex-30-portfolio-reverification.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Endpoints;
use Pactman\NonprofitCheckPlus\Examples\ApiDate;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Exception\PactmanException;
use Pactman\NonprofitCheckPlus\Model\Nonprofit;
use Pactman\NonprofitCheckPlus\Sources;

// A courtesy throttle, because this job runs unattended against a whole portfolio.
$context = ExampleContext::fixtures();
$client = $context->sibling(maxRequestsPerSecond: 5.0, retry: ['maxRetries' => 2]);

$portfolio = [
    Fixtures::EINS['publicCharity'],
    Fixtures::EINS['publicCharitySecond'],
    Fixtures::EINS['privateFoundation'],
    Fixtures::EINS['revoked'],
    Fixtures::EINS['reinstated'],
    Fixtures::EINS['ofacMatch'],
    Fixtures::EINS['staleData'],
    Fixtures::EINS['noRecord'],
];

/** What the last run recorded, keyed by EIN. */
$lastRun = [
    Fixtures::EINS['publicCharity'] => 'clear',
    Fixtures::EINS['publicCharitySecond'] => 'clear',
    Fixtures::EINS['privateFoundation'] => 'clear',
    Fixtures::EINS['revoked'] => 'clear',
    Fixtures::EINS['reinstated'] => 'review',
    Fixtures::EINS['ofacMatch'] => 'clear',
    Fixtures::EINS['staleData'] => 'clear',
    Fixtures::EINS['noRecord'] => 'no_record',
];

/** Classifies one organization against this application's policy. */
$classify = static function (Nonprofit $organization): string {
    $ofac = Sources::ofac($organization);
    $aroe = Sources::aroe($organization);
    $bmf = Sources::bmf($organization);
    $pub78 = Sources::pub78($organization);

    return match (true) {
        $ofac === null || $ofac->get('status') === null => 'review',
        str_contains(Output::text($ofac->get('status')), 'UID:') => 'escalate',
        ($aroe?->get('revocation_date') ?? null) !== null => 'escalate',
        $organization->get('irs_bmf_pub78_conflict') === true => 'review',
        $bmf?->get('status') === true && $pub78?->get('verified') === true => 'clear',
        default => 'review',
    };
};

// Chunk below the limit. Each chunk is a separate billable request, so the size
// is a cost decision — which is why the SDK never chunks on your behalf.
$chunkSize = min(4, Endpoints::MAX_BULK_EINS);
$current = [];
$missing = [];
$failedChunks = 0;

Output::heading('Re-verifying the portfolio');

foreach (array_chunk($portfolio, $chunkSize) as $index => $chunk) {
    try {
        $result = $client->nonprofits->checkBulk($chunk);
    } catch (PactmanException $error) {
        // A chunk that did not complete leaves its EINs unknown — never "clear".
        ++$failedChunks;
        Output::field('chunk ' . ($index + 1), 'failed — ' . $error->category->value);

        continue;
    }

    foreach ($result->byEin() as $ein => $organization) {
        $current[(string) $ein] = $classify($organization);
    }

    $missing = [...$missing, ...$result->notFoundEins];

    Output::field(
        'chunk ' . ($index + 1),
        sprintf('%d matched, %d without a record', count($result->organizations), count($result->notFoundEins)),
    );
}

foreach ($missing as $ein) {
    $current[$ein] = 'no_record';
}

// Compare against the last run. What moved is the report; the rest is noise.
Output::heading('What changed since the last run');

$moved = 0;

foreach ($portfolio as $ein) {
    // An EIN with no entry in $current was never classified this run. It is
    // reported as such and never inherits its previous classification.
    $before = $lastRun[$ein];
    $after = $current[$ein] ?? 'NOT CHECKED';

    if ($before === $after) {
        continue;
    }

    ++$moved;
    Output::field($ein, "{$before}  →  {$after}");
}

if ($moved === 0) {
    Output::bullet('Nothing moved.');
}

Output::heading('Run summary');
Output::field('portfolio size', count($portfolio));
Output::field('classified', count($current));
Output::field('not checked', count($portfolio) - count($current));
Output::field('failed chunks', $failedChunks);
Output::field('escalations', count(array_filter($current, static fn (string $s): bool => $s === 'escalate')));
Output::field('reviews', count(array_filter($current, static fn (string $s): bool => $s === 'review')));
Output::field('run completed at', ApiDate::checkedAt());

Output::note(
    "An EIN this run could not classify is \"not checked\", and it must appear in the\n"
    . 'report as such. A re-verification job that silently keeps yesterday\'s answer is '
    . "not\nre-verifying anything.",
);

$context->close();
