<?php

/**
 * EX-14 — Data freshness and report metadata.
 *
 * Source timestamps, report date and request timing, feeding an
 * application-owned re-review rule.
 *
 * Run:  php examples/ex-14-data-freshness.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ApiDate;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;

$context = ExampleContext::fixtures();

// Your rule. The SDK has no isStale() and no default threshold, because 90 days
// is prudent for one workflow and reckless for another.
const RE_REVIEW_AFTER_DAYS = 90;

const TIMESTAMP_FIELDS = [
    'organization_info_last_modified',
    'report_date',              // when this response was generated
    'most_recent_bmf',          // when each list was last refreshed
    'most_recent_pub78',
];

foreach ([Fixtures::EINS['publicCharity'], Fixtures::EINS['staleData']] as $ein) {
    $result = $context->client->nonprofits->check($ein);
    $nonprofit = $result->nonprofit;

    if ($nonprofit === null) {
        continue;
    }

    Output::heading(Output::text($nonprofit->get('organization_name')));

    $ages = [];

    foreach (TIMESTAMP_FIELDS as $field) {
        $value = $nonprofit->get($field);
        $age = ApiDate::ageInDays($value);
        $ages[$field] = $age;

        Output::field($field, $age === null ? Output::NOT_RETURNED : "{$age} days old");
    }

    $undated = array_keys(array_filter($ages, static fn (?int $age): bool => $age === null));
    $dated = array_filter($ages, static fn (?int $age): bool => $age !== null);
    $oldest = $dated === [] ? 0 : max($dated);

    // The oldest source governs, and an undated source is not a fresh one.
    $needsReReview = $oldest > RE_REVIEW_AFTER_DAYS || $undated !== [];

    Output::field('oldest source', "{$oldest} days");
    Output::field('undated sources', $undated === [] ? 'none' : implode(', ', $undated));
    Output::field('threshold', RE_REVIEW_AFTER_DAYS . ' days');
    Output::field('needs re-review', $needsReReview);

    // Store the timestamps with the verification record, not just the outcome.
    // "We checked and it was fine" is not an answer six months later; "we checked
    // on this date against BMF data published on that date" is.
    $evidence = ['ein' => $nonprofit->get('ein'), 'checked_at' => ApiDate::checkedAt(), 'request_id' => $result->requestId];

    foreach (TIMESTAMP_FIELDS as $field) {
        if ($nonprofit->has($field)) {
            $evidence[$field] = $nonprofit->get($field);
        }
    }

    Output::field('evidence keys', implode(', ', array_keys($evidence)));
}

Output::note('A clean answer from stale data is still stale. The timestamps are the point.');

$context->close();
