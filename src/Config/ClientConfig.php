<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Config;

use JsonSerializable;
use Pactman\NonprofitCheckPlus\Environment;

/**
 * Fully-resolved client configuration.
 *
 * The API key is deliberately not a property of this object, so no diagnostic
 * that reaches for the configuration can print the credential.
 */
final class ClientConfig implements JsonSerializable
{
    /** Default timeout per attempt, in seconds. */
    public const DEFAULT_TIMEOUT = 30.0;

    /**
     * @param string                $baseUrl        The host every request is sent to.
     * @param Environment|null      $environment    The named environment, or `null` when an explicit `baseUrl` was given.
     * @param float                 $timeout        Timeout per attempt, in seconds.
     * @param RetryOptions          $retry          Retry policy.
     * @param float|null            $maxRequestsPerSecond Optional client-side outbound ceiling.
     * @param array<string, string> $defaultHeaders Extra headers sent with every request.
     * @param string                $userAgent      The `User-Agent` this client reports.
     */
    public function __construct(
        public readonly string $baseUrl,
        public readonly ?Environment $environment,
        public readonly float $timeout,
        public readonly RetryOptions $retry,
        public readonly ?float $maxRequestsPerSecond,
        public readonly array $defaultHeaders,
        public readonly string $userAgent,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'environment' => $this->environment?->value,
            'timeout' => $this->timeout,
            'retry' => $this->retry->toArray(),
            'maxRequestsPerSecond' => $this->maxRequestsPerSecond,
            'userAgent' => $this->userAgent,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
