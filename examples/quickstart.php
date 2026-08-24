<?php

/**
 * The shortest useful thing: check one EIN and read the result.
 *
 * Run:  PACTMAN_API_KEY=... php examples/quickstart.php 41-1787097
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
$ein = $argv[1] ?? '41-1787097';

$client = new PactmanClient(
    apiKey: $apiKey,
    baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null,
);

$result = $client->nonprofits->check($ein);

if ($result->nonprofit === null) {
    echo "The API returned no record for {$ein}.\n";

    exit(0);
}

printf("%s\n", (string) $result->nonprofit->organization_name);
printf("  EIN            %s\n", (string) $result->nonprofit->ein);
printf("  Pub 78         %s\n", var_export($result->nonprofit->pub78_verified, true));
printf("  BMF            %s\n", var_export($result->nonprofit->bmf_status, true));
printf("  OFAC           %s\n", (string) $result->nonprofit->ofac_status);
printf("  Checks used    %s this billing cycle\n", var_export($result->checkCount, true));
