<?php

/**
 * Sending through your own PSR-18 client instead of the bundled cURL transport.
 *
 * Use this when the SDK's requests must go through a stack you already own — a
 * shared connection pool, a corporate proxy, pinned certificates, or a client
 * that records traffic in tests.
 *
 * Needs psr/http-client and a PSR-17 factory, neither of which is a dependency
 * of this package:
 *
 *   composer require nyholm/psr7 psr/http-client
 *
 * Run:  PACTMAN_API_KEY=... php examples/psr18-client.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Pactman\NonprofitCheckPlus\Http\HttpRequest;
use Pactman\NonprofitCheckPlus\Http\Psr18HttpClient;
use Pactman\NonprofitCheckPlus\Http\TransportException;
use Pactman\NonprofitCheckPlus\PactmanClient;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

if (!interface_exists(ClientInterface::class) || !class_exists(Psr17Factory::class)) {
    fwrite(STDERR, "Install psr/http-client and nyholm/psr7 to run this example.\n");

    exit(1);
}

$apiKey = getenv('PACTMAN_API_KEY');
$baseUrl = getenv('PACTMAN_BASE_URL');

if (!is_string($apiKey) || $apiKey === '') {
    fwrite(STDERR, "Set PACTMAN_API_KEY before running this example.\n");

    exit(1);
}

/**
 * Stand-in for the client you already have — Guzzle, Symfony HttpClient, or a
 * recording double. Yours would send the request; this one answers from memory
 * so the example needs no network.
 */
final class RecordingPsr18Client implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $sent = [];

    public function __construct(private readonly Psr17Factory $factory)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;

        $body = json_encode([
            'code' => 200,
            'message' => 'OK',
            'errors' => null,
            'data' => ['ein' => '411787097', 'organization_name' => 'EXAMPLE NONPROFIT'],
            'timeTaken' => 4,
            'nonprofit_check_count' => 7,
        ], JSON_THROW_ON_ERROR);

        return $this->factory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Request-Id', 'psr18-example')
            ->withBody($this->factory->createStream($body));
    }
}

$factory = new Psr17Factory();
$psr18 = new RecordingPsr18Client($factory);

$client = new PactmanClient(
    apiKey: $apiKey,
    baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null,
    // The adapter takes PSR-17 factories for the request and its body.
    httpClient: new Psr18HttpClient($psr18, $factory, $factory),
);

$result = $client->nonprofits->check('41-1787097');

printf("organization    %s\n", (string) $result->nonprofit?->organization_name);
printf("requestId       %s\n", (string) $result->requestId);
printf("checkCount      %s\n", var_export($result->checkCount, true));

$sent = $psr18->sent[0];

printf("\nWhat the SDK handed your client:\n");
printf("  method        %s\n", $sent->getMethod());
printf("  uri           %s\n", (string) $sent->getUri());
printf("  accept        %s\n", $sent->getHeaderLine('Accept'));
printf("  user-agent    %s\n", $sent->getHeaderLine('User-Agent'));
printf("  authorization %s\n", $sent->hasHeader('Authorization') ? 'Bearer [redacted]' : 'MISSING');

// Retries, backoff, throttling and the error taxonomy all still apply — the
// adapter only changes who moves the bytes. The one thing it cannot do is
// enforce the SDK's `timeout`, because PSR-18 has no per-request deadline:
// configure that on the client you pass in.
printf("\nTimeout ownership: your PSR-18 client. The SDK's `timeout` option is\n");
printf("not enforced through this adapter — set it where the connection is made.\n");

// A failure from your client is classified like any other transport failure.
printf("\nA client exception becomes: %s → %s\n", ClientExceptionInterface::class, TransportException::class);
printf("…which the transport then reports as a NetworkException.\n");

unset($request);
