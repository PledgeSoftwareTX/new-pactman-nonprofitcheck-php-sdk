<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus;

/**
 * The SDK version, reported in the User-Agent header.
 *
 * Kept in sync with `composer.json` by a unit test rather than a build step, so
 * the value is available without reading installed-package metadata at runtime.
 */
final class Version
{
    /** The release version, reported in the User-Agent header. */
    public const VERSION = '1.0.0';

    /** The Packagist package name, reported in the User-Agent header. */
    public const PACKAGE_NAME = 'pactmandev/nonprofit-check-plus';
}
