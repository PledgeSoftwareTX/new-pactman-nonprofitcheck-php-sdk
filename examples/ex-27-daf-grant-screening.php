<?php

/**
 * EX-27 — Donor-advised fund grant screening.
 *
 * A DAF sponsor screening a recommended grantee: deductibility, foundation
 * classification and expenditure responsibility, each routed differently.
 *
 * Run:  php examples/ex-27-daf-grant-screening.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Sources;

$context = ExampleContext::fixtures();

$recommendations = [
    ['ein' => Fixtures::EINS['publicCharity'], 'amount' => 25_000],
    ['ein' => Fixtures::EINS['privateFoundation'], 'amount' => 50_000],
    ['ein' => Fixtures::EINS['revoked'], 'amount' => 10_000],
];

$result = $context->client->nonprofits->checkBulk(array_column($recommendations, 'ein'));
$byEin = $result->byEin();

foreach ($recommendations as $recommendation) {
    $organization = $byEin[$recommendation['ein']] ?? null;

    Output::heading(sprintf(
        '$%s to %s',
        number_format($recommendation['amount']),
        Output::text($organization?->get('organization_name')) ?: $recommendation['ein'],
    ));

    if ($organization === null) {
        Output::field('decision', 'hold — no record returned for this EIN');

        continue;
    }

    $bmf = Sources::bmf($organization);
    $pub78 = Sources::pub78($organization);
    $aroe = Sources::aroe($organization);

    // A private foundation grantee is not disqualified. It changes the path:
    // expenditure responsibility applies, and the deductibility limit differs.
    $isPrivateFoundation = $bmf?->get('foundation_type_code') === 'pf';

    $limitations = [];

    foreach ($organization->organizationTypes() as $entry) {
        if (isset($entry['deductibility_limitation']) && is_string($entry['deductibility_limitation'])) {
            $limitations[] = $entry['deductibility_limitation'];
        }
    }

    Output::field('pub78.verified', $pub78?->get('verified'));
    Output::field('deductibility limits', $limitations === [] ? 'none returned' : implode(', ', $limitations));
    Output::field('foundation type', $bmf?->get('foundation_type_description'));
    Output::field('509(a) status', $bmf?->get('foundation_509a_status'));
    Output::field('revoked', ($aroe?->get('revocation_date') ?? null) !== null);

    $decision = match (true) {
        ($aroe?->get('revocation_date') ?? null) !== null => 'decline — exemption revoked',
        $pub78?->get('verified') !== true => 'hold — not verified in Publication 78',
        $isPrivateFoundation => 'approve with expenditure responsibility',
        default => 'approve — standard grant path',
    };

    Output::field('decision', $decision);
    Output::field(
        'required file',
        $isPrivateFoundation ? 'ER agreement, reports, and pre-grant inquiry' : 'standard grant letter',
    );
}

Output::note(
    "One bulk request screened the whole recommendation batch. The classification\n"
    . 'fields decide which grant path applies — not whether the grant is allowed.',
);

$context->close();
