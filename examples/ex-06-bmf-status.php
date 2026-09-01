<?php

/**
 * EX-06 — IRS Business Master File status.
 *
 * Every IRS Business Master File field on the response — status, identity,
 * subsection, exemption, ruling, foundation.
 *
 * Run:  PACTMAN_API_KEY=... php examples/ex-06-bmf-status.php 41-1787097
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Sources;

$context = ExampleContext::live();
$ein = (string) ExampleContext::argument(fallback: Fixtures::EINS['publicCharity']);

$nonprofit = $context->client->nonprofits->check($ein)->nonprofit;
$bmf = $nonprofit === null ? null : Sources::bmf($nonprofit);

if ($bmf === null) {
    // Not "not in the BMF" — the API returned no BMF fields at all. That is an
    // absence of evidence, not a negative finding. Route it to review.
    Output::heading('No Business Master File data was returned');
    Output::note('Absence of evidence is not a negative finding. This record goes to review.');
    $context->close();

    exit(0);
}

// One source's answer to one question. There is no is_exempt here, and there
// will not be one: exempt-for-what is a determination, not a field.
Output::heading('Status');
Output::displayField($bmf, 'status');
Output::displayField($bmf, 'exempt_status_code');
Output::displayField($bmf, 'most_recent');

Output::heading('Identity as the BMF holds it');

foreach (['organization_name', 'ein', 'church_message'] as $field) {
    Output::displayField($bmf, $field);
}

Output::heading('Classification');

foreach ([
    'subsection', 'subsection_description',
    'ruling_month', 'ruling_year', 'group_exemption',
    'foundation_code', 'foundation_code_description',
    'foundation_type_code', 'foundation_type_description', 'foundation_509a_status',
    'filing_req_code',
] as $field) {
    Output::displayField($bmf, $field);
}

Output::note(
    "Reading the BMF in isolation is how a revoked or sanctioned organization passes\n"
    . 'a check — see EX-08 and EX-10.',
);

$context->close();
