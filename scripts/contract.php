<?php

/**
 * Prints the signature of a JSON response: which fields exist, and what form
 * each value takes. No value from the response is ever printed.
 *
 * Use it to record a baseline, or to see what shape a payload actually has.
 *
 *   php scripts/contract.php response.json
 *   cat response.json | php scripts/contract.php
 *   php scripts/contract.php baseline.json current.json   # diff two of them
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Contract;

/** @return array<string, string> */
function signatureFrom(string $source): array
{
    $raw = $source === '-' ? (string) file_get_contents('php://stdin') : (string) @file_get_contents($source);

    if (trim($raw) === '') {
        fwrite(STDERR, "Nothing to read from {$source}.\n");

        exit(1);
    }

    try {
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        fwrite(STDERR, "{$source} is not valid JSON: {$error->getMessage()}\n");

        exit(1);
    }

    // Already a signature? Then it is a flat map of path to token; pass it through.
    if (is_array($decoded) && $decoded !== [] && !array_is_list($decoded)) {
        $values = array_values($decoded);

        if (array_filter($values, 'is_string') === $values) {
            /** @var array<string, string> $decoded */
            return $decoded;
        }
    }

    return Contract::signatureOf($decoded);
}

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$sources = array_slice($arguments, 1);

if ($sources === []) {
    $sources = ['-'];
}

if (count($sources) === 1) {
    echo json_encode(signatureFrom($sources[0]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

    exit(0);
}

$baseline = signatureFrom($sources[0]);
$current = signatureFrom($sources[1]);

$schema = Contract::schemaDiff($baseline, $current);
$types = Contract::typeDiff($baseline, $current);

printf("%d field(s) appeared or disappeared, %d changed shape\n\n", count($schema), count($types));

foreach ([...$schema, ...$types] as $change) {
    echo '  ', Contract::describe($change), "\n";
}

// A field that disappeared or changed shape breaks callers. An addition does not.
$breaking = array_filter($schema, static fn (array $change): bool => $change['kind'] === 'removed');

exit($breaking === [] && $types === [] ? 0 : 1);
