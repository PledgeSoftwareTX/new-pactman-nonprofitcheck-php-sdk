<?php

/**
 * EX-13 — Filing and exemption metadata.
 *
 * Filing and exemption codes preserved exactly, or mapped through documented
 * tables with an unknown-value fallback.
 *
 * Run:  php examples/ex-13-filing-exemption-metadata.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Model\SourceView;
use Pactman\NonprofitCheckPlus\Sources;

$context = ExampleContext::fixtures();

/** A documented table, kept deliberately short. */
const FILING_REQUIREMENTS = [
    '00' => 'Not required to file (income below threshold)',
    '01' => '990 (all other) or 990-EZ return',
    '02' => '990 - Required to file Form 990-N',
];

const PF_FILING_REQUIREMENTS = [
    '0' => 'No 990-PF return',
    '1' => '990-PF return',
];

/**
 * A documented table with an explicit unknown fallback.
 *
 * A value the IRS adds reads as "unrecognized" — never as a blank, and never as
 * the wrong label.
 *
 * Note the key type: PHP turns `'0'` and `'1'` into `int` keys, so the table is
 * `array-key`-keyed even though every key here was written as a string. Lookup
 * canonicalizes the same way, so `$table[$code]` still finds the right row.
 *
 * @param array<array-key, string> $table
 *
 * @return array{code: string|null, known: bool, display: string}
 */
function describe(array $table, SourceView $source, string $field): array
{
    if (!$source->has($field) || $source->get($field) === null) {
        return ['code' => null, 'known' => false, 'display' => Output::NOT_RETURNED];
    }

    $code = Output::text($source->get($field));
    $description = $table[$code] ?? null;

    return [
        'code' => $code,
        'known' => $description !== null,
        'display' => $description ?? "unrecognized code \"{$code}\"",
    ];
}

foreach ([Fixtures::EINS['publicCharity'], Fixtures::EINS['privateFoundation'], Fixtures::EINS['futureFields']] as $ein) {
    $nonprofit = $context->client->nonprofits->check($ein)->nonprofit;
    $bmf = $nonprofit === null ? null : Sources::bmf($nonprofit);

    if ($bmf === null) {
        continue;
    }

    Output::heading(Output::text($nonprofit?->get('organization_name')));

    $filing = describe(FILING_REQUIREMENTS, $bmf, 'filing_req_code');
    $pfFiling = describe(PF_FILING_REQUIREMENTS, $bmf, 'pf_filing_req_cd');

    Output::field('filing_req_code', $filing['code']);
    Output::field('  → ' . ($filing['known'] ? 'known' : 'UNKNOWN'), $filing['display']);
    Output::field('pf_filing_req_cd', $pfFiling['code']);
    Output::field('  → ' . ($pfFiling['known'] ? 'known' : 'UNKNOWN'), $pfFiling['display']);

    // Codes the API already describes for you: read its description, do not
    // shadow it with a local table that will drift.
    Output::heading('Described by the API itself');
    Output::displayField($bmf, 'subsection');
    Output::displayField($bmf, 'subsection_description');
    Output::displayField($bmf, 'foundation_code');
    Output::displayField($bmf, 'foundation_code_description');
    Output::displayField($bmf, 'foundation_type_code');
    Output::displayField($bmf, 'foundation_type_description');
    // Raw values, preserved exactly, null included.
    Output::displayField($bmf, 'ruling_month');
    Output::displayField($bmf, 'ruling_year');
    Output::displayField($bmf, 'exempt_status_code');
}

Output::note(
    "Never coerce an unrecognized code to a default. \"Unknown\" is a real state, and it\n"
    . 'usually means review rather than approval.',
);

$context->close();
