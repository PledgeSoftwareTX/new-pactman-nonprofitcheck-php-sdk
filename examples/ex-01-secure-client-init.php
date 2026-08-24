<?php

/**
 * EX-01 — Secure client initialization.
 *
 * Loads the API key from an environment variable, selects the environment,
 * configures a finite timeout, and builds one reusable client. Then it proves
 * the key does not leak into logs, debug output, or exceptions.
 *
 * Run:  PACTMAN_API_KEY=... php examples/ex-01-secure-client-init.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Config\ClientConfig;
use Pactman\NonprofitCheckPlus\Environment;
use Pactman\NonprofitCheckPlus\Examples\Output;
use Pactman\NonprofitCheckPlus\Exception\ConfigurationException;
use Pactman\NonprofitCheckPlus\PactmanClient;

// 1. The key comes from the environment. It is never a literal in source, never
//    committed, and never shipped to a browser or mobile bundle — anyone who
//    opens devtools on a page holding this key owns your quota.
$apiKey = getenv('PACTMAN_API_KEY');

if (!is_string($apiKey) || trim($apiKey) === '') {
    Output::error('Set PACTMAN_API_KEY before running this example.');
    Output::error('Load it from your secret manager or a .env file excluded from git.');

    exit(1);
}

$baseUrl = getenv('PACTMAN_BASE_URL');

// 2. One client, built once, reused for the life of the process. Constructing a
//    client per request throws away connection reuse and any throttle state.
$client = new PactmanClient(
    apiKey: $apiKey,
    // Production is the default; naming it makes the intent explicit at review time.
    environment: Environment::Production,
    // A mock or a host Pactman gave you directly overrides `environment`.
    baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null,
    // 3. A finite timeout. The default is 30s and there is no way to disable it,
    //    but a caller-facing service usually wants something shorter.
    timeout: 10.0,
);

Output::heading('Resolved configuration');
Output::field('baseUrl', $client->baseUrl());
Output::field('environment', $client->environment()?->value);
Output::field('timeout', $client->timeout());
Output::field('SDK default timeout', ClientConfig::DEFAULT_TIMEOUT);

// 4. Every diagnostic surface is checked against the real key. None of them
//    contain it: the key is not a property of the client, it lives inside a
//    closure the transport calls at send time, and the exception types never
//    copy it into a message or a serialized field.
$caught = null;

try {
    new PactmanClient(apiKey: $apiKey, baseUrl: 'not-a-url');
} catch (ConfigurationException $error) {
    $caught = $error;
}

$surfaces = [
    '(string) $client' => (string) $client,
    '$client->toArray()' => print_r($client->toArray(), true),
    'json_encode($client)' => (string) json_encode($client),
    'print_r($client)' => print_r($client, true),
    'var_dump($client)' => self_var_dump($client),
    '$error->getMessage()' => $caught?->getMessage() ?? '',
    '$error->toArray()' => print_r($caught?->toArray(), true),
    '$error->getTraceAsString()' => $caught?->getTraceAsString() ?? '',
];

Output::heading('Credential redaction');

$leaked = false;

foreach ($surfaces as $surface => $text) {
    $clean = !str_contains($text, $apiKey);
    $leaked = $leaked || !$clean;

    Output::field($surface, $clean ? 'clean' : 'LEAKED THE KEY');
}

Output::heading('Client as printed');
print_r($client->toArray());

Output::field("\nConfiguration error type", $caught === null ? 'none' : $caught::class);

Output::note(
    "The key is sent only as an Authorization header at request time. Rotate it if\n"
    . 'it is ever printed, logged, or committed.',
);

exit($leaked ? 1 : 0);

function self_var_dump(mixed $value): string
{
    ob_start();
    var_dump($value);

    return (string) ob_get_clean();
}
