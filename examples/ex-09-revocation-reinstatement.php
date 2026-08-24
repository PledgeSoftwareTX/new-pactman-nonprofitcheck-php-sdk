<?php

/**
 * EX-09 — Revocation with reinstatement.
 *
 * Revocation and reinstatement dates kept separate, and the questions
 * reinstatement does not answer.
 *
 * Run:  php examples/ex-09-revocation-reinstatement.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ApiDate;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Sources;

$context = ExampleContext::fixtures();

$nonprofit = $context->client->nonprofits->check(Fixtures::EINS['reinstated'])->nonprofit;
$aroe = $nonprofit === null ? null : Sources::aroe($nonprofit);

if ($aroe === null) {
    Output::error('The fixture API returned no revocation data.');
    $context->close();

    exit(1);
}

Output::heading(Output::text($nonprofit?->get('organization_name')));
Output::displayField($aroe, 'revocation_code');
Output::displayField($aroe, 'revocation_date');
Output::displayField($aroe, 'reinstatement_date');
Output::displayField($aroe, 'list_published_date');

$revokedAt = ApiDate::parse($aroe->get('revocation_date'));
$reinstatedAt = ApiDate::parse($aroe->get('reinstatement_date'));

// Nothing collapses the two into a "currently revoked" boolean — that boolean
// would lose the interval, and donations dated inside it may need handling.
Output::heading('The interval, which a boolean would destroy');

if ($revokedAt !== null && $reinstatedAt !== null) {
    Output::field('revoked on', $revokedAt->format('Y-m-d'));
    Output::field('reinstated on', $reinstatedAt->format('Y-m-d'));
    Output::field('days without exemption', $revokedAt->diff($reinstatedAt)->days);
} else {
    Output::bullet('One of the dates is missing, so no interval can be stated.');
}

Output::heading('What reinstatement does not tell you');
Output::bullet('Was the reinstatement retroactive to the revocation date?');
Output::bullet('Do gifts made during the lapse need re-characterizing?');
Output::bullet('Does your grant agreement require continuous exemption?');

Output::field('routed to', 'manual_review');

Output::note('Reinstatement resolves one question, not every question.');

$context->close();
