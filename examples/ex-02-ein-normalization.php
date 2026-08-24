<?php

/**
 * EX-02 — EIN normalization.
 *
 * A hyphenated, whitespace-padded EIN normalized to nine digits before the
 * request, with the original kept for diagnostics.
 *
 * Run:  PACTMAN_API_KEY=... php examples/ex-02-ein-normalization.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Ein;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;

$context = ExampleContext::live();

// What an onboarding form actually sends: a copy-paste with padding and the
// conventional hyphen.
$submitted = '  41-1787097  ';

Output::heading('Normalizing what the form sent');
Output::field('as submitted', json_encode($submitted));
Output::field('Ein::isValid()', Ein::isValid($submitted));
Output::field('Ein::normalize()', Ein::normalize($submitted));

Output::heading('Shapes the SDK accepts and rejects');

foreach (['41-1787097', '411787097', " 411787097\n", '04-2103594'] as $accepted) {
    Output::field(json_encode($accepted) . ' → accepted', Ein::normalize($accepted));
}

foreach (['4117870', '41178709777', '41-178709A', '41.1787097', ''] as $rejected) {
    Output::field(json_encode($rejected) . ' → rejected', Ein::isValid($rejected) ? 'accepted?!' : 'not EIN-shaped');
}

// Store the normalized form as your key — it is what the API echoes back — and
// keep the raw input beside it so support can see what the applicant typed.
$applicant = [
    'ein_as_submitted' => $submitted,
    'ein' => Ein::normalize($submitted),
];

// check() normalizes internally too, so either form is the same request.
$result = $context->client->nonprofits->check($applicant['ein_as_submitted']);

Output::heading('The record the API returned');
Output::field('ein (echoed by the API)', $result->nonprofit?->ein);
Output::field('organization_name', $result->nonprofit?->organization_name);

Output::note(
    "Formatting validation confirms only that a value is shaped like an EIN. It says\n"
    . 'nothing about tax-exempt status, identity, eligibility, or good standing.',
);

$context->close();
