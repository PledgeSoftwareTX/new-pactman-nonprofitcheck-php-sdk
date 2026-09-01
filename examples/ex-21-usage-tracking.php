<?php

/**
 * EX-21 — Billing-cycle usage tracking.
 *
 * `nonprofit_check_count`, surfaced as `$result->checkCount`, is the running
 * total of checks your account has consumed so far in the current billing
 * cycle. It is never the size of the request you just made.
 *
 * The test is one thing: the API sends that counter as a JSON number. The SDK
 * maps anything else to `null`, which downstream is indistinguishable from "not
 * reported", so the check reads the uncoerced value off `raw`. This example
 * exits non-zero when any response fails it.
 *
 * Run:  php examples/ex-21-usage-tracking.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Model\PactmanResult;

$context = ExampleContext::fixtures();

$samples = [
    sample('single check', $context->client->nonprofits->check(Fixtures::EINS['publicCharity'])),
    sample(
        'single check',
        $context->client->nonprofits->check(Fixtures::EINS['publicCharitySecond']),
    ),
    sample('bulk check of 3', $context->client->nonprofits->checkBulk([
        Fixtures::EINS['publicCharity'],
        Fixtures::EINS['publicCharitySecond'],
        Fixtures::EINS['privateFoundation'],
    ])),
    sample('bulk with a miss', $context->client->nonprofits->checkBulk([
        Fixtures::EINS['revoked'],
        Fixtures::EINS['noRecord'],
    ])),
];

$context->close();

Output::heading('nonprofit_check_count on the wire');
printf("  %-20s %-20s checkCount\n", 'request', 'wire type');

foreach ($samples as $sample) {
    printf("  %-20s %-20s %s\n", $sample['label'], $sample['wire'], $sample['checkCount'] ?? 'null');
}

$mistyped = array_values(
    array_filter($samples, static fn (array $sample): bool => $sample['wire'] !== 'number'),
);

Output::heading('Verdict');
Output::field('responses inspected', count($samples));
Output::field('sent as a JSON number', count($samples) - count($mistyped));

foreach ($mistyped as $sample) {
    Output::bullet(sprintf(
        '%s: the API sent %s, so checkCount reads null',
        $sample['label'],
        $sample['wire'],
    ));
}

Output::note(
    "The counter is cumulative for the billing cycle and resets when a new one starts.\n"
    . 'A bulk call for five EINs does not return 5 — it returns your cycle total.',
);

if ($mistyped !== []) {
    Output::error(sprintf(
        "\n%d of %d responses did not send nonprofit_check_count as a number.",
        count($mistyped),
        count($samples),
    ));
}

exit($mistyped === [] ? 0 : 1);

/**
 * One response, reduced to what the check needs.
 *
 * @return array{label: string, wire: string, checkCount: int|null}
 */
function sample(string $label, PactmanResult $result): array
{
    return ['label' => $label, 'wire' => wireCheckCount($result), 'checkCount' => $result->checkCount];
}

/**
 * How `nonprofit_check_count` arrived, before this SDK read it.
 *
 * `checkCount` is `int|null`, and the SDK produces that `null` both for a
 * counter the API sent as null and for one it sent as `"42"`. Only `raw`, which
 * nothing has coerced, tells them apart.
 */
function wireCheckCount(PactmanResult $result): string
{
    $envelope = $result->raw;

    if (!is_array($envelope) || !array_key_exists('nonprofit_check_count', $envelope)) {
        return Output::NOT_RETURNED;
    }

    $value = $envelope['nonprofit_check_count'];
    $type = jsonType($value);

    return $type === 'number'
        ? 'number'
        : $type . ' ' . (string) json_encode($value, JSON_UNESCAPED_SLASHES);
}

/** The JSON type of a value, in the vocabulary the response contract uses. */
function jsonType(mixed $value): string
{
    return match (true) {
        $value === null => 'null',
        is_bool($value) => 'boolean',
        is_int($value), is_float($value) => 'number',
        is_string($value) => 'string',
        is_array($value) => array_is_list($value) ? 'array' : 'object',
        default => get_debug_type($value),
    };
}
