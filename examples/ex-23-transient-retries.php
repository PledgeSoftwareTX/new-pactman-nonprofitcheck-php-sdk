<?php

/**
 * EX-23 — Transient failures and retries.
 *
 * Transient 5xx and connection failures retried with jittered backoff; auth,
 * validation and not-found never retried.
 *
 * Run:  php examples/ex-23-transient-retries.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Exception\NetworkException;
use Pactman\NonprofitCheckPlus\Exception\NotFoundException;
use Pactman\NonprofitCheckPlus\PactmanClient;

$context = ExampleContext::fixtures();

// The control EIN answers 503 twice, then succeeds. Backoff grows exponentially
// and is jittered, so parallel clients scatter instead of retrying in lockstep.
Output::heading('Two 503s absorbed, one result returned');

$started = microtime(true);
$result = $context->client->nonprofits->check(
    Fixtures::CONTROL_EINS['transientFailure'],
    retry: ['maxRetries' => 3, 'initialDelay' => 0.2, 'maxDelay' => 2.0],
);

Output::field('status', $result->status);
Output::field('organization', $result->nonprofit?->organization_name);
Output::field('wall clock', round(microtime(true) - $started, 2) . 's');
Output::field('the caller saw', 'one successful result');

// Never retried, whatever retryableStatuses contains. Retrying a 404 cannot make
// a record exist; retrying a rejected key just burns it three times.
Output::heading('Statuses that are never retried');

try {
    $context->client->nonprofits->check(Fixtures::EINS['noRecord'], retry: [
        'maxRetries' => 5,
        'retryableStatuses' => [404, 500],
    ]);
} catch (NotFoundException $error) {
    Output::field('asked for 5 retries on 404', 'refused');
    Output::field('attempts', $error->attempts);
}

// A connection that never reached a server: retried, then surfaced with the
// attempt count and the underlying cause chained.
Output::heading('A connection that never reached a server');

$unreachable = new PactmanClient(
    apiKey: 'any-key',
    baseUrl: 'http://127.0.0.1:1',
    timeout: 1.0,
    retry: ['maxRetries' => 2, 'initialDelay' => 0.1],
);

try {
    $unreachable->nonprofits->check(Fixtures::EINS['publicCharity']);
} catch (NetworkException $error) {
    Output::field('attempts', $error->attempts);
    Output::field('category', $error->category->value);
    Output::field('previous', $error->getPrevious()?->getMessage());
}

Output::note(
    "A retried failure that exhausts its budget is an outage. Record it as \"not\n"
    . 'checked\", never as a pass.',
);

$context->close();
