<?php

/**
 * The error taxonomy, one branch at a time.
 *
 * Every failure is a PactmanException with a stable category and origin. Branch
 * on the class or the category — never on message text.
 *
 * Run:  PACTMAN_API_KEY=... php examples/error-handling.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Exception\ApiException;
use Pactman\NonprofitCheckPlus\Exception\AuthenticationException;
use Pactman\NonprofitCheckPlus\Exception\NetworkException;
use Pactman\NonprofitCheckPlus\Exception\NotFoundException;
use Pactman\NonprofitCheckPlus\Exception\PactmanException;
use Pactman\NonprofitCheckPlus\Exception\RateLimitException;
use Pactman\NonprofitCheckPlus\Exception\TimeoutException;
use Pactman\NonprofitCheckPlus\Exception\ValidationException;
use Pactman\NonprofitCheckPlus\PactmanClient;

$apiKey = getenv('PACTMAN_API_KEY');

if (!is_string($apiKey) || $apiKey === '') {
    fwrite(STDERR, "Set PACTMAN_API_KEY before running this example.\n");

    exit(1);
}

$baseUrl = getenv('PACTMAN_BASE_URL');

$client = new PactmanClient(
    apiKey: $apiKey,
    baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null,
);

/** The shape of a caller that handles every case it can act on. */
function check(PactmanClient $client, string $ein): string
{
    try {
        $result = $client->nonprofits->check($ein);

        return $result->nonprofit === null
            ? 'no record in the response'
            : (string) $result->nonprofit->organization_name;
    } catch (ValidationException $error) {
        // Bad input. Nothing was sent, and nothing was billed.
        return 'invalid input: ' . ($error->issues[0]->message ?? $error->getMessage());
    } catch (AuthenticationException) {
        // The key was rejected. Retrying will not help.
        return 'the API key was rejected — check PACTMAN_API_KEY';
    } catch (NotFoundException) {
        return 'no IRS record for this EIN';
    } catch (RateLimitException $error) {
        return 'rate limited; retry after ' . var_export($error->retryAfterSeconds, true) . 's';
    } catch (TimeoutException $error) {
        return "timed out after {$error->timeout}s over {$error->attempts} attempt(s)";
    } catch (NetworkException $error) {
        return "no response after {$error->attempts} attempt(s)";
    } catch (ApiException $error) {
        // Anything else the API returned. The metadata survives even when the
        // body could not be deserialized.
        return sprintf(
            'HTTP %d (%s) requestId=%s',
            $error->status,
            $error->category->value,
            var_export($error->requestId, true),
        );
    }
}

foreach (['41-1787097', 'not-an-ein', '999999999'] as $ein) {
    printf("%-14s %s\n", $ein, check($client, $ein));
}

// Catching the base class catches everything this SDK can throw.
try {
    $client->nonprofits->check('4117870');
} catch (PactmanException $error) {
    printf(
        "\nbase class caught  %s (category=%s, origin=%s)\n",
        $error::class,
        $error->category->value,
        $error->origin->value,
    );
    // Safe to log: the API key is never in here.
    printf("serialized         %s\n", (string) json_encode($error->toArray()));
}
