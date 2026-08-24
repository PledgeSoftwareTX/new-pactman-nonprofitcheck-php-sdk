<?php

/**
 * EX-24 — Timeouts and operation budgets.
 *
 * `TimeoutException` distinguished from a connection failure, and the difference
 * between a per-attempt deadline and the wall clock a retried call can actually
 * consume.
 *
 * PHP has no cancellation primitive — no AbortSignal, no task to cancel — so a
 * request in flight runs to its deadline. Bounding a whole operation is
 * therefore arithmetic you do up front, which this example shows.
 *
 * Run:  php examples/ex-24-timeouts-and-budgets.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Config\ClientConfig;
use Pactman\NonprofitCheckPlus\Config\RetryOptions;
use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Exception\NetworkException;
use Pactman\NonprofitCheckPlus\Exception\TimeoutException;
use Pactman\NonprofitCheckPlus\PactmanClient;

$context = ExampleContext::fixtures();

Output::heading('The deadline is per attempt, and always finite');
Output::field('SDK default', ClientConfig::DEFAULT_TIMEOUT . 's');
Output::field('client timeout', $context->client->timeout() . 's');
Output::field('can it be disabled', 'no — a non-positive timeout is a ConfigurationException');

// Two different events, two different types. Conflating them hides which side
// gave up: a timeout means raise the budget or shed load; a connection failure
// means the other end was not there at all.
Output::heading('A deadline that expires');

$started = microtime(true);

try {
    // The control EIN holds the response open for five seconds.
    $context->client->nonprofits->check(
        Fixtures::CONTROL_EINS['slow'],
        timeout: 0.5,
        retry: false,
    );
} catch (TimeoutException $error) {
    Output::field('class', $error::class);
    Output::field('category', $error->category->value);
    Output::field('origin', $error->origin->value);
    Output::field('timeout', $error->timeout . 's');
    Output::field('attempts', $error->attempts);
    Output::field('elapsed', round(microtime(true) - $started, 2) . 's');
}

Output::heading('A connection that was never established');

try {
    (new PactmanClient(apiKey: 'any', baseUrl: 'http://127.0.0.1:1', timeout: 1.0, retry: false))
        ->nonprofits->check(Fixtures::EINS['publicCharity']);
} catch (NetworkException $error) {
    Output::field('class', $error::class);
    Output::field('category', $error->category->value);
}

// Retries multiply the wall clock. The per-attempt deadline bounds one attempt;
// the worst case for the whole call is every attempt plus every backoff delay.
Output::heading('Budgeting the whole operation');

$policy = new RetryOptions(maxRetries: 2, initialDelay: 0.5, maxDelay: 8.0, backoffFactor: 2.0);
$perAttempt = 2.0;

$attempts = $policy->maxRetries + 1;
$backoff = 0.0;

for ($attempt = 1; $attempt <= $policy->maxRetries; ++$attempt) {
    $backoff += min($policy->initialDelay * $policy->backoffFactor ** ($attempt - 1), $policy->maxDelay);
}

Output::field('per-attempt timeout', $perAttempt . 's');
Output::field('attempts at most', $attempts);
Output::field('backoff at most', $backoff . 's');
Output::field('worst case for the call', ($perAttempt * $attempts + $backoff) . 's');

// With jitter on — the default — real delays land anywhere in [0, computed], so
// this is a ceiling rather than an estimate.
Output::field('jitter', $policy->jitter ? 'on: the number above is a ceiling' : 'off');

Output::heading('Fitting a caller-facing budget');

$budget = 3.0;
$fitted = new RetryOptions(maxRetries: 1, initialDelay: 0.25, maxDelay: 0.5);

Output::field('budget', $budget . 's');
Output::field('chosen timeout', '1.0s per attempt');
Output::field('chosen retries', $fitted->maxRetries);
Output::field('worst case', (1.0 * ($fitted->maxRetries + 1) + $fitted->initialDelay) . 's');

Output::note(
    "A server-supplied Retry-After is honored even when it exceeds maxDelay, so a\n"
    . "429 can outlast the ceiling above. Where a hard budget matters, disable retries\n"
    . 'and schedule the next attempt yourself.',
);

$context->close();
