<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Exception;

/** HTTP 404. */
final class NotFoundException extends ApiException
{
    public function __construct(string $message, ApiErrorInit $init)
    {
        parent::__construct($message, $init, ErrorCategory::NotFound);
    }
}
