<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Exception;

use Throwable;

/** The request produced no HTTP response. */
final class NetworkException extends PactmanException
{
    public function __construct(
        string $message,
        /** How many attempts were made before this error was surfaced. */
        public readonly int $attempts = 1,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, ErrorCategory::Network, ErrorOrigin::Local, $previous);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [...parent::toArray(), 'attempts' => $this->attempts];
    }
}
