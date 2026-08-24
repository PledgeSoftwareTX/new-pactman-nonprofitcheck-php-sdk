<?php

/**
 * EX-10 — OFAC screening result.
 *
 * Four distinct OFAC outcomes — no match, match, null, and not screened at all.
 *
 * Run:  php examples/ex-10-ofac-screening.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Model\Nonprofit;
use Pactman\NonprofitCheckPlus\Sources;

$context = ExampleContext::fixtures();

/**
 * The SDK exposes no hasOfacMatch boolean: deriving one means pattern-matching
 * English the source can reword at any time. The one textual test below
 * escalates and never clears — anything unrecognized falls through to review.
 */
$classify = static function (Nonprofit $nonprofit): string {
    $ofac = Sources::ofac($nonprofit);

    if ($ofac === null) {
        return 'unavailable'; // no OFAC field at all; nothing was screened
    }

    if (!$ofac->has('status') || $ofac->get('status') === null) {
        return 'null';
    }

    $status = Output::text($ofac->get('status'));

    return match (true) {
        stripos($status, 'UID:') !== false => 'match',
        stripos($status, 'NOT included') !== false => 'no_match',
        default => 'needs_review',
    };
};

// Four states, four destinations. None of them is "approve automatically".
const ROUTING = [
    'no_match' => 'continue — screened against the SDN list with no match',
    'match' => 'block and escalate to compliance',
    'null' => 'hold — the field was returned empty; treat as unscreened, not as cleared',
    'unavailable' => 'hold — no OFAC data was returned',
    'needs_review' => 'hold — the status text was not recognized by this application',
];

$cases = [
    'no match' => Fixtures::EINS['publicCharity'],
    'possible match' => Fixtures::EINS['ofacMatch'],
    'returned as null' => Fixtures::EINS['ofacUnavailable'],
    'not screened at all' => Fixtures::EINS['sparseIdentity'],
];

foreach ($cases as $label => $ein) {
    $nonprofit = $context->client->nonprofits->check($ein)->nonprofit;

    if ($nonprofit === null) {
        continue;
    }

    $ofac = Sources::ofac($nonprofit);
    $outcome = $classify($nonprofit);

    Output::heading(strtoupper($label));
    Output::field('ein', $nonprofit->get('ein'));
    Output::field('Sources::ofac()', $ofac === null ? 'null — the source was not returned' : 'a projection');
    Output::field(
        'ofac_status',
        $nonprofit->has('ofac_status') ? $nonprofit->get('ofac_status') : Output::NOT_RETURNED,
    );
    Output::field('classified as', $outcome);
    Output::field('routed to', ROUTING[$outcome]);
}

Output::note(
    "The API returns ofac_status as prose, not a boolean. Read it, or put it in front\n"
    . 'of a reviewer — do not turn it into a flag your application then trusts.',
);

$context->close();
