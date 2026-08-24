<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Http;

use RuntimeException;
use Throwable;

/**
 * A request that produced no HTTP response.
 *
 * Internal to the transport layer: {@see Transport} classifies it into the
 * public {@see \Pactman\NonprofitCheckPlus\Exception\TimeoutException} or
 * {@see \Pactman\NonprofitCheckPlus\Exception\NetworkException} before anything
 * reaches a caller. An {@see HttpClientInterface} implementation raises it.
 */
final class TransportException extends RuntimeException
{
    public function __construct(
        string $message,
        /** True when the failure was the deadline expiring rather than the connection failing. */
        public readonly bool $isTimeout = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
