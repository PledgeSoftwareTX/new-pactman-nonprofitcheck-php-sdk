<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Config;

use Pactman\NonprofitCheckPlus\Environment;
use Pactman\NonprofitCheckPlus\Exception\ConfigurationException;
use Pactman\NonprofitCheckPlus\Version;

/**
 * Validates and resolves constructor options.
 *
 * Everything unusable is rejected here, at construction, before any network call
 * is attempted.
 */
final class ConfigResolver
{
    /**
     * @throws ConfigurationException for a missing or blank API key.
     */
    public static function apiKey(?string $apiKey): string
    {
        if ($apiKey === null) {
            throw new ConfigurationException(
                'A Pactman API key is required. Pass `apiKey`, for example from getenv(\'PACTMAN_API_KEY\').',
            );
        }

        if (trim($apiKey) === '') {
            throw new ConfigurationException(
                'The Pactman API key is empty. Check that the environment variable holding it is set.',
            );
        }

        return trim($apiKey);
    }

    /**
     * @param array<string, string> $defaultHeaders
     * @param RetryOptions|array<string, mixed>|bool|null $retry
     *
     * @throws ConfigurationException for an unknown environment, a malformed base
     *     URL, or a nonsensical numeric option.
     */
    public static function config(
        Environment|string|null $environment = null,
        ?string $baseUrl = null,
        ?float $timeout = null,
        RetryOptions|array|bool|null $retry = null,
        ?float $maxRequestsPerSecond = null,
        array $defaultHeaders = [],
    ): ClientConfig {
        return new ClientConfig(
            baseUrl: $baseUrl !== null
                ? self::validateBaseUrl($baseUrl)
                : self::coerceEnvironment($environment)->baseUrl(),
            environment: $baseUrl !== null ? null : self::coerceEnvironment($environment),
            timeout: self::resolveTimeout($timeout),
            retry: RetryOptions::resolve(new RetryOptions(), $retry),
            maxRequestsPerSecond: self::resolveRequestsPerSecond($maxRequestsPerSecond),
            defaultHeaders: $defaultHeaders,
            userAgent: self::buildUserAgent(),
        );
    }

    /** `pactmandev-nonprofit-check-plus/<version> (php/<version>; <os>)` */
    public static function buildUserAgent(): string
    {
        return sprintf(
            '%s/%s (php/%s; %s)',
            str_replace('/', '-', Version::PACKAGE_NAME),
            Version::VERSION,
            PHP_VERSION,
            PHP_OS_FAMILY,
        );
    }

    private static function coerceEnvironment(Environment|string|null $environment): Environment
    {
        if ($environment === null) {
            return Environment::DEFAULT;
        }

        if ($environment instanceof Environment) {
            return $environment;
        }

        $resolved = Environment::tryFrom($environment);

        if ($resolved === null) {
            throw new ConfigurationException(sprintf(
                'Unknown environment "%s". Supported: %s. Use `baseUrl` to target a host that is not a named environment.',
                $environment,
                Environment::supportedNames(),
            ));
        }

        return $resolved;
    }

    private static function validateBaseUrl(string $baseUrl): string
    {
        $trimmed = trim($baseUrl);

        if ($trimmed === '') {
            throw new ConfigurationException('`baseUrl` must be a non-empty URL string.');
        }

        $parts = parse_url($trimmed);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new ConfigurationException(sprintf(
                '`baseUrl` is not a valid URL: "%s". Expected something like https://entities.pactman.org.',
                $baseUrl,
            ));
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new ConfigurationException(sprintf(
                '`baseUrl` must use http or https, received "%s".',
                $scheme,
            ));
        }

        $authority = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = rtrim($parts['path'] ?? '', '/');

        return $scheme . '://' . $authority . $path;
    }

    private static function resolveTimeout(?float $timeout): float
    {
        if ($timeout === null) {
            return ClientConfig::DEFAULT_TIMEOUT;
        }

        if (!is_finite($timeout) || $timeout <= 0) {
            throw new ConfigurationException(
                '`timeout` must be a number of seconds greater than zero. There is no way to disable the timeout.',
            );
        }

        return $timeout;
    }

    private static function resolveRequestsPerSecond(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (!is_finite($value) || $value <= 0) {
            throw new ConfigurationException(
                '`maxRequestsPerSecond` must be a number greater than zero, or omitted.',
            );
        }

        return $value;
    }
}
