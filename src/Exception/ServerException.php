<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Exception;

/** HTTP 5xx. */
final class ServerException extends ApiException
{
    public function __construct(string $message, ApiErrorInit $init)
    {
        parent::__construct($message, $init, ErrorCategory::Server);
    }
}
