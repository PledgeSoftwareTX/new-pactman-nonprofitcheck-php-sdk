<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus;

use JsonSerializable;
use Pactman\NonprofitCheckPlus\Config\ClientConfig;
use Pactman\NonprofitCheckPlus\Config\ConfigResolver;
use Pactman\NonprofitCheckPlus\Config\RetryOptions;
use Pactman\NonprofitCheckPlus\Exception\ConfigurationException;
use Pactman\NonprofitCheckPlus\Http\CurlHttpClient;
use Pactman\NonprofitCheckPlus\Http\HttpClientInterface;
use Pactman\NonprofitCheckPlus\Http\Transport;
use Pactman\NonprofitCheckPlus\Http\TransportHooks;
use Stringable;

/**
 * The Pactman Nonprofit Check Plus client.
 *
 * Server-side use only. The API key is a private credential; do not construct
 * this client anywhere its configuration is shipped to an end user.
 *
 * ```php
 * $client = new PactmanClient(apiKey: getenv('PACTMAN_API_KEY'));
 * $result = $client->nonprofits->check('41-1787097');
 * ```
 *
 * Build one client per process and share it. Each instance carries its own
 * connection and throttle state, so constructing one per request throws both
 * away.
 */
final class PactmanClient implements JsonSerializable, Stringable
{
    /** Nonprofit lookups. */
    public readonly NonprofitsResource $nonprofits;

    private readonly ClientConfig $config;

    /**
     * @param string|null $apiKey Your Pactman API key. Load it from the environment or a secret
     *     manager; never commit it, and never ship it to an end user.
     * @param Environment|string|null $environment Named Pactman environment. Defaults to production.
     * @param string|null $baseUrl Explicit base URL, for a mock server, a proxy, or a host Pactman
     *     has given you directly. Overrides `environment` when set.
     * @param float|null $timeout Timeout per attempt, in seconds. Defaults to 30.
     * @param RetryOptions|array<string, mixed>|bool|null $retry Retry policy, or `false` to disable
     *     retrying entirely.
     * @param float|null $maxRequestsPerSecond Optional client-side ceiling on outbound requests per
     *     second. Off by default; the server's limits are authoritative and may change.
     * @param array<string, string> $defaultHeaders Extra headers sent with every request. Cannot
     *     override `Authorization`.
     * @param HttpClientInterface|null $httpClient Where to send through. Defaults to the bundled
     *     {@see CurlHttpClient}; wrap a PSR-18 client with
     *     {@see \Pactman\NonprofitCheckPlus\Http\Psr18HttpClient} to use your own stack.
     * @param TransportHooks|null $hooks Internal seam for injecting a clock in tests. Not covered
     *     by semantic versioning; do not depend on it.
     *
     * @throws ConfigurationException for a missing or blank API key, an unknown environment, a
     *     malformed base URL, or a nonsensical numeric option.
     */
    public function __construct(
        #[\SensitiveParameter]
        ?string $apiKey = null,
        Environment|string|null $environment = null,
        ?string $baseUrl = null,
        ?float $timeout = null,
        RetryOptions|array|bool|null $retry = null,
        ?float $maxRequestsPerSecond = null,
        array $defaultHeaders = [],
        ?HttpClientInterface $httpClient = null,
        ?TransportHooks $hooks = null,
    ) {
        $key = ConfigResolver::apiKey($apiKey);

        $this->config = ConfigResolver::config(
            environment: $environment,
            baseUrl: $baseUrl,
            timeout: $timeout,
            retry: $retry,
            maxRequestsPerSecond: $maxRequestsPerSecond,
            defaultHeaders: $defaultHeaders,
        );

        // The key lives only inside this closure. It is never a property of the
        // client or the transport, so print_r(), var_dump() and json_encode()
        // cannot reach it however deeply they walk.
        $credential = static fn (): string => 'Bearer ' . $key;

        $this->nonprofits = new NonprofitsResource(new Transport(
            $credential,
            $this->config,
            $httpClient ?? new CurlHttpClient(),
            $hooks,
        ));
    }

    /** The resolved base URL every request is sent to. */
    public function baseUrl(): string
    {
        return $this->config->baseUrl;
    }

    /** The named environment in use, or `null` when an explicit `baseUrl` was given. */
    public function environment(): ?Environment
    {
        return $this->config->environment;
    }

    /** The resolved timeout per attempt, in seconds. */
    public function timeout(): float
    {
        return $this->config->timeout;
    }

    /** The resolved retry policy. */
    public function retry(): RetryOptions
    {
        return $this->config->retry;
    }

    /**
     * A redacted view of the configuration.
     *
     * The API key is not a property of this object and never appears here, in
     * `json_encode()`, `print_r()`, `var_dump()` or `(string) $client`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [...$this->config->toArray(), 'apiKey' => '[redacted]'];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return sprintf('PactmanClient(%s)', $this->config->baseUrl);
    }
}
