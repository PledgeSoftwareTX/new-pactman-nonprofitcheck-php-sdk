<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus;

/** The API surface this SDK wraps, declared once. */
final class Endpoints
{
    /** Path of the single-check endpoint. `{ein}` is replaced with a normalized EIN. */
    public const SINGLE_CHECK_PATH = '/api/entities/nonprofitcheck/v1/us/ein/{ein}';

    /** Path of the bulk-check endpoint. */
    public const BULK_CHECK_PATH = '/api/entities/nonprofitcheckbulk/v1/us/eins';

    /**
     * Maximum number of EINs the API accepts in one bulk request.
     *
     * This mirrors the server-side limit. It is declared once, here.
     */
    public const MAX_BULK_EINS = 50;
}
