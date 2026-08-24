<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus;

use Pactman\NonprofitCheckPlus\Exception\ValidationException;
use Pactman\NonprofitCheckPlus\Exception\ValidationIssue;

/**
 * EIN normalization and validation.
 *
 * Formatting validation only. Passing these checks says nothing about whether an
 * organization is tax-exempt, in good standing, or eligible for anything — only
 * that the value is shaped like an EIN. No IRS prefix rules are applied.
 */
final class Ein
{
    /** Number of digits in an EIN. */
    public const LENGTH = 9;

    /**
     * Accepted input shapes: nine digits, optionally with the conventional
     * hyphen after the two-digit prefix. Surrounding whitespace is ignored.
     */
    private const PATTERN = '/^\d{2}-?\d{7}$/';

    /**
     * Normalizes an EIN to the nine-digit form the API expects.
     *
     * `"41-1787097"` and `"411787097"` both normalize to `"411787097"`.
     *
     * @throws ValidationException if the value is not shaped like an EIN.
     */
    public static function normalize(mixed $value, ?int $index = null): string
    {
        $issue = self::issue($value, $index);

        if ($issue !== null) {
            throw new ValidationException($issue->message, [$issue]);
        }

        /** @var string $value */
        return self::clean($value);
    }

    /**
     * Normalizes a collection of EINs, reporting every failure at once.
     *
     * Duplicates are preserved and order is retained; see
     * {@see NonprofitsResource::checkBulk()} for the optional `dedupe` behaviour.
     *
     * **An integer is refused, deliberately.** `042103594` as an `int` is
     * `42103594` — the leading zero is gone and the value is a different EIN.
     * Watch for this when your EINs arrive as PHP array *keys*, which the engine
     * canonicalizes to `int` when they look like one: pass
     * `array_column($rows, 'ein')` rather than `array_keys($rows)`.
     *
     * @param iterable<mixed> $values
     *
     * @return list<string>
     *
     * @throws ValidationException if any item is not shaped like an EIN. The
     *     exception's `issues` identify the failing item by index and original value.
     */
    public static function normalizeMany(iterable $values): array
    {
        $issues = [];
        $normalized = [];
        $count = 0;

        foreach ($values as $value) {
            $issue = self::issue($value, $count);
            ++$count;

            if ($issue !== null) {
                $issues[] = $issue;

                continue;
            }

            /** @var string $value */
            $normalized[] = self::clean($value);
        }

        if ($issues !== []) {
            $positions = implode(', ', array_map(
                static fn (ValidationIssue $issue): string => (string) $issue->index,
                $issues,
            ));

            throw new ValidationException(
                sprintf(
                    '%d of %d EINs are invalid (at index %s). No request was sent.',
                    count($issues),
                    $count,
                    $positions,
                ),
                $issues,
            );
        }

        return $normalized;
    }

    /** True when `$value` is shaped like an EIN. Never throws. */
    public static function isValid(mixed $value): bool
    {
        return self::issue($value) === null;
    }

    /** Strips a value that {@see issue()} has already confirmed is EIN-shaped. */
    private static function clean(string $value): string
    {
        return str_replace('-', '', trim($value));
    }

    private static function issue(mixed $value, ?int $index = null): ?ValidationIssue
    {
        $at = $index === null ? '' : " at index {$index}";

        if ($value === null) {
            return new ValidationIssue("EIN{$at} is required.", $index, $value);
        }

        if (!is_string($value)) {
            return new ValidationIssue(
                sprintf('EIN%s must be a string, received %s.', $at, get_debug_type($value)),
                $index,
                $value,
            );
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return new ValidationIssue("EIN{$at} is empty.", $index, $value);
        }

        if (preg_match(self::PATTERN, $trimmed) !== 1) {
            return new ValidationIssue(
                sprintf(
                    'EIN%s must be %d digits, optionally hyphenated as XX-XXXXXXX. Received "%s".',
                    $at,
                    self::LENGTH,
                    $trimmed,
                ),
                $index,
                $value,
            );
        }

        return null;
    }
}
