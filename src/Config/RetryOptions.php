<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Config;

use JsonSerializable;
use Pactman\NonprofitCheckPlus\Exception\ConfigurationException;

/**
 * Retry policy. Applied per request, on top of the overall timeout.
 *
 * Every parameter has the SDK default, so `new RetryOptions(maxRetries: 5)` is a
 * complete policy rather than a patch. To adjust a client's policy for one
 * request without restating it, pass an array to a method's `retry` argument —
 * those keys are merged onto the policy in force.
 */
final class RetryOptions implements JsonSerializable
{
    /** Statuses that are never retried, regardless of `retryableStatuses`. */
    private const NEVER_RETRY_STATUSES = [400, 401, 403, 404];

    /** @var list<int> */
    public readonly array $retryableStatuses;

    /**
     * @param int   $maxRetries   Retries after the first attempt. `0` disables retrying.
     * @param float $initialDelay Delay before the first retry, in seconds. Subsequent delays grow
     *     by `backoffFactor` and are randomized when `jitter` is on.
     * @param float $maxDelay     Ceiling for a single backoff delay, in seconds. A server-supplied
     *     `Retry-After` is honored even when it exceeds this.
     * @param bool  $jitter       Randomize each delay across `[0, computed]` (full jitter) so that
     *     clients failing together do not retry in lockstep.
     * @param array<array-key, mixed> $retryableStatuses HTTP statuses worth retrying. Authentication,
     *     authorization, validation and not-found responses are never retried, whatever this
     *     contains.
     * @param bool  $respectRetryAfter Wait for the server's `Retry-After` before falling back to
     *     backoff.
     *
     * @throws ConfigurationException for a nonsensical value.
     */
    public function __construct(
        public readonly int $maxRetries = 2,
        public readonly float $initialDelay = 0.5,
        public readonly float $maxDelay = 8.0,
        public readonly float $backoffFactor = 2.0,
        public readonly bool $jitter = true,
        array $retryableStatuses = [429, 500, 502, 503, 504],
        public readonly bool $respectRetryAfter = true,
    ) {
        if ($maxRetries < 0) {
            throw new ConfigurationException('`maxRetries` must be an integer of 0 or more.');
        }

        if (!is_finite($initialDelay) || $initialDelay < 0) {
            throw new ConfigurationException('`initialDelay` must be 0 or more.');
        }

        if (!is_finite($maxDelay) || $maxDelay < 0) {
            throw new ConfigurationException('`maxDelay` must be 0 or more.');
        }

        if (!is_finite($backoffFactor) || $backoffFactor < 1) {
            throw new ConfigurationException('`backoffFactor` must be 1 or more.');
        }

        $statuses = [];

        foreach ($retryableStatuses as $status) {
            if (!is_int($status)) {
                throw new ConfigurationException(sprintf(
                    '`retryableStatuses` must contain only integers, found %s.',
                    get_debug_type($status),
                ));
            }

            $statuses[] = $status;
        }

        $this->retryableStatuses = $statuses;
    }

    /**
     * A copy with `$overrides` applied on top.
     *
     * @param array<string, mixed> $overrides
     *
     * @throws ConfigurationException for an unknown key.
     */
    public function with(array $overrides): self
    {
        $known = [
            'maxRetries', 'initialDelay', 'maxDelay', 'backoffFactor',
            'jitter', 'retryableStatuses', 'respectRetryAfter',
        ];

        $unknown = array_diff(array_keys($overrides), $known);

        if ($unknown !== []) {
            sort($unknown);

            throw new ConfigurationException(sprintf(
                'Unknown retry option(s): %s. Valid keys: %s.',
                implode(', ', $unknown),
                implode(', ', $known),
            ));
        }

        $merged = [...$this->toArray(), ...$overrides];

        return new self(
            maxRetries: self::intOption($merged['maxRetries'], 'maxRetries'),
            initialDelay: self::floatOption($merged['initialDelay'], 'initialDelay'),
            maxDelay: self::floatOption($merged['maxDelay'], 'maxDelay'),
            backoffFactor: self::floatOption($merged['backoffFactor'], 'backoffFactor'),
            jitter: self::boolOption($merged['jitter'], 'jitter'),
            retryableStatuses: self::listOption($merged['retryableStatuses'], 'retryableStatuses'),
            respectRetryAfter: self::boolOption($merged['respectRetryAfter'], 'respectRetryAfter'),
        );
    }

    private static function intOption(mixed $value, string $name): int
    {
        if (!is_int($value)) {
            throw new ConfigurationException(sprintf(
                '`%s` must be an integer, received %s.',
                $name,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    private static function floatOption(mixed $value, string $name): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new ConfigurationException(sprintf(
                '`%s` must be a number, received %s.',
                $name,
                get_debug_type($value),
            ));
        }

        return (float) $value;
    }

    private static function boolOption(mixed $value, string $name): bool
    {
        if (!is_bool($value)) {
            throw new ConfigurationException(sprintf(
                '`%s` must be true or false, received %s.',
                $name,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /** @return list<int> */
    private static function listOption(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new ConfigurationException(sprintf(
                '`%s` must be a list of integers, received %s.',
                $name,
                get_debug_type($value),
            ));
        }

        // The constructor rejects a non-integer member; this only fixes the shape.
        /** @var list<int> $statuses */
        $statuses = array_values($value);

        return $statuses;
    }

    /**
     * Applies a `retry` argument to the policy currently in force.
     *
     * `null` keeps the policy, `false` disables retrying, a {@see RetryOptions}
     * replaces the policy outright, and an array merges its keys onto it.
     *
     * @param self|array<string, mixed>|bool|null $override
     *
     * @throws ConfigurationException for an unusable argument.
     */
    public static function resolve(self $current, self|array|bool|null $override): self
    {
        if ($override === null || $override === true) {
            return $current;
        }

        if ($override === false) {
            return $current->with(['maxRetries' => 0]);
        }

        if ($override instanceof self) {
            return $override;
        }

        return $current->with($override);
    }

    /** True when a status may be retried under this policy. */
    public function isRetryableStatus(int $status): bool
    {
        if (in_array($status, self::NEVER_RETRY_STATUSES, true)) {
            return false;
        }

        return in_array($status, $this->retryableStatuses, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'maxRetries' => $this->maxRetries,
            'initialDelay' => $this->initialDelay,
            'maxDelay' => $this->maxDelay,
            'backoffFactor' => $this->backoffFactor,
            'jitter' => $this->jitter,
            'retryableStatuses' => $this->retryableStatuses,
            'respectRetryAfter' => $this->respectRetryAfter,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
