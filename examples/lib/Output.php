<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Examples;

use Pactman\NonprofitCheckPlus\Model\DataObject;

/**
 * Terminal formatting for the examples.
 *
 * The only thing here worth borrowing is {@see display()}: it prints "the API
 * returned no such field" differently from "the API returned null", because in
 * this API those route differently and a display that flattens them into one
 * blank has thrown away a finding.
 */
final class Output
{
    /** How a field the API did not return is shown. */
    public const NOT_RETURNED = '<not returned>';

    public static function heading(string $text): void
    {
        printf("\n\033[1m%s\033[0m\n%s\n", $text, str_repeat('─', max(8, mb_strlen($text))));
    }

    public static function field(string $label, mixed $value): void
    {
        printf("  %-38s %s\n", $label, self::format($value));
    }

    public static function bullet(string $text): void
    {
        printf("  • %s\n", $text);
    }

    public static function note(string $text): void
    {
        printf("\n\033[2m%s\033[0m\n", $text);
    }

    public static function error(string $text): void
    {
        fwrite(STDERR, $text . "\n");
    }

    /** Prints a field, distinguishing "not returned" from "returned as null". */
    public static function displayField(DataObject $object, string $field, ?string $label = null): void
    {
        self::field($label ?? $field, self::display($object, $field));
    }

    /** The field's value for display, or {@see NOT_RETURNED} when the API omitted it. */
    public static function display(DataObject $object, string $field): mixed
    {
        return $object->has($field) ? $object->get($field) : self::NOT_RETURNED;
    }

    /**
     * A value as text, for interpolation into a message.
     *
     * The API's fields are typed `mixed` on the way out — the wire decides their
     * shape, not this SDK — so casting one straight to `string` is a bet. This
     * takes the value it was given and describes anything that is not a scalar
     * rather than crashing on it.
     */
    public static function text(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            $value === null => '',
            is_scalar($value) => (string) $value,
            default => self::format($value),
        };
    }

    /** Renders a value so `null`, `false` and `0` stay visibly distinct. */
    public static function format(mixed $value): string
    {
        return match (true) {
            $value === null => "\033[2mnull\033[0m",
            $value === true => 'true',
            $value === false => 'false',
            $value === self::NOT_RETURNED => "\033[2m" . self::NOT_RETURNED . "\033[0m",
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }
}
