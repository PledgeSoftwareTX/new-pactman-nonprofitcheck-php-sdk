<?php

/**
 * EX-12 — Organization type and foundation classification.
 *
 * Organization types, foundation and subsection classification for a grantmaker
 * or DAF display.
 *
 * Run:  php examples/ex-12-foundation-classification.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Sources;

$context = ExampleContext::fixtures();

foreach ([Fixtures::EINS['publicCharity'], Fixtures::EINS['privateFoundation']] as $ein) {
    $nonprofit = $context->client->nonprofits->check($ein)->nonprofit;

    if ($nonprofit === null) {
        continue;
    }

    $bmf = Sources::bmf($nonprofit);
    $pub78 = Sources::pub78($nonprofit);

    Output::heading(Output::text($nonprofit->get('organization_name')));

    // What a grant officer sees. Every value is copied, none is computed — and
    // the descriptions come from the API's own *_description fields, which stay
    // correct when the source changes. A lookup table in your repository does not.
    $panel = [
        'subsection' => $bmf?->get('subsection_description'),
        'foundation_code' => $bmf?->get('foundation_code_description'),
        'foundation_type' => $bmf?->get('foundation_type_description'),
        'status_509a' => $bmf?->get('foundation_509a_status'),
        'deductibility' => $bmf?->get('deductability_text'),
    ];

    foreach ($panel as $label => $value) {
        Output::field($label, $value);
    }

    // organizationTypes() hands back only the entries that are objects, so the
    // loop does not have to re-check what the API sent.
    foreach ($nonprofit->organizationTypes() as $entry) {
        Output::field('pub78 entry', sprintf(
            '%s (%s)',
            Output::text($entry['deductibility_status_description'] ?? null) ?: '?',
            Output::text($entry['deductibility_limitation'] ?? null) ?: '?',
        ));
    }

    // A private foundation grantee is not disqualified — it is routed
    // differently, because expenditure responsibility and the deductibility
    // limit both change.
    $isPrivateFoundation = $bmf?->get('foundation_type_code') === 'pf'
        || $bmf?->get('pf_filing_req_cd') === '1';

    Output::field('private foundation', $isPrivateFoundation);
    Output::field('grant path', $isPrivateFoundation ? 'expenditure responsibility review' : 'standard');
}

Output::note('Classification changes the path a grant takes. It is not a pass or a fail.');

$context->close();
