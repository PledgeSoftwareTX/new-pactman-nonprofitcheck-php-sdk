<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Dev;

/**
 * The `.env` beside the package, and the organizations every live script reads.
 *
 * Kept here rather than in one script so `smoke-live.php` and
 * `record-baseline.php` read the same file the same way and talk about the same
 * subjects. The two have to agree on which deployment and which organizations
 * they are describing — a signature collapses every element of `data[]` onto one
 * path, so a recording made from one batch and a run made from another disagree
 * wherever the two sets of organizations differ. A second copy of this parser, or
 * a second list of EINs, is how they would stop agreeing.
 */
final class Env
{
    /** The variable the credential is read from, in the environment or in `.env`. */
    public const API_KEY_ENV = 'PACTMAN_API_KEY';

    /**
     * The organizations these scripts check.
     *
     * A primary subject with a record, a second one to give the bulk order and
     * duplicate probes something to work with, and a well-formed EIN with no
     * record for the not-found and partial-success paths. The first two are
     * reachable on a free-tier key, so a free key gets as far as a free key can.
     *
     * These are also the subjects `src/response-baseline.json` describes.
     * Changing one means re-recording it.
     */
    public const EIN = '996589560';

    /** @var list<string> */
    public const BULK_EINS = ['996589560', '996202676'];

    public const MISSING_EIN = '999999999';

    /**
     * How many of the bulk subjects a run actually sends.
     *
     * The bulk probes read the first few and the rest would cost quota unspent —
     * but the recorder has to send the same batch the smoke run does. Same
     * number, same subjects, or the comparison reports the batch size as drift.
     */
    public const BULK_PROBE_LIMIT = 3;

    /** Assignment lines a `.env` is allowed to carry, `export` prefix included. */
    private const ASSIGNMENT = '/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/';

    /**
     * Loads the `.env` beside the package, so the key and any standing overrides
     * live in a file rather than in the shell for every run. The file is
     * gitignored.
     *
     * A variable already in the environment wins: exporting one for a single run
     * must not be silently overridden by a file someone set up months ago.
     *
     * @return array{path: string, names: list<string>}|null
     */
    public static function loadEnvFile(?string $path = null): ?array
    {
        $path ??= \dirname(__DIR__, 2) . '/.env';

        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $names = [];

        // Both line endings: a .env saved on Windows ends its lines with CRLF,
        // and a trailing CR left on the value would become part of the key.
        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            if (preg_match(self::ASSIGNMENT, $line, $matched) !== 1) {
                continue;
            }

            [, $name, $rawValue] = $matched;

            if (self::get($name) !== null) {
                continue;
            }

            $value = trim($rawValue);

            // A quoted value keeps its inner whitespace; the quotes are syntax.
            if (\strlen($value) >= 2 && $value[0] === $value[-1] && ($value[0] === '"' || $value[0] === "'")) {
                $value = substr($value, 1, -1);
            }

            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $names[] = $name;
        }

        return ['path' => $path, 'names' => $names];
    }

    /**
     * One environment variable, or null when it is unset or empty.
     *
     * `getenv()` returns false for unset and an empty string for set-but-empty,
     * and a key that is present but empty is not a key. Both are nothing here.
     */
    public static function get(string $name): ?string
    {
        $value = getenv($name);

        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
