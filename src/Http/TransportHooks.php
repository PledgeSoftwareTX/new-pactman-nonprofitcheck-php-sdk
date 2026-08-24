<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Http;

use Closure;

/**
 * Seam for injecting a clock into tests, so retry and throttle tests run
 * instantly instead of actually waiting.
 *
 * Not part of the public API and not covered by semantic versioning; do not
 * depend on it.
 */
final class TransportHooks
{
    /**
     * @param (Closure(float): void)|null $sleep     Called instead of sleeping, with a delay in seconds.
     * @param (Closure(): float)|null     $random    Returns a value in `[0, 1)`. Used for backoff jitter.
     * @param (Closure(): float)|null     $monotonic Returns a monotonically increasing time in seconds. Used for throttling.
     */
    public function __construct(
        public readonly ?Closure $sleep = null,
        public readonly ?Closure $random = null,
        public readonly ?Closure $monotonic = null,
    ) {
    }
}
