<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Tests;

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Dev\MockServer;
use Pactman\NonprofitCheckPlus\Exception\AuthenticationException;
use Pactman\NonprofitCheckPlus\Exception\NotFoundException;
use Pactman\NonprofitCheckPlus\Exception\RateLimitException;
use Pactman\NonprofitCheckPlus\Exception\TimeoutException;
use Pactman\NonprofitCheckPlus\Http\CurlHttpClient;
use Pactman\NonprofitCheckPlus\Http\HttpRequest;
use Pactman\NonprofitCheckPlus\PactmanClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The bundled cURL client against a real socket.
 *
 * Everything else in the suite substitutes the transport, which is what keeps
 * those tests fast and deterministic — but it also means nothing else here
 * exercises ext-curl, header parsing, or the wire format. This does.
 */
#[Group('integration')]
final class CurlHttpClientTest extends TestCase
{
    private static ?MockServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = MockServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    private static function server(): MockServer
    {
        self::assertNotNull(self::$server);

        return self::$server;
    }

    private static function client(?float $timeout = null): PactmanClient
    {
        return new PactmanClient(
            apiKey: self::server()->apiKey,
            baseUrl: self::server()->baseUrl(),
            timeout: $timeout,
            retry: false,
        );
    }

    public function testChecksAnOrganizationOverHttp(): void
    {
        $result = self::client()->nonprofits->check(Fixtures::EINS['publicCharity']);

        self::assertSame(200, $result->status);
        self::assertNotNull($result->nonprofit);
        self::assertSame(Fixtures::EINS['publicCharity'], $result->nonprofit->ein);
        self::assertSame('MEALS TODAY EXAMPLE NONPROFIT', $result->nonprofit->organization_name);
        self::assertNotNull($result->checkCount);
        self::assertNotNull($result->timeTakenMs);
    }

    public function testReadsAResponseHeaderOffTheWire(): void
    {
        $result = self::client()->nonprofits->check(Fixtures::EINS['publicCharity']);

        self::assertNotNull($result->requestId);
        self::assertStringStartsWith('mock-', $result->requestId);
    }

    public function testPostsABulkBodyAndReadsPartialSuccess(): void
    {
        $result = self::client()->nonprofits->checkBulk([
            Fixtures::EINS['publicCharity'],
            Fixtures::EINS['noRecord'],
            Fixtures::EINS['publicCharitySecond'],
        ]);

        self::assertSame(200, $result->status);
        self::assertCount(2, $result->organizations);
        self::assertSame([Fixtures::EINS['noRecord']], $result->notFoundEins);
    }

    public function testMapsA404FromTheWire(): void
    {
        $this->expectException(NotFoundException::class);

        self::client()->nonprofits->check(Fixtures::EINS['noRecord']);
    }

    public function testMapsA401FromTheWire(): void
    {
        $client = new PactmanClient(
            apiKey: 'not-the-key',
            baseUrl: self::server()->baseUrl(),
            retry: false,
        );

        $this->expectException(AuthenticationException::class);

        $client->nonprofits->check(Fixtures::EINS['publicCharity']);
    }

    public function testReadsRetryAfterFromTheWire(): void
    {
        try {
            self::client()->nonprofits->check(Fixtures::CONTROL_EINS['rateLimited']);
            self::fail('Expected a RateLimitException.');
        } catch (RateLimitException $error) {
            self::assertSame(429, $error->status);
            self::assertSame(1.0, $error->retryAfterSeconds);
        }
    }

    public function testAnExpiredDeadlineIsReportedAsATimeout(): void
    {
        try {
            // The control EIN holds the response open for five seconds.
            self::client(timeout: 0.5)->nonprofits->check(Fixtures::CONTROL_EINS['slow']);
            self::fail('Expected a TimeoutException.');
        } catch (TimeoutException $error) {
            self::assertSame(0.5, $error->timeout);
        }
    }

    public function testAnUnreachableHostIsReportedAsANetworkFailure(): void
    {
        $client = new PactmanClient(
            apiKey: 'any',
            // Port 1 on loopback refuses connections.
            baseUrl: 'http://127.0.0.1:1',
            timeout: 2.0,
            retry: false,
        );

        $this->expectException(\Pactman\NonprofitCheckPlus\Exception\NetworkException::class);

        $client->nonprofits->check(Fixtures::EINS['publicCharity']);
    }

    public function testReusesOneConnectionAcrossRequests(): void
    {
        $client = self::client();

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(200, $client->nonprofits->check(Fixtures::EINS['publicCharity'])->status);
        }
    }

    public function testAClosedClientIsUsableAgain(): void
    {
        $http = new CurlHttpClient();
        $url = self::server()->baseUrl() . '/api/entities/nonprofitcheck/v1/us/ein/'
            . Fixtures::EINS['publicCharity'];
        $headers = ['Authorization' => 'Bearer ' . self::server()->apiKey];

        self::assertSame(200, $http->send(new HttpRequest('GET', $url, $headers, null, 5.0))->status);

        $http->close();

        self::assertSame(200, $http->send(new HttpRequest('GET', $url, $headers, null, 5.0))->status);
    }
}
