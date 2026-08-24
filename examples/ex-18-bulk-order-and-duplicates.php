<?php

/**
 * EX-18 — Input order and duplicate EINs.
 *
 * Response order does not follow request order, duplicates collapse in the
 * response but still bill, and usage is read rather than inferred.
 *
 * Run:  php examples/ex-18-bulk-order-and-duplicates.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;

$context = ExampleContext::fixtures();

// Deliberately unsorted, with one EIN repeated. The SDK sends them exactly as
// supplied: it does not reorder and it does not deduplicate.
$requested = [
    Fixtures::EINS['publicCharitySecond'],
    Fixtures::EINS['publicCharity'],
    Fixtures::EINS['publicCharitySecond'],
    Fixtures::EINS['privateFoundation'],
];

$before = $context->client->nonprofits->check(Fixtures::EINS['publicCharity']);
$result = $context->client->nonprofits->checkBulk($requested);

Output::heading('What was sent, and what came back');
Output::field('requested', implode(', ', $requested));
Output::field('requested count', count($requested));
Output::field('organizations returned', count($result->organizations));

Output::field(
    'returned order',
    implode(', ', array_map(
        static fn ($organization): string => Output::text($organization->get('ein')),
        $result->organizations,
    )),
);

// Positional pairing is invalid. This is the pairing that always holds.
Output::heading('Index by EIN, never by position');

foreach ($requested as $position => $ein) {
    $organization = $result->byEin()[$ein] ?? null;

    Output::field(
        "input[{$position}] {$ein}",
        $organization === null ? 'no record' : Output::text($organization->get('organization_name')),
    );
}

// Usage is reported, not inferred. Every submitted EIN is billable, duplicates
// included, so a count derived from unique inputs will disagree with the invoice.
Output::heading('What it cost');
Output::field('checkCount before', $before->checkCount);
Output::field('checkCount after', $result->checkCount);
Output::field('delta', ($result->checkCount ?? 0) - ($before->checkCount ?? 0));
Output::field('unique EINs sent', count(array_unique($requested)));

// Opt in when duplicates are an artifact of your data rather than intent.
Output::heading('With dedupe: true');
$deduped = $context->client->nonprofits->checkBulk($requested, dedupe: true);
Output::field('organizations returned', count($deduped->organizations));

Output::note(
    "A repeated EIN comes back once and bills twice. Deduplicate deliberately, or\n"
    . 'accept the charge — but do not infer usage from your input list.',
);

$context->close();
