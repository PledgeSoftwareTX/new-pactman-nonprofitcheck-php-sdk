<?php

/**
 * Check several EINs in one request, and account for the ones with no record.
 *
 * Run:  PACTMAN_API_KEY=... php examples/bulk.php 41-1787097 996589560
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\PactmanClient;

$apiKey = getenv('PACTMAN_API_KEY');

if (!is_string($apiKey) || $apiKey === '') {
    fwrite(STDERR, "Set PACTMAN_API_KEY before running this example.\n");

    exit(1);
}

$baseUrl = getenv('PACTMAN_BASE_URL');
/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$eins = array_slice($arguments, 1);

if ($eins === []) {
    $eins = ['41-1787097', '996589560', '999999999'];
}

$client = new PactmanClient(
    apiKey: $apiKey,
    baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null,
);

$result = $client->nonprofits->checkBulk($eins);

// The response is a set of matched records, not a row-for-row answer to your
// input, so index by EIN rather than pairing positionally.
$byEin = $result->byEin();

foreach ($eins as $ein) {
    $normalized = str_replace('-', '', trim($ein));
    $organization = $byEin[$normalized] ?? null;

    printf(
        "%-12s %s\n",
        $normalized,
        $organization === null
            ? 'no record returned'
            : (string) $organization->organization_name,
    );
}

printf("\nMatched      %d\n", count($result->organizations));
printf("No record    %s\n", implode(', ', $result->notFoundEins) ?: 'none');
printf("Checks used  %s this billing cycle\n", var_export($result->checkCount, true));
