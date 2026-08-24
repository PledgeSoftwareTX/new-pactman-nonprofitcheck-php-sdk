<?php

/**
 * EX-08 — Automatic revocation detected.
 *
 * An organization in the IRS Automatic Revocation data, flagged and recorded
 * with its source fields.
 *
 * Run:  php examples/ex-08-automatic-revocation.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ApiDate;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Sources;

// A revoked exemption is not something a live API will produce on request.
$context = ExampleContext::fixtures();

$result = $context->client->nonprofits->check(Fixtures::EINS['revoked']);
$nonprofit = $result->nonprofit;

if ($nonprofit === null) {
    Output::error('The fixture API returned no record.');
    $context->close();

    exit(1);
}

$aroe = Sources::aroe($nonprofit);
$revoked = $aroe !== null
    && (($aroe->get('revocation_code') ?? null) !== null || ($aroe->get('revocation_date') ?? null) !== null);

Output::heading(Output::text($nonprofit->get('organization_name')));
Output::displayField($nonprofit, 'ein');

Output::heading('Automatic Revocation of Exemption');

foreach (['revocation_code', 'revocation_date', 'reinstatement_date', 'list_published_date'] as $field) {
    Output::displayField($aroe ?? $nonprofit, $field);
}

// The application's policy, in one place, expressed against source fields.
$action = match (true) {
    !$revoked => 'continue',
    // $revoked is only true when $aroe exists, so it is non-null from here.
    $aroe !== null && $aroe->get('reinstatement_date') !== null => 'manual_review',
    default => 'block',
};

Output::heading('The other sources agree');
Output::displayField($nonprofit, 'bmf_status');
Output::displayField($nonprofit, 'pub78_verified');
Output::displayField($nonprofit, 'bmf_deductability_text');

Output::heading('Decision');
Output::field('revoked', $revoked);
Output::field('action', $action);

// What you keep is what you can explain later. Store the source fields, the
// request identifier and the time you looked — not just the verdict.
const AUDITED = [
    'revocation_code',
    'revocation_date',
    'reinstatement_date',
    'aroe_list_published_date',
    'bmf_status',      // revocation shows up in the other sources too
    'pub78_verified',
];

$sourceFindings = [];

foreach (AUDITED as $field) {
    // Absent keys stay absent, so the record cannot imply a null the API never sent.
    if ($nonprofit->has($field)) {
        $sourceFindings[$field] = $nonprofit->get($field);
    }
}

$auditRecord = [
    'ein' => $nonprofit->get('ein'),
    'checked_at' => ApiDate::checkedAt(),
    'request_id' => $result->requestId,
    'action' => $action,
    'source_findings' => $sourceFindings,
];

Output::heading('Audit record');
echo json_encode($auditRecord, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

Output::note('A verdict without its evidence cannot be defended six months later.');

$context->close();
