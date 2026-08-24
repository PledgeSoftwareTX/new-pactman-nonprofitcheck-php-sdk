<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Tests;

use Pactman\NonprofitCheckPlus\Config\ClientConfig;
use Pactman\NonprofitCheckPlus\Config\RetryOptions;
use Pactman\NonprofitCheckPlus\Endpoints;
use Pactman\NonprofitCheckPlus\Environment;
use Pactman\NonprofitCheckPlus\Exception\ConfigurationException;
use Pactman\NonprofitCheckPlus\PactmanClient;
use Pactman\NonprofitCheckPlus\Tests\Support\FakeHttpClient;
use Pactman\NonprofitCheckPlus\Tests\Support\Fixtures;
use Pactman\NonprofitCheckPlus\Tests\Support\Stub;
use Pactman\NonprofitCheckPlus\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /** @return iterable<string, array{string|null, string}> */
    public static function unusableApiKeys(): iterable
    {
        yield 'null' => [null, 'A Pactman API key is required'];
        yield 'empty' => ['', 'The Pactman API key is empty'];
        yield 'whitespace' => ['   ', 'The Pactman API key is empty'];
    }

    #[DataProvider('unusableApiKeys')]
    public function testRejectsAnUnusableApiKey(?string $apiKey, string $expected): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expected, '/') . '/');

        new PactmanClient(apiKey: $apiKey);
    }

    public function testDefaultsToProduction(): void
    {
        $client = new PactmanClient(apiKey: Fixtures::API_KEY);

        self::assertSame(Environment::Production, $client->environment());
        self::assertSame('https://entities.pactman.org', $client->baseUrl());
        self::assertSame(ClientConfig::DEFAULT_TIMEOUT, $client->timeout());
        self::assertSame(30.0, $client->timeout());
    }

    public function testAcceptsAnEnvironmentAsAStringOrAnEnum(): void
    {
        $fromEnum = new PactmanClient(apiKey: Fixtures::API_KEY, environment: Environment::Production);
        $fromString = new PactmanClient(apiKey: Fixtures::API_KEY, environment: 'production');

        self::assertSame($fromEnum->baseUrl(), $fromString->baseUrl());
    }

    public function testRejectsAnUnknownEnvironment(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/Unknown environment "sandbox"/');

        new PactmanClient(apiKey: Fixtures::API_KEY, environment: 'sandbox');
    }

    public function testAnExplicitBaseUrlOverridesTheEnvironment(): void
    {
        $client = new PactmanClient(apiKey: Fixtures::API_KEY, baseUrl: 'http://127.0.0.1:4010');

        self::assertSame('http://127.0.0.1:4010', $client->baseUrl());
        self::assertNull($client->environment());
    }

    public function testTrimsATrailingSlashFromTheBaseUrl(): void
    {
        $client = new PactmanClient(apiKey: Fixtures::API_KEY, baseUrl: 'https://proxy.internal/pactman/');

        self::assertSame('https://proxy.internal/pactman', $client->baseUrl());
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedBaseUrls(): iterable
    {
        yield 'not a url' => ['not-a-url', 'is not a valid URL'];
        yield 'empty' => ['', 'must be a non-empty URL string'];
        yield 'unsupported scheme' => ['ftp://entities.pactman.org', 'must use http or https'];
    }

    #[DataProvider('malformedBaseUrls')]
    public function testRejectsAMalformedBaseUrl(string $baseUrl, string $expected): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expected, '/') . '/');

        new PactmanClient(apiKey: Fixtures::API_KEY, baseUrl: $baseUrl);
    }

    /** @return iterable<string, array{float}> */
    public static function unusableTimeouts(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'infinite' => [INF];
        yield 'nan' => [NAN];
    }

    #[DataProvider('unusableTimeouts')]
    public function testRejectsAnUnusableTimeout(float $timeout): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/There is no way to disable the timeout/');

        new PactmanClient(apiKey: Fixtures::API_KEY, timeout: $timeout);
    }

    public function testRejectsAnUnusableRateCeiling(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/must be a number greater than zero/');

        new PactmanClient(apiKey: Fixtures::API_KEY, maxRequestsPerSecond: 0.0);
    }

    public function testRetryFalseDisablesRetryingWithoutLosingTheOtherSettings(): void
    {
        $client = new PactmanClient(apiKey: Fixtures::API_KEY, retry: false);

        self::assertSame(0, $client->retry()->maxRetries);
        self::assertSame(0.5, $client->retry()->initialDelay);
        self::assertTrue($client->retry()->respectRetryAfter);
    }

    public function testRetryArrayMergesOntoTheDefaults(): void
    {
        $client = new PactmanClient(apiKey: Fixtures::API_KEY, retry: ['maxRetries' => 5]);

        self::assertSame(5, $client->retry()->maxRetries);
        self::assertSame(8.0, $client->retry()->maxDelay);
    }

    public function testRetryRejectsAnUnknownKey(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/Unknown retry option\(s\): maxRetrys/');

        new PactmanClient(apiKey: Fixtures::API_KEY, retry: ['maxRetrys' => 5]);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function unusableRetryPolicies(): iterable
    {
        yield 'negative retries' => [['maxRetries' => -1], 'maxRetries'];
        yield 'negative initial delay' => [['initialDelay' => -1.0], 'initialDelay'];
        yield 'negative max delay' => [['maxDelay' => -1.0], 'maxDelay'];
        yield 'backoff below one' => [['backoffFactor' => 0.5], 'backoffFactor'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('unusableRetryPolicies')]
    public function testRejectsAnUnusableRetryPolicy(array $overrides, string $expected): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expected, '/') . '/');

        new PactmanClient(apiKey: Fixtures::API_KEY, retry: $overrides);
    }

    public function testNeverRetriesTheStatusesThatCannotSucceed(): void
    {
        $policy = new RetryOptions(retryableStatuses: [400, 401, 403, 404, 500]);

        foreach ([400, 401, 403, 404] as $status) {
            self::assertFalse($policy->isRetryableStatus($status), "status {$status}");
        }

        self::assertTrue($policy->isRetryableStatus(500));
    }

    public function testTheUserAgentNamesThePackageAndVersion(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope(Fixtures::nonprofit())));
        Fixtures::client($http)->nonprofits->check('411787097');

        $userAgent = $http->lastRequest()->headers['User-Agent'];

        self::assertStringStartsWith('pactmandev-nonprofit-check-plus/' . Version::VERSION, $userAgent);
        self::assertStringContainsString('php/' . PHP_VERSION, $userAgent);
    }

    public function testTheVersionMatchesTheOneCiPublishes(): void
    {
        $composer = json_decode(
            (string) file_get_contents(__DIR__ . '/../composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($composer);
        self::assertSame('pactmandev/nonprofit-check-plus', $composer['name']);
        self::assertSame($composer['name'], Version::PACKAGE_NAME);
    }

    public function testEndpointsAreDeclaredOnce(): void
    {
        self::assertSame(50, Endpoints::MAX_BULK_EINS);
        self::assertStringContainsString('{ein}', Endpoints::SINGLE_CHECK_PATH);
        self::assertSame('/api/entities/nonprofitcheckbulk/v1/us/eins', Endpoints::BULK_CHECK_PATH);
    }

    public function testOnlyTheEnvironmentEnumDeclaresAPactmanHost(): void
    {
        $mentions = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../src'),
        ) as $file) {
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'Environment.php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $total = substr_count($source, 'entities.pactman.org');

            if ($total === 0) {
                continue;
            }

            // The host may appear as an example inside guidance text — that is
            // prose, not an endpoint. Anything else is a host escaping the enum.
            $asGuidance = substr_count($source, 'Expected something like https://entities.pactman.org');

            if ($total !== $asGuidance) {
                $mentions[] = $file->getFilename();
            }
        }

        self::assertSame(
            [],
            $mentions,
            'Endpoint hosts belong in Environment.php and nowhere else.',
        );
    }
}
