<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Exception;

/** Whether an error was raised locally or derived from an API response. */
enum ErrorOrigin: string
{
    case Local = 'local';
    case Api = 'api';
}
