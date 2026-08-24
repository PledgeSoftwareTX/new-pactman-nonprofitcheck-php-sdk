<?php

/**
 * EX-22 — Rate limits and `Retry-After`.
 *
 * HTTP 429, `Retry-After`, bounded retries, a client-side rate ceiling and a
 * bounded worker loop.
 *
 * Run:  php examples/ex-22-rate-limit.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Exception\RateLimitException;

$context = ExampleContext::fixtures();

// 1. Retries off, so the 429 reaches the caller untouched.
Output::heading('The 429 as the caller sees it');

try {
    $context->client->nonprofits->check(Fixtures::CONTROL_EINS['rateLimited'], retry: false);
} catch (RateLimitException $error) {
    Output::field('status', $error->status);
    Output::field('category', $error->category->value);
    // The server's number, when it sent one.
    Output::field('retryAfterSeconds', $error->retryAfterSeconds);
    Output::field('requestId', $error->requestId);
    Output::field('attempts', $error->attempts);

    foreach ($error->apiErrors as $detail) {
        Output::field('errors[]', (string) $detail->reason);
    }

    // Schedule your own backoff from the server's number; fall back when absent.
    $wait = $error->retryAfterSeconds ?? 5.0;
    $retryAt = (new DateTimeImmutable())->modify('+' . (int) ceil($wait) . ' seconds');

    Output::field('retry no earlier than', $retryAt->format(DATE_ATOM));
}

// 2. Bounded automatic retry. Retry-After wins over computed backoff, and
//    retries stay finite — the SDK never retries indefinitely.
Output::heading('Bounded automatic retry');

$started = microtime(true);

try {
    $context->client->nonprofits->check(
        Fixtures::CONTROL_EINS['rateLimited'],
        retry: ['maxRetries' => 1, 'respectRetryAfter' => true],
    );
} catch (RateLimitException $error) {
    Output::field('attempts made', $error->attempts);
    Output::field('wall clock', round(microtime(true) - $started, 2) . 's');
    Output::field('still finite', 'the budget was spent, and the error surfaced');
}

// 3. Reduce pressure rather than absorb rejections: cap the outbound rate, keep
//    your own concurrency small, and prefer one bulk call to a fan-out of single
//    ones. The SDK throttles, but it does not queue on your behalf.
Output::heading('Reducing pressure instead of absorbing rejections');

$paced = $context->sibling(retry: ['maxRetries' => 2], maxRequestsPerSecond: 3.0);
$started = microtime(true);

foreach ([Fixtures::EINS['publicCharity'], Fixtures::EINS['publicCharitySecond'], Fixtures::EINS['privateFoundation']] as $ein) {
    $paced->nonprofits->check($ein);
}

Output::field('3 checks at 3/second', round(microtime(true) - $started, 2) . 's');
Output::field('one bulk call instead', 'one round trip, one rate-limit slot');

Output::note(
    "Server-provided limits are authoritative and may vary by account and endpoint.\n"
    . 'Treat maxRequestsPerSecond as a courtesy throttle, not a guarantee.',
);

$context->close();
