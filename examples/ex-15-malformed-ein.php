<?php

/**
 * EX-15 — Malformed EIN rejected locally.
 *
 * Every malformed shape rejected locally, with an instrumented transport proving
 * no request was sent.
 *
 * Run:  PACTMAN_API_KEY=... php examples/ex-15-malformed-ein.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Exception\ValidationException;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Http\CurlHttpClient;
use Pactman\NonprofitCheckPlus\Http\HttpClientInterface;
use Pactman\NonprofitCheckPlus\Http\HttpRequest;
use Pactman\NonprofitCheckPlus\Http\HttpResponse;
use Pactman\NonprofitCheckPlus\PactmanClient;

/**
 * A counting wrapper around the real client, to prove the claim rather than
 * assert it. If any call below reaches the network, this number moves.
 */
final class CountingHttpClient implements HttpClientInterface
{
    public int $requestsSent = 0;

    public function __construct(private readonly HttpClientInterface $inner)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        ++$this->requestsSent;

        return $this->inner->send($request);
    }
}

$apiKey = getenv('PACTMAN_API_KEY');

if (!is_string($apiKey) || trim($apiKey) === '') {
    Output::error('Set PACTMAN_API_KEY before running this example.');

    exit(1);
}

$baseUrl = getenv('PACTMAN_BASE_URL');
$counting = new CountingHttpClient(new CurlHttpClient());

$client = new PactmanClient(
    apiKey: $apiKey,
    baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null,
    httpClient: $counting,
);

$malformed = [
    '41178709',      // eight digits
    '4117870977',    // ten digits
    '41-178709A',    // a letter
    '',              // empty
    '   ',           // whitespace
    '41.1787097',    // the wrong separator
    '411-787097',    // the hyphen in the wrong place
];

Output::heading('Rejected before anything is sent');

foreach ($malformed as $value) {
    try {
        $client->nonprofits->check($value);
        Output::field((string) json_encode($value), 'accepted?! — this should not happen');
    } catch (ValidationException $error) {
        $issue = $error->issues[0] ?? null;

        Output::field(
            (string) json_encode($value),
            sprintf('%s — %s', $error->origin->value, $issue === null ? $error->getMessage() : $issue->message),
        );
    }
}

// Bulk reports every failure at once, by index — enough to highlight the exact
// rows of an upload that need fixing.
Output::heading('A bulk batch reports every failure at once');

try {
    $client->nonprofits->checkBulk(['411787097', 'nope', '996589560', '1234']);
} catch (ValidationException $error) {
    Output::field('message', $error->getMessage());

    foreach ($error->issues as $issue) {
        Output::field("index {$issue->index}", json_encode($issue->value) . ' — ' . $issue->message);
    }
}

Output::heading('Requests that reached the network');
Output::field('requestsSent', $counting->requestsSent);

Output::note('Bad input costs no quota, no latency, and no rate-limit budget.');

exit($counting->requestsSent === 0 ? 0 : 1);
