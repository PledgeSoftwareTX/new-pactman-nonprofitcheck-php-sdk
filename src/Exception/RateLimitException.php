<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Exception;

/** HTTP 429. `$error->retryAfterSeconds` carries the server's `Retry-After` when sent. */
final class RateLimitException extends ApiException
{
    public function __construct(string $message, ApiErrorInit $init)
    {
        parent::__construct($message, $init, ErrorCategory::RateLimit);
    }
}
