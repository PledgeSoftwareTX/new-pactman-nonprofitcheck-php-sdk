<?php

/**
 * EX-11 — Cross-source conflict.
 *
 * `irs_bmf_pub78_conflict` handled by recording both sources, not by picking one.
 *
 * Run:  php examples/ex-11-source-conflict.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Sources;

$context = ExampleContext::fixtures();

$result = $context->client->nonprofits->check(Fixtures::EINS['conflicted']);
$nonprofit = $result->nonprofit;

if ($nonprofit === null) {
    Output::error('The fixture API returned no record.');
    $context->close();

    exit(1);
}

$bmf = Sources::bmf($nonprofit);
$pub78 = Sources::pub78($nonprofit);
$findings = [];

// The flag the API sets is authoritative; the comparisons only explain it.
if ($nonprofit->get('irs_bmf_pub78_conflict') === true) {
    $findings[] = 'The API flagged a BMF / Publication 78 disagreement.';
}

if ($bmf?->get('status') === true && $pub78?->get('verified') === false) {
    $findings[] = 'The BMF lists the organization as exempt; Publication 78 does not list it.';
}

if ($bmf?->get('status') === false && $pub78?->get('verified') === true) {
    $findings[] = 'Publication 78 lists the organization; the BMF does not show it as exempt.';
}

Output::heading(Output::text($nonprofit->get('organization_name')));
Output::displayField($nonprofit, 'irs_bmf_pub78_conflict');

Output::heading('Both sides, side by side');
Output::field('bmf.status', $bmf?->get('status'));
Output::field('bmf.organization_name', $bmf?->get('organization_name'));
Output::field('pub78.verified', $pub78?->get('verified'));
Output::field('pub78.organization_name', $pub78?->get('organization_name'));

Output::heading('Findings');

foreach ($findings as $finding) {
    Output::bullet($finding);
}

if ($findings === []) {
    Output::bullet('The sources agree.');
}

// Both sides are kept, side by side, for the reviewer. Silently preferring one
// source means being wrong for some organization with the evidence destroyed.
$reviewRecord = $findings === [] ? null : [
    'ein' => $nonprofit->get('ein'),
    'request_id' => $result->requestId,
    'findings' => $findings,
    'sources' => ['bmf' => $bmf?->toArray(), 'pub78' => $pub78?->toArray()],
];

if ($reviewRecord !== null) {
    Output::heading('Review record');
    echo json_encode($reviewRecord, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}

Output::note('Two sources disagreeing is information. Resolving it silently destroys it.');

$context->close();
