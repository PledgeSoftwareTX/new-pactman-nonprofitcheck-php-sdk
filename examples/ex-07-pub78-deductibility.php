<?php

/**
 * EX-07 — Publication 78 and deductibility.
 *
 * Publication 78 verification and deductibility entries, with a donation policy
 * applied in customer code.
 *
 * Run:  PACTMAN_API_KEY=... php examples/ex-07-pub78-deductibility.php 41-1787097
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Sources;

$context = ExampleContext::live();
$ein = (string) ExampleContext::argument(fallback: Fixtures::EINS['publicCharity']);

$nonprofit = $context->client->nonprofits->check($ein)->nonprofit;
$pub78 = $nonprofit === null ? null : Sources::pub78($nonprofit);

if ($pub78 === null) {
    Output::heading('No Publication 78 data was returned');
    Output::note('That is not the same as "not listed". Route it to review.');
    $context->close();

    exit(0);
}

Output::heading('Publication 78');
Output::displayField($pub78, 'verified');
Output::displayField($pub78, 'indicator');
Output::displayField($pub78, 'church_message');
Output::displayField($pub78, 'most_recent');
Output::displayField($pub78, 'source_org_type_1');
Output::displayField($pub78, 'source_org_type_2');
Output::displayField($pub78, 'source_org_type_3');

Output::heading('Deductibility entries');

$entries = is_array($pub78->get('organization_types')) ? $pub78->get('organization_types') : [];
$limitations = [];

foreach ($entries as $index => $entry) {
    if (!is_array($entry)) {
        continue;
    }

    Output::field("[{$index}] status", $entry['deductibility_status_description'] ?? null);
    Output::field("[{$index}] limitation", $entry['deductibility_limitation'] ?? null);
    Output::field("[{$index}] description", mb_strimwidth(Output::text($entry['organization_type'] ?? null), 0, 70, '…'));

    if (isset($entry['deductibility_limitation']) && is_string($entry['deductibility_limitation'])) {
        $limitations[] = $entry['deductibility_limitation'];
    }
}

if ($entries === []) {
    Output::bullet('The API returned no deductibility entries.');
}

// Your policy, expressed against the source data. Change the predicate, not the
// SDK — nothing here is a verdict the API handed down.
const ACCEPTED_LIMITATIONS = ['50%', '60%'];

$eligibleUnderThisPolicy = $pub78->get('verified') === true
    && array_intersect($limitations, ACCEPTED_LIMITATIONS) !== [];

Output::heading('This application\'s policy');
Output::field('accepted limitations', implode(', ', ACCEPTED_LIMITATIONS));
Output::field('limitations returned', $limitations === [] ? 'none' : implode(', ', $limitations));
Output::field('eligible under this policy', $eligibleUnderThisPolicy);

Output::note('The SDK reports what Publication 78 says. Whether that qualifies is yours.');

$context->close();
