<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Tests;

use Pactman\NonprofitCheckPlus\Config\RetryOptions;
use Pactman\NonprofitCheckPlus\Exception\NetworkException;
use Pactman\NonprofitCheckPlus\Exception\NotFoundException;
use Pactman\NonprofitCheckPlus\Exception\RateLimitException;
use Pactman\NonprofitCheckPlus\Exception\ServerException;
use Pactman\NonprofitCheckPlus\Http\Transport;
use Pactman\NonprofitCheckPlus\Tests\Support\Clock;
use Pactman\NonprofitCheckPlus\Tests\Support\FakeHttpClient;
use Pactman\NonprofitCheckPlus\Tests\Support\Fixtures;
use Pactman\NonprofitCheckPlus\Tests\Support\Stub;
use PHPUnit\Framework\TestCase;

final class RetryTest extends TestCase
{
    public function testRetriesATransient503AndReturnsTheEventualSuccess(): void
    {
        $http = new FakeHttpClient([
            new Stub(status: 503, body: ['code' => 503]),
            new Stub(status: 503, body: ['code' => 503]),
            new Stub(body: Fixtures::envelope(Fixtures::nonprofit())),
        ]);
        $clock = new Clock();

        $result = Fixtures::client($http, $clock->hooks())->nonprofits->check('411787097');

        self::assertSame(200, $result->status);
        self::assertSame(3, $http->requestCount());
        self::assertCount(2, $clock->delays);
    }

    public function testStopsOnceTheRetryBudgetIsSpent(): void
    {
        $http = FakeHttpClient::always(new Stub(status: 503, body: ['code' => 503]));
        $clock = new Clock();

        try {
            Fixtures::client($http, $clock->hooks())->nonprofits->check('411787097');
            self::fail('Expected a ServerException.');
        } catch (ServerException $error) {
            self::assertSame(3, $http->requestCount(), 'two retries after the first attempt');
            self::assertSame(3, $error->attempts);
        }
    }

    public function testRetriesATransportFailure(): void
    {
        $http = new FakeHttpClient([
            Stub::networkFailure(),
            Stub::networkFailure(),
            new Stub(body: Fixtures::envelope(Fixtures::nonprofit())),
        ]);
        $clock = new Clock();

        $result = Fixtures::client($http, $clock->hooks())->nonprofits->check('411787097');

        self::assertSame(200, $result->status);
        self::assertSame(3, $http->requestCount());
    }

    public function testSurfacesTheAttemptCountOnAnExhaustedNetworkFailure(): void
    {
        $http = FakeHttpClient::always(Stub::networkFailure());
        $clock = new Clock();

        try {
            Fixtures::client($http, $clock->hooks())->nonprofits->check('411787097');
            self::fail('Expected a NetworkException.');
        } catch (NetworkException $error) {
            self::assertSame(3, $error->attempts);
        }
    }

    public function testNeverRetriesAStatusThatCannotSucceed(): void
    {
        foreach ([400, 401, 403, 404] as $status) {
            $http = FakeHttpClient::always(new Stub(status: $status, body: ['code' => $status]));
            $clock = new Clock();

            try {
                // Even asked to retry it explicitly.
                Fixtures::client($http, $clock->hooks())->nonprofits->check('411787097', retry: [
                    'maxRetries' => 5,
                    'retryableStatuses' => [400, 401, 403, 404, 500],
                ]);
                self::fail("Expected an exception for HTTP {$status}.");
            } catch (\Pactman\NonprofitCheckPlus\Exception\ApiException $error) {
                self::assertSame(1, $http->requestCount(), "HTTP {$status} must not be retried");
                self::assertSame(1, $error->attempts);
            }
        }
    }

    public function testRetryFalseDisablesRetryingForOneRequest(): void
    {
        $http = FakeHttpClient::always(new Stub(status: 503, body: ['code' => 503]));
        $clock = new Clock();

        try {
            Fixtures::client($http, $clock->hooks())->nonprofits->check('411787097', retry: false);
            self::fail('Expected a ServerException.');
        } catch (ServerException) {
            self::assertSame(1, $http->requestCount());
            self::assertSame([], $clock->delays);
        }
    }

    public function testAPerRequestArrayMergesOntoTheClientPolicy(): void
    {
        $http = FakeHttpClient::always(new Stub(status: 503, body: ['code' => 503]));
        $clock = new Clock();

        try {
            Fixtures::client($http, $clock->hooks(), retry: ['initialDelay' => 2.0, 'jitter' => false])
                ->nonprofits->check('411787097', retry: ['maxRetries' => 1]);
            self::fail('Expected a ServerException.');
        } catch (ServerException) {
            self::assertSame(2, $http->requestCount());
            // initialDelay survived the merge, so the one delay is 2s, not 0.5s.
            self::assertSame([2.0], $clock->delays);
        }
    }

    public function testHonoursRetryAfterOverComputedBackoff(): void
    {
        $http = new FakeHttpClient([
            new Stub(status: 429, body: ['code' => 429], headers: ['retry-after' => '7']),
            new Stub(body: Fixtures::envelope(Fixtures::nonprofit())),
        ]);
        $clock = new Clock();

        Fixtures::client($http, $clock->hooks())->nonprofits->check('411787097');

        self::assertSame([7.0], $clock->delays);
    }

    public function testIgnoresRetryAfterWhenTheCallerTurnsItOff(): void
    {
        $http = new FakeHttpClient([
            new Stub(status: 429, body: ['code' => 429], headers: ['retry-after' => '600']),
            new Stub(body: Fixtures::envelope(Fixtures::nonprofit())),
        ]);
        $clock = new Clock();

        Fixtures::client($http, $clock->hooks())->nonprofits->check('411787097', retry: [
            'respectRetryAfter' => false,
            'jitter' => false,
        ]);

        self::assertSame([0.5], $clock->delays);
    }

    public function testSurfacesRetryAfterOnAnExhaustedRateLimit(): void
    {
        $http = FakeHttpClient::always(new Stub(
            status: 429,
            body: ['code' => 429],
            headers: ['retry-after' => '12'],
        ));
        $clock = new Clock();

        try {
            Fixtures::client($http, $clock->hooks())->nonprofits->check('411787097', retry: false);
            self::fail('Expected a RateLimitException.');
        } catch (RateLimitException $error) {
            self::assertSame(12.0, $error->retryAfterSeconds);
        }
    }

    public function testBackoffGrowsExponentiallyAndIsCapped(): void
    {
        $policy = new RetryOptions(initialDelay: 0.5, maxDelay: 8.0, backoffFactor: 2.0, jitter: false);

        self::assertSame(0.5, Transport::computeRetryDelay(1, $policy, null));
        self::assertSame(1.0, Transport::computeRetryDelay(2, $policy, null));
        self::assertSame(2.0, Transport::computeRetryDelay(3, $policy, null));
        self::assertSame(8.0, Transport::computeRetryDelay(9, $policy, null), 'capped at maxDelay');
    }

    public function testFullJitterSpreadsTheDelayAcrossTheWholeRange(): void
    {
        $policy = new RetryOptions(initialDelay: 4.0, jitter: true);

        self::assertSame(0.0, Transport::computeRetryDelay(1, $policy, null, static fn (): float => 0.0));
        self::assertSame(2.0, Transport::computeRetryDelay(1, $policy, null, static fn (): float => 0.5));
        self::assertSame(4.0, Transport::computeRetryDelay(1, $policy, null, static fn (): float => 1.0));
    }

    public function testAServerRetryAfterBeatsTheDelayCeiling(): void
    {
        $policy = new RetryOptions(maxDelay: 8.0);

        self::assertSame(120.0, Transport::computeRetryDelay(1, $policy, 120.0));
    }

    public function testANegativeRetryAfterFallsBackToBackoff(): void
    {
        $policy = new RetryOptions(initialDelay: 0.5, jitter: false);

        self::assertSame(0.5, Transport::computeRetryDelay(1, $policy, -5.0));
    }

    public function testThrottlesToTheConfiguredRate(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope(Fixtures::nonprofit())));
        $clock = new Clock();
        $client = Fixtures::client($http, $clock->hooks(), maxRequestsPerSecond: 2.0);

        $client->nonprofits->check('411787097');
        $client->nonprofits->check('411787097');
        $client->nonprofits->check('411787097');

        // The first goes straight out; the next two are spaced half a second apart.
        self::assertSame([0.5, 0.5], $clock->delays);
        self::assertSame(3, $http->requestCount());
    }

    public function testDoesNotThrottleByDefault(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope(Fixtures::nonprofit())));
        $clock = new Clock();
        $client = Fixtures::client($http, $clock->hooks());

        $client->nonprofits->check('411787097');
        $client->nonprofits->check('411787097');

        self::assertSame([], $clock->delays);
    }
}
