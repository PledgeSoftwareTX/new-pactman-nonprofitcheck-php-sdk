<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus;

/**
 * Named Pactman environments that are supported for public SDK use.
 *
 * Endpoint hosts are declared here and nowhere else. Nothing else in this
 * package contains a literal Pactman host.
 *
 * Pactman's QA, SIT and sandbox hosts are internal and are deliberately not
 * exposed. Point at one with the `baseUrl` option if you have been given access
 * to it.
 */
enum Environment: string
{
    /** The Pactman production API. */
    case Production = 'production';

    /** The environment used when none is supplied. */
    public const DEFAULT = self::Production;

    /** The base URL every request in this environment is sent to. */
    public function baseUrl(): string
    {
        return match ($this) {
            self::Production => 'https://entities.pactman.org',
        };
    }

    /**
     * Every environment name the SDK understands.
     *
     * @return list<self>
     */
    public static function supported(): array
    {
        return self::cases();
    }

    /** The supported names, for an error message. */
    public static function supportedNames(): string
    {
        return implode(', ', array_map(static fn (self $case): string => $case->value, self::cases()));
    }
}
