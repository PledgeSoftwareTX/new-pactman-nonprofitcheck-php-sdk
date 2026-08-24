<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Exception;

/** HTTP 400. The API rejected the request; see `$error->apiErrors` for the reasons. */
final class BadRequestException extends ApiException
{
    public function __construct(string $message, ApiErrorInit $init)
    {
        parent::__construct($message, $init, ErrorCategory::BadRequest);
    }
}
