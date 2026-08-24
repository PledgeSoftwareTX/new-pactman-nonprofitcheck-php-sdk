<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Exception;

/** The client options were unusable — a missing API key, a malformed base URL. */
final class ConfigurationException extends PactmanException
{
    public function __construct(string $message)
    {
        parent::__construct($message, ErrorCategory::Configuration, ErrorOrigin::Local);
    }
}
