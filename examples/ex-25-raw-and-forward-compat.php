<?php

/**
 * EX-25 — Raw response and forward compatibility.
 *
 * A record from a newer API version: unknown fields and an unknown enum value,
 * both readable, neither fatal.
 *
 * Run:  php examples/ex-25-raw-and-forward-compat.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Sources;

$context = ExampleContext::fixtures();

$result = $context->client->nonprofits->check(Fixtures::EINS['futureFields']);
$nonprofit = $result->nonprofit;

if ($nonprofit === null) {
    Output::error('The fixture API returned no record.');
    $context->close();

    exit(1);
}

// Known fields deserialize exactly as they always have.
Output::heading('Known fields are unaffected');
$bmf = Sources::bmf($nonprofit);
Output::field('bmf.status', $bmf?->get('status'));
Output::field('organization_name', $nonprofit->get('organization_name'));

// Fields this SDK version does not declare ride along on the same object. No
// upgrade needed, and no deserialization failure.
Output::heading('Fields newer than this SDK');

$known = Fixtures::knownNonprofitFields();
$unknown = array_values(array_diff(array_keys($nonprofit->toArray()), $known));

foreach ($unknown as $field) {
    Output::field($field, $nonprofit->get($field));
}

if ($unknown === []) {
    Output::bullet('This response contains nothing outside the published contract.');
}

// Narrow them deliberately rather than trusting the shape.
$registration = $nonprofit->get('state_charity_registration_status');
Output::field('narrowed to a string', is_string($registration) ? $registration : 'not a string — ignored');

// An unrecognized value in a documented field. This is the case that breaks
// applications which map eagerly into an enum and default the miss.
Output::heading('An unknown value in a known field');

const KNOWN_FOUNDATION_TYPES = ['pc', 'pf', 'po'];
$foundationType = $bmf?->get('foundation_type_code');

Output::field('foundation_type_code', $foundationType);
Output::field('foundation_type_description', $bmf?->get('foundation_type_description'));
Output::field(
    'handled as',
    in_array($foundationType, KNOWN_FOUNDATION_TYPES, true)
        ? 'a known classification'
        : 'unknown — routed to review, not defaulted to a known type',
);

// The same holds inside nested objects.
Output::heading('Unknown members inside a known object');

foreach ($nonprofit->organizationTypes() as $entry) {
    foreach ($entry as $key => $value) {
        Output::field($key, is_string($value) ? mb_strimwidth($value, 0, 60, '…') : $value);
    }
}

Output::heading('The envelope, unmodified');
Output::field('raw is the parsed body', is_array($result->raw));
Output::field(
    'raw[data] matches the model',
    is_array($result->raw) && ($result->raw['data'] ?? null) === $nonprofit->toArray(),
);

Output::note('Persist `raw` as evidence. It is what the API said, before this SDK read it.');

$context->close();
