<?php

/**
 * EX-21 — Billing-cycle usage tracking.
 *
 * `nonprofit_check_count`, surfaced as `$result->checkCount`, is the running
 * total of checks your account has consumed so far in the current billing
 * cycle. It is never the size of the request you just made.
 *
 * The test is one thing: fetch one nonprofit by EIN, and confirm the API sent
 * that counter as a JSON number. The SDK maps anything else to `null`, which
 * downstream is indistinguishable from "not reported", so the check reads the
 * uncoerced value off `raw`. This example exits non-zero when it is not a
 * number.
 *
 * Run:  PACTMAN_API_KEY=... php examples/ex-21-usage-tracking.php [EIN]
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;

$context = ExampleContext::live();
$ein = (string) ExampleContext::argument(fallback: Fixtures::EINS['publicCharity']);

$result = $context->client->nonprofits->check($ein);
$context->close();

// `checkCount` is `int|null`, and the SDK produces that `null` both for a
// counter the API sent as null and for one it sent as `"42"`. Only `raw`, which
// nothing has coerced, tells them apart.
$envelope = $result->raw;
$returned = is_array($envelope) && array_key_exists('nonprofit_check_count', $envelope);
$wireValue = $returned ? $envelope['nonprofit_check_count'] : null;
$wireType = $returned ? jsonType($wireValue) : Output::NOT_RETURNED;

Output::heading('nonprofit_check_count on the wire');
Output::field('ein', $ein);
Output::field('wire type', $wireType);
Output::field('checkCount', $result->checkCount);

Output::note(
    "The counter is cumulative for the billing cycle and resets when a new one starts.\n"
    . 'A bulk call for five EINs does not return 5 — it returns your cycle total.',
);

if ($wireType !== 'number') {
    $detail = $returned ? ' ' . (string) json_encode($wireValue, JSON_UNESCAPED_SLASHES) : '';

    Output::error(sprintf(
        "\nnonprofit_check_count arrived as %s%s, not a number, so checkCount reads null.",
        $wireType,
        $detail,
    ));

    exit(1);
}

exit(0);

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
