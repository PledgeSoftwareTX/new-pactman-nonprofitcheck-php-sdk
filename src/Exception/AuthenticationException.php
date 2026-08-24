<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Exception;

/** HTTP 401. */
final class AuthenticationException extends ApiException
{
    public function __construct(string $message, ApiErrorInit $init)
    {
        parent::__construct($message, $init, ErrorCategory::Authentication);
    }
}
