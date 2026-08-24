<?php

/**
 * Runs the bundled fixture API until interrupted.
 *
 *   php scripts/mock-server.php --port 8787
 *   MOCK_API_KEY=my-key php scripts/mock-server.php
 *
 * Point the SDK at it with `baseUrl`, and use the key it prints:
 *
 *   new PactmanClient(apiKey: 'mock-key', baseUrl: 'http://127.0.0.1:8787');
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\MockServer;

// A required value, so both `--port 8787` and `--port=8787` are accepted.
$options = getopt('', ['port:', 'api-key:']) ?: [];
$portOption = $options['port'] ?? null;
$keyOption = $options['api-key'] ?? null;

$port = is_string($portOption) && $portOption !== '' ? (int) $portOption : 8787;
$apiKey = is_string($keyOption) && $keyOption !== '' ? $keyOption : null;

$server = MockServer::start($port, $apiKey);

printf("Mock Pactman API listening on %s\n", $server->baseUrl());
printf("Accepting Authorization: Bearer %s\n", $server->apiKey);
printf("Press Ctrl-C to stop.\n");

if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);

    foreach ([SIGINT, SIGTERM] as $signal) {
        pcntl_signal($signal, static function () use ($server): void {
            $server->stop();
            exit(0);
        });
    }
}

// Serve until a signal, or until the operator interrupts the process.
/** @phpstan-ignore while.alwaysTrue */
while (true) {
    sleep(1);
}
