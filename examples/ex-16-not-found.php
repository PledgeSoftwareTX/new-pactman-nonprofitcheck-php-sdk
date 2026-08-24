<?php

/**
 * EX-16 — EIN not found.
 *
 * A well-formed EIN with no record: `NotFoundException`, sanitized diagnostics,
 * and why bulk behaves differently.
 *
 * Run:  php examples/ex-16-not-found.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Exception\ApiException;
use Pactman\NonprofitCheckPlus\Exception\NotFoundException;
use Pactman\NonprofitCheckPlus\Exception\PactmanException;

$context = ExampleContext::fixtures();

Output::heading('A single check with no matching record');

try {
    $context->client->nonprofits->check(Fixtures::EINS['noRecord']);
} catch (NotFoundException $error) {
    // Stable identity: class, category, origin. Never parse `getMessage()`.
    Output::field('class', $error::class);
    Output::field('category', $error->category->value);
    Output::field('origin', $error->origin->value);
    // Catch the specific case, or the general one — every API error is an
    // ApiException, and every SDK error is a PactmanException.
    Output::field('parents', implode(' → ', array_values(class_parents($error) ?: [])));
    Output::field('is an ApiException', in_array(ApiException::class, class_parents($error) ?: [], true));
    Output::field('isPactmanError', PactmanException::isPactmanError($error));

    // The envelope's own detail survives onto the exception.
    Output::field('status', $error->status);
    Output::field('apiCode', $error->apiCode);
    Output::field('apiMessage', $error->apiMessage);
    Output::field('requestId', $error->requestId);
    // Not-found is not a transient failure, so it is never retried.
    Output::field('attempts', $error->attempts);

    foreach ($error->apiErrors as $detail) {
        Output::field('errors[]', $detail->resource . ': ' . $detail->reason);
    }

    Output::heading('Sanitized — safe to log, safe to attach to a ticket');
    echo json_encode($error->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}

// The bulk endpoint behaves differently: unmatched EINs come back on a 200.
Output::heading('The same EIN inside a bulk request');

$mixed = $context->client->nonprofits->checkBulk([
    Fixtures::EINS['publicCharity'],
    Fixtures::EINS['noRecord'],
]);

Output::field('status', $mixed->status);
Output::field('organizations', count($mixed->organizations));
Output::field('notFoundEins', implode(', ', $mixed->notFoundEins));

Output::note(
    "Only a bulk request where nothing at all matched is a 404. An EIN with no record\n"
    . 'is a gap in the data, not a negative finding about the organization.',
);

$context->close();
