<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Tests;

use Pactman\NonprofitCheckPlus\Config\ClientConfig;
use Pactman\NonprofitCheckPlus\Exception\ErrorCategory;
use Pactman\NonprofitCheckPlus\Exception\ErrorOrigin;
use Pactman\NonprofitCheckPlus\Exception\TimeoutException;
use Pactman\NonprofitCheckPlus\Http\TransportException;
use Pactman\NonprofitCheckPlus\Tests\Support\Clock;
use Pactman\NonprofitCheckPlus\Tests\Support\FakeHttpClient;
use Pactman\NonprofitCheckPlus\Tests\Support\Fixtures;
use Pactman\NonprofitCheckPlus\Tests\Support\Stub;
use PHPUnit\Framework\TestCase;

final class TimeoutTest extends TestCase
{
    public function testAnExpiredDeadlineBecomesATimeoutException(): void
    {
        $http = FakeHttpClient::always(Stub::timeout());

        try {
            Fixtures::client($http, retry: false, timeout: 0.25)
                ->nonprofits->check('411787097');
            self::fail('Expected a TimeoutException.');
        } catch (TimeoutException $error) {
            self::assertSame(ErrorCategory::Timeout, $error->category);
            self::assertSame(ErrorOrigin::Local, $error->origin);
            self::assertSame(0.25, $error->timeout);
            self::assertSame(1, $error->attempts);
            self::assertStringContainsString('timed out after 0.25s', $error->getMessage());
            self::assertInstanceOf(TransportException::class, $error->getPrevious());
        }
    }

    public function testTheDeadlineReachesTheHttpClient(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope(Fixtures::nonprofit())));
        $client = Fixtures::client($http, timeout: 10.0);

        $client->nonprofits->check('411787097');
        self::assertSame(10.0, $http->lastRequest()->timeout);

        $client->nonprofits->check('411787097', timeout: 2.5);
        self::assertSame(2.5, $http->lastRequest()->timeout);
    }

    public function testTheDefaultDeadlineIsThirtySeconds(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope(Fixtures::nonprofit())));

        Fixtures::client($http)->nonprofits->check('411787097');

        self::assertSame(ClientConfig::DEFAULT_TIMEOUT, $http->lastRequest()->timeout);
    }

    public function testATimeoutIsRetriedLikeAnyTransientFailure(): void
    {
        $http = new FakeHttpClient([
            Stub::timeout(),
            new Stub(body: Fixtures::envelope(Fixtures::nonprofit())),
        ]);
        $clock = new Clock();

        $result = Fixtures::client($http, $clock->hooks())->nonprofits->check('411787097');

        self::assertSame(200, $result->status);
        self::assertSame(2, $http->requestCount());
    }

    public function testAnExhaustedTimeoutReportsEveryAttempt(): void
    {
        $http = FakeHttpClient::always(Stub::timeout());
        $clock = new Clock();

        try {
            Fixtures::client($http, $clock->hooks())->nonprofits->check('411787097');
            self::fail('Expected a TimeoutException.');
        } catch (TimeoutException $error) {
            self::assertSame(3, $error->attempts);
            self::assertSame(3, $http->requestCount());
        }
    }

    public function testTimeoutAndNetworkFailuresStayDistinguishable(): void
    {
        $timeout = FakeHttpClient::always(Stub::timeout());
        $network = FakeHttpClient::always(Stub::networkFailure());

        $categories = [];

        foreach (['timeout' => $timeout, 'network' => $network] as $name => $http) {
            try {
                Fixtures::client($http, retry: false)->nonprofits->check('411787097');
            } catch (\Pactman\NonprofitCheckPlus\Exception\PactmanException $error) {
                $categories[$name] = $error->category;
            }
        }

        self::assertSame(
            ['timeout' => ErrorCategory::Timeout, 'network' => ErrorCategory::Network],
            $categories,
        );
    }
}
