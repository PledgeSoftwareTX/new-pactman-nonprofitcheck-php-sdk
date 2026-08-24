<?php

/**
 * EX-03 — Identity lookup.
 *
 * EIN, name, AKA and Pactman profile URL, plus the raw envelope alongside the
 * typed model.
 *
 * Run:  PACTMAN_API_KEY=... php examples/ex-03-identity-lookup.php 41-1787097
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;

$context = ExampleContext::live();
$ein = ExampleContext::argument(fallback: Fixtures::EINS['publicCharity']);

$result = $context->client->nonprofits->check((string) $ein);
$nonprofit = $result->nonprofit;

if ($nonprofit === null) {
    Output::error('The API returned no record for this EIN.');
    $context->close();

    exit(1);
}

Output::heading('Identity');
Output::displayField($nonprofit, 'ein');
Output::displayField($nonprofit, 'organization_name');
// Frequently null: "none on file", not "none exists".
Output::displayField($nonprofit, 'organization_name_aka');
Output::displayField($nonprofit, 'pactman_org_url');

Output::heading('Address as the API returned it');

foreach (['address_line1', 'address_line2', 'city', 'state', 'state_name', 'zip'] as $field) {
    Output::displayField($nonprofit, $field);
}

Output::heading('Response metadata');
Output::field('status', $result->status);
Output::field('requestId', $result->requestId);
Output::field('timeTakenMs', $result->timeTakenMs);
Output::field('checkCount', $result->checkCount);

// The typed model is a view over the envelope, not a replacement for it.
Output::heading('The envelope underneath');
$envelope = is_array($result->raw) ? $result->raw : [];
Output::field('raw[code]', $envelope['code'] ?? null);
Output::field('raw[message]', $envelope['message'] ?? null);
Output::field('raw[data][ein]', is_array($envelope['data'] ?? null) ? ($envelope['data']['ein'] ?? null) : null);

Output::note('Persist `raw` when you need evidence of what the API actually said.');

$context->close();
