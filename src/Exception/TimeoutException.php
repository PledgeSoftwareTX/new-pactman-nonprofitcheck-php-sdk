<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Exception;

use Throwable;

/** The request exceeded the configured timeout. */
final class TimeoutException extends PactmanException
{
    public function __construct(
        string $message,
        /** The timeout that elapsed, in seconds. */
        public readonly float $timeout,
        /** How many attempts were made before this error was surfaced. */
        public readonly int $attempts = 1,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, ErrorCategory::Timeout, ErrorOrigin::Local, $previous);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [...parent::toArray(), 'timeout' => $this->timeout, 'attempts' => $this->attempts];
    }
}
