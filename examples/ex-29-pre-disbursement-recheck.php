<?php

/**
 * EX-29 — Pre-disbursement recheck.
 *
 * A grant was approved weeks ago. Before the money moves, check again — and
 * compare the two findings rather than trusting the stored verdict.
 *
 * Run:  php examples/ex-29-pre-disbursement-recheck.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ApiDate;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Exception\PactmanException;
use Pactman\NonprofitCheckPlus\Sources;

$context = ExampleContext::fixtures();

/** The fields a disbursement decision actually rests on. */
const MATERIAL_FIELDS = [
    'bmf_status',
    'pub78_verified',
    'revocation_code',
    'revocation_date',
    'reinstatement_date',
    'ofac_status',
    'irs_bmf_pub78_conflict',
];

// What was stored at approval time, six weeks ago.
$approvals = [
    [
        'ein' => Fixtures::EINS['publicCharity'],
        'amount' => 40_000,
        'approved_at' => '2026-07-08T14:02:00+00:00',
        'findings_at_approval' => [
            'bmf_status' => true,
            'pub78_verified' => true,
            'revocation_code' => null,
            'revocation_date' => null,
            'reinstatement_date' => null,
            'ofac_status' => Fixtures::OFAC_NO_MATCH,
            'irs_bmf_pub78_conflict' => false,
        ],
    ],
    [
        'ein' => Fixtures::EINS['revoked'],
        'amount' => 15_000,
        'approved_at' => '2026-07-02T09:30:00+00:00',
        // At approval this organization looked clean. It no longer does.
        'findings_at_approval' => [
            'bmf_status' => true,
            'pub78_verified' => true,
            'revocation_code' => null,
            'revocation_date' => null,
            'reinstatement_date' => null,
            'ofac_status' => Fixtures::OFAC_NO_MATCH,
            'irs_bmf_pub78_conflict' => false,
        ],
    ],
];

foreach ($approvals as $approval) {
    Output::heading(sprintf('$%s — EIN %s', number_format($approval['amount']), $approval['ein']));
    Output::field('approved at', $approval['approved_at']);

    // A recheck that fails to complete blocks the disbursement. It never falls
    // back to the stored verdict: "we could not check" is not "it is still fine".
    try {
        $result = $context->client->nonprofits->check($approval['ein']);
    } catch (PactmanException $error) {
        Output::field('recheck', 'did not complete — ' . $error->category->value);
        Output::field('decision', 'hold the disbursement');

        continue;
    }

    $nonprofit = $result->nonprofit;

    if ($nonprofit === null) {
        Output::field('decision', 'hold — the API now returns no record');

        continue;
    }

    // Compare field by field. A verdict stored at approval time cannot tell you
    // what changed; the findings can.
    $changes = [];

    foreach (MATERIAL_FIELDS as $field) {
        $then = $approval['findings_at_approval'][$field] ?? null;
        $now = $nonprofit->has($field) ? $nonprofit->get($field) : null;

        if ($then !== $now) {
            $changes[$field] = ['then' => $then, 'now' => $now];
        }
    }

    foreach ($changes as $field => $change) {
        Output::field(
            "changed: {$field}",
            Output::format($change['then']) . '  →  ' . Output::format($change['now']),
        );
    }

    $aroe = Sources::aroe($nonprofit);
    $revoked = ($aroe?->get('revocation_date') ?? null) !== null;

    $decision = match (true) {
        $revoked => 'STOP — exemption revoked since approval',
        $changes !== [] => 'hold for review — material findings changed',
        default => 'release — findings unchanged since approval',
    };

    Output::field('changes', count($changes));
    Output::field('decision', $decision);

    // The recheck is itself evidence. Store it beside the approval, not over it.
    Output::field('recheck record', json_encode([
        'ein' => $nonprofit->get('ein'),
        'rechecked_at' => ApiDate::checkedAt(),
        'request_id' => $result->requestId,
        'decision' => $decision,
        'changed_fields' => array_keys($changes),
    ]));
}

Output::note(
    "Exemption status is a fact about a moment, not a property of an organization.\n"
    . 'The gap between approval and disbursement is exactly where it changes.',
);

$context->close();
