<?php

/**
 * EX-21 — Usage tracking.
 *
 * `nonprofit_check_count` as a cumulative billing-cycle total that resets each
 * cycle — never a per-request size.
 *
 * Run:  php examples/ex-21-usage-tracking.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;

$context = ExampleContext::fixtures();

Output::heading('checkCount is a running total, not a request size');

$first = $context->client->nonprofits->check(Fixtures::EINS['publicCharity']);
Output::field('after 1 single check', $first->checkCount);

$second = $context->client->nonprofits->check(Fixtures::EINS['publicCharitySecond']);
Output::field('after 2 single checks', $second->checkCount);

$bulk = $context->client->nonprofits->checkBulk([
    Fixtures::EINS['publicCharity'],
    Fixtures::EINS['publicCharitySecond'],
    Fixtures::EINS['privateFoundation'],
]);

Output::field('after a 3-EIN bulk call', $bulk->checkCount);
// A bulk call for three EINs does not return 3.
Output::field('the bulk call did NOT return', 3);

Output::heading('What one operation actually consumed');
Output::field('delta across the bulk call', ($bulk->checkCount ?? 0) - ($second->checkCount ?? 0));

// EINs with no matching record are not billed, so a delta can be smaller than
// the batch you sent. Read the number the API reports rather than reconstructing
// usage from your input.
Output::heading('Unmatched EINs are not billed');

$before = $context->client->nonprofits->check(Fixtures::EINS['publicCharity']);
$mixed = $context->client->nonprofits->checkBulk([
    Fixtures::EINS['publicCharity'],
    Fixtures::EINS['noRecord'],
    '123456789',
]);

Output::field('EINs submitted', 3);
Output::field('records returned', count($mixed->organizations));
Output::field('delta', ($mixed->checkCount ?? 0) - ($before->checkCount ?? 0));

Output::heading('Reading it as a usage gauge');

$quota = 5000;
$used = $mixed->checkCount ?? 0;

Output::field('cycle total so far', $used);
Output::field('illustrative quota', $quota);
Output::field('remaining this cycle', $quota - $used);
Output::field('percent consumed', round($used / $quota * 100, 2) . '%');

Output::note(
    "The total resets when a new billing cycle begins, so a value smaller than the one\n"
    . 'you saw yesterday means a new cycle started — not that usage went backwards.',
);

$context->close();
