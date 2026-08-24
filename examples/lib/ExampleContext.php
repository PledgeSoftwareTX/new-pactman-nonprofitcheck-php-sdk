<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Examples;

use Pactman\NonprofitCheckPlus\Config\RetryOptions;
use Pactman\NonprofitCheckPlus\Dev\MockServer;
use Pactman\NonprofitCheckPlus\PactmanClient;

/**
 * Wiring shared by every example: where to send requests, and where the key
 * comes from.
 *
 * Examples that need an ordinary lookup use {@see live()} and run against
 * production, or against `PACTMAN_BASE_URL` when it is set. Examples that need a
 * record or a response a live API will not produce on request — a revoked
 * exemption, an OFAC match, an HTTP 429, a field newer than this SDK — use
 * {@see fixtures()}, which starts the bundled fixture API and shuts it down on
 * the way out.
 */
final class ExampleContext
{
    private function __construct(
        public readonly PactmanClient $client,
        private readonly ?MockServer $server = null,
    ) {
    }

    /**
     * A client pointed at production, or at `PACTMAN_BASE_URL` when set.
     *
     * @param RetryOptions|array<string, mixed>|bool|null $retry
     */
    public static function live(
        ?float $timeout = null,
        RetryOptions|array|bool|null $retry = null,
    ): self {
        return new self(new PactmanClient(
            apiKey: self::requireApiKey(),
            baseUrl: self::baseUrlOverride(),
            timeout: $timeout,
            retry: $retry,
        ));
    }

    /**
     * A client pointed at the bundled fixture API.
     *
     * `PACTMAN_BASE_URL` still wins, so the same example can be aimed at a
     * different mock or a sandbox you have been given.
     *
     * @param RetryOptions|array<string, mixed>|bool|null $retry
     */
    public static function fixtures(
        ?float $timeout = null,
        RetryOptions|array|bool|null $retry = null,
    ): self {
        $override = self::baseUrlOverride();

        if ($override !== null) {
            return new self(new PactmanClient(
                apiKey: self::requireApiKey(),
                baseUrl: $override,
                timeout: $timeout,
                retry: $retry,
            ));
        }

        $server = MockServer::start();

        return new self(
            new PactmanClient(
                apiKey: $server->apiKey,
                baseUrl: $server->baseUrl(),
                timeout: $timeout,
                retry: $retry,
            ),
            $server,
        );
    }

    /**
     * Builds a second client against the same target, for examples that need two.
     *
     * @param RetryOptions|array<string, mixed>|bool|null $retry
     */
    public function sibling(
        ?float $timeout = null,
        RetryOptions|array|bool|null $retry = null,
        ?float $maxRequestsPerSecond = null,
    ): PactmanClient {
        return new PactmanClient(
            apiKey: $this->server === null ? self::requireApiKey() : $this->server->apiKey,
            baseUrl: $this->client->baseUrl(),
            timeout: $timeout,
            retry: $retry,
            maxRequestsPerSecond: $maxRequestsPerSecond,
        );
    }

    /** True when this example is running against the bundled fixture API. */
    public function usesFixtures(): bool
    {
        return $this->server !== null;
    }

    public function close(): void
    {
        $this->server?->stop();
    }

    /** The first CLI argument, or `$fallback`. Examples take an EIN this way. */
    public static function argument(int $position = 1, ?string $fallback = null): ?string
    {
        $arguments = $_SERVER['argv'] ?? [];
        $value = is_array($arguments) ? ($arguments[$position] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    private static function requireApiKey(): string
    {
        $apiKey = getenv('PACTMAN_API_KEY');

        if (!is_string($apiKey) || trim($apiKey) === '') {
            Output::error('Set PACTMAN_API_KEY before running this example.');
            Output::error('Load it from your secret manager, or from a .env file excluded from git.');

            exit(1);
        }

        return $apiKey;
    }

    private static function baseUrlOverride(): ?string
    {
        $baseUrl = getenv('PACTMAN_BASE_URL');

        return is_string($baseUrl) && trim($baseUrl) !== '' ? $baseUrl : null;
    }
}
