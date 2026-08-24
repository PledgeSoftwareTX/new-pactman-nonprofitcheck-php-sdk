<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Examples;

use DateTimeImmutable;

/**
 * Parsing for the date format this API returns.
 *
 * Every timestamp arrives as `M/DD/YYYY h:mm:ss AM`. Parse it; never reformat it
 * in place, and never store the reformatted value as if it were what the API
 * said. A value that will not parse is reported as unparseable rather than
 * silently treated as absent — the difference matters when the date is the
 * evidence for a decision.
 */
final class ApiDate
{
    private const FORMAT = 'n/d/Y g:i:s A';

    /** Parses an API timestamp, or returns `null` when there is nothing to parse. */
    public static function parse(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(self::FORMAT, trim($value));

        if ($parsed !== false) {
            return $parsed;
        }

        // A format the API changed or a value this example has not seen. Fall
        // back to PHP's own parser rather than reporting a date as missing.
        try {
            return new DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return null;
        }
    }

    /** Whole days since `$value`, or `null` when it could not be parsed. */
    public static function ageInDays(mixed $value, ?DateTimeImmutable $now = null): ?int
    {
        $parsed = self::parse($value);

        if ($parsed === null) {
            return null;
        }

        $days = ($now ?? new DateTimeImmutable())->diff($parsed)->days;

        // `days` is only populated on an interval that came from diff(), and is
        // `false` otherwise. Report "unknown" rather than a bogus zero.
        return $days === false ? null : $days;
    }

    /** An ISO-8601 stamp for "when we looked", to store beside a verification record. */
    public static function checkedAt(): string
    {
        return (new DateTimeImmutable())->format(DATE_ATOM);
    }
}
