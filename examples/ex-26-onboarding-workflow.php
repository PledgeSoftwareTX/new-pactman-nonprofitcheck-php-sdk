<?php

/**
 * EX-26 — Nonprofit onboarding workflow.
 *
 * An applicant arrives with an EIN and a name. This runs the check, gathers
 * every source finding, applies one explicit policy, and stores the evidence.
 *
 * Run:  php examples/ex-26-onboarding-workflow.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ApiDate;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Exception\NotFoundException;
use Pactman\NonprofitCheckPlus\Exception\PactmanException;
use Pactman\NonprofitCheckPlus\Exception\ValidationException;
use Pactman\NonprofitCheckPlus\Sources;

$context = ExampleContext::fixtures();

$applicants = [
    ['ein' => Fixtures::EINS['publicCharity'], 'name' => 'Meals Today Example Nonprofit, Inc.'],
    ['ein' => Fixtures::EINS['revoked'], 'name' => 'Lapsed Filings Example Society'],
    ['ein' => Fixtures::EINS['ofacMatch'], 'name' => 'Overseas Relief Example Fund'],
    ['ein' => Fixtures::EINS['noRecord'], 'name' => 'Nowhere Foundation'],
    ['ein' => 'not-an-ein', 'name' => 'Typo Trust'],
];

foreach ($applicants as $applicant) {
    Output::heading($applicant['name']);

    // 1. Local validation first. A malformed EIN is a form error, not an outage.
    try {
        $result = $context->client->nonprofits->check($applicant['ein']);
    } catch (ValidationException $error) {
        Output::field('outcome', 'reject — invalid EIN, nothing was sent');
        Output::field('reason', $error->issues[0]->message ?? $error->getMessage());

        continue;
    } catch (NotFoundException) {
        // 2. No record is a gap in the data, not a negative finding.
        Output::field('outcome', 'manual_review — no IRS record for this EIN');

        continue;
    } catch (PactmanException $error) {
        // 3. An outage is "not checked". It is never a pass.
        Output::field('outcome', 'retry_later — the check did not complete');
        Output::field('category', $error->category->value);

        continue;
    }

    $nonprofit = $result->nonprofit;

    if ($nonprofit === null) {
        Output::field('outcome', 'manual_review — the API returned no record');

        continue;
    }

    // 4. Gather findings from every source. Nothing here is collapsed into one
    //    boolean; each source answers its own question.
    $bmf = Sources::bmf($nonprofit);
    $pub78 = Sources::pub78($nonprofit);
    $aroe = Sources::aroe($nonprofit);
    $ofac = Sources::ofac($nonprofit);

    $revoked = ($aroe?->get('revocation_date') ?? null) !== null
        || ($aroe?->get('revocation_code') ?? null) !== null;
    $ofacUnresolved = $ofac === null
        || $ofac->get('status') === null
        || str_contains(Output::text($ofac->get('status')), 'UID:');

    Output::field('bmf.status', $bmf?->get('status'));
    Output::field('pub78.verified', $pub78?->get('verified'));
    Output::field('revoked', $revoked);
    Output::field('ofac unresolved', $ofacUnresolved);
    Output::field('sources disagree', $nonprofit->get('irs_bmf_pub78_conflict'));

    // 5. One policy, in one place, expressed against source fields. Change this
    //    function, not the SDK — none of it is a verdict the API handed down.
    $outcome = match (true) {
        $ofacUnresolved => 'block — escalate to compliance',
        $revoked && ($aroe?->get('reinstatement_date') ?? null) === null => 'block — exemption revoked',
        $revoked => 'manual_review — revoked and reinstated',
        $nonprofit->get('irs_bmf_pub78_conflict') === true => 'manual_review — sources disagree',
        $bmf?->get('status') === true && $pub78?->get('verified') === true => 'approve',
        default => 'manual_review — findings incomplete',
    };

    Output::field('outcome', $outcome);

    // 6. Store the evidence, not just the verdict.
    $evidence = [
        'ein' => $nonprofit->get('ein'),
        'applicant_name' => $applicant['name'],
        'checked_at' => ApiDate::checkedAt(),
        'request_id' => $result->requestId,
        'outcome' => $outcome,
        'sources' => [
            'bmf' => $bmf?->toArray(),
            'pub78' => $pub78?->toArray(),
            'aroe' => $aroe?->toArray(),
            'ofac' => $ofac?->toArray(),
        ],
    ];

    Output::field('evidence stored', strlen((string) json_encode($evidence)) . ' bytes');
}

Output::note(
    "Five applicants, five outcomes, and only one of them is \"approve\". A workflow\n"
    . 'that cannot say "I do not know" will say "yes" instead.',
);

$context->close();
