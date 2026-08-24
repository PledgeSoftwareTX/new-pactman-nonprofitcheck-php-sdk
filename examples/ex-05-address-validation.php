<?php

/**
 * EX-05 — Validating the returned address.
 *
 * Asks whether the address the API returned is well-formed and self-consistent,
 * before acting on it. Complete is not the same as correct.
 *
 * Run:  php examples/ex-05-address-validation.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;

// The fixture API serves a record whose address components contradict each other.
$context = ExampleContext::fixtures();

/** A slice of the USPS table, enough to show the shape of the check. */
const STATES = ['MA' => 'Massachusetts', 'ME' => 'Maine', 'NH' => 'New Hampshire', 'VT' => 'Vermont'];

/** ZIP prefix → the states that use it. Deliberately partial; see below. */
const ZIP_PREFIXES = ['010' => ['MA'], '011' => ['MA'], '048' => ['ME'], '049' => ['ME']];

foreach ([Fixtures::EINS['inconsistentAddress'], Fixtures::EINS['sparseIdentity'], Fixtures::EINS['publicCharity']] as $ein) {
    $nonprofit = $context->client->nonprofits->check($ein)->nonprofit;

    if ($nonprofit === null) {
        continue;
    }

    Output::heading('EIN ' . $ein . ' — ' . Output::text($nonprofit->get('organization_name')));

    foreach (['address_line1', 'address_line2', 'city', 'state', 'state_name', 'zip'] as $field) {
        Output::displayField($nonprofit, $field);
    }

    // `state` and `state_name` are two fields for one fact, and the ZIP encodes
    // the state a third time. A record can be complete and still contradict itself.
    $stateValue = $nonprofit->get('state');
    $state = is_string($stateValue) ? strtoupper(trim($stateValue)) : null;
    $zipDigits = (string) preg_replace('/\D/', '', Output::text($nonprofit->get('zip')));
    $claimants = ZIP_PREFIXES[substr($zipDigits, 0, 3)] ?? [];

    $missing = array_values(array_filter(
        ['address_line1', 'city', 'state', 'zip'],
        static fn (string $component): bool => !$nonprofit->has($component) || $nonprofit->get($component) === null,
    ));

    $failures = array_values(array_filter([
        $state !== null && array_key_exists($state, STATES) ? null : 'state is not a USPS code',
        ($state !== null ? STATES[$state] ?? null : null) === $nonprofit->get('state_name')
            ? null
            : 'state_name disagrees with state',
        in_array(strlen($zipDigits), [5, 9], true) ? null : 'zip is not 5 or 9 digits',
        // A check that cannot run reports nothing, never a failure: an incomplete
        // lookup table must not manufacture a finding about somebody's address.
        $claimants !== [] && !in_array($state, $claimants, true) ? 'zip belongs to another state' : null,
    ]));

    // Three verdicts, and the middle one is the point. Absence is not validity.
    $verdict = match (true) {
        $failures !== [] => 'inconsistent',
        $missing !== [] => 'incomplete',
        default => 'usable',
    };

    Output::field('missing components', $missing === [] ? 'none' : implode(', ', $missing));

    foreach ($failures as $failure) {
        Output::bullet($failure);
    }

    Output::field('verdict', $verdict);
    Output::field('routed to', $verdict === 'usable' ? 'continue' : 'manual_review');
}

Output::note(
    "Well-formed is not deliverable. USPS, Lob, Smarty and Google Address Validation\n"
    . 'answer that one, over the network, with a second credential.',
);

$context->close();
