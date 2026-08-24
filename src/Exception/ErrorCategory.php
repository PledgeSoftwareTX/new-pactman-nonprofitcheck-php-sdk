<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Exception;

/**
 * Stable, machine-comparable error categories.
 *
 * The backing values match the other Pactman SDKs exactly, so a category logged
 * by the PHP client is the same string a Node or Python service would log for
 * the same failure.
 */
enum ErrorCategory: string
{
    /** The client was constructed with unusable options. */
    case Configuration = 'configuration';

    /** Input failed the SDK's local validation; no request was sent. */
    case Validation = 'validation';

    /** HTTP 401. The API key is missing, malformed, revoked or unrecognized. */
    case Authentication = 'authentication';

    /** HTTP 403. The key is valid but lacks access to the resource. */
    case Authorization = 'authorization';

    /** HTTP 400. The API rejected the request. */
    case BadRequest = 'bad_request';

    /** HTTP 404. No matching record. */
    case NotFound = 'not_found';

    /** HTTP 429. Rate limit exceeded. */
    case RateLimit = 'rate_limit';

    /** HTTP 5xx. */
    case Server = 'server';

    /** The request exceeded the configured timeout. */
    case Timeout = 'timeout';

    /** The request never produced an HTTP response. */
    case Network = 'network';

    /** An API error that does not fall into a more specific category. */
    case Api = 'api';
}
