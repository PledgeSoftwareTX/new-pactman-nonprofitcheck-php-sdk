#!/usr/bin/env php
<?php

/**
 * Records the shape of the production API into `src/response-baseline.json`.
 *
 * `src/response-contract.json` says what this package *promises* a response looks
 * like. This file says what production *actually returned*, once, on a day
 * someone looked. They answer different questions and both are needed: the
 * contract catches the API drifting away from the documented model, the baseline
 * catches the API drifting at all — including in the fields the contract leaves
 * as a bare `string`, where a promise is too loose to notice anything.
 *
 * The recording is committed, so it is the same for everyone and a change to it
 * shows up in review as what it is: production moved, and someone accepted it.
 * That is also why writing it is a deliberate command rather than something a
 * smoke run does on the side. A baseline that rewrites itself on every run agrees
 * with the API by construction and can never fail.
 *
 *   composer baseline:record
 *   php scripts/record-baseline.php [--allow-any-target] [--dry-run]
 *
 * The key and the target come from the environment or `.env`, and the subjects
 * from `scripts/lib/Env.php`, exactly as `smoke-live.php` reads them. Recording
 * spends billable checks: one single lookup and one bulk lookup against the key
 * you point it at.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Contract;
use Pactman\NonprofitCheckPlus\Dev\Env;
use Pactman\NonprofitCheckPlus\Ein;
use Pactman\NonprofitCheckPlus\Environment;
use Pactman\NonprofitCheckPlus\Exception\PactmanException;
use Pactman\NonprofitCheckPlus\Model\PactmanResult;
use Pactman\NonprofitCheckPlus\PactmanClient;
use Pactman\NonprofitCheckPlus\Version;

const BASELINE_PATH = __DIR__ . '/../src/response-baseline.json';

const NOTE = 'The shape production returned when this was recorded: path, JSON type and value '
    . 'format, never a value. Committed, so every run of scripts/smoke-live.php is held against '
    . 'the same recording — any path added or removed, and any token that changed, fails there. '
    . 'Rewrite it with `composer baseline:record` only when production has moved and the move is '
    . 'intended.';

Env::loadEnvFile();

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$allowAnyTarget = \in_array('--allow-any-target', $arguments, true);
$dryRun = \in_array('--dry-run', $arguments, true);

$apiKey = Env::get(Env::API_KEY_ENV);

if ($apiKey === null) {
    fwrite(STDERR, sprintf(
        "No API key. Put %s in .env, or export it, and run this again.\n",
        Env::API_KEY_ENV,
    ));

    exit(2);
}

/** Replaces the credential wherever it surfaces. Applied to everything printed. */
$redact = static fn (string $value): string => str_replace($apiKey, '[redacted]', $value);

$say = static function (string $text = '') use ($redact): void {
    echo $redact($text), "\n";
};

$normalizeUrl = static fn (string $value): string => strtolower(rtrim($value, '/'));

$productionUrl = Environment::DEFAULT->baseUrl();
$baseUrl = Env::get('PACTMAN_BASE_URL') ?? $productionUrl;

// The baseline every run is held against has to come from the deployment those
// runs are about. A recording made against a sandbox would quietly turn the
// sandbox into the standard, and nothing downstream could tell.
if ($normalizeUrl($baseUrl) !== $normalizeUrl($productionUrl) && !$allowAnyTarget) {
    fwrite(STDERR, sprintf(
        "Refusing to record from %s.\n"
            . "The committed baseline describes production (%s); recording it from anywhere else "
            . "makes that deployment the standard for everyone.\n"
            . "Unset PACTMAN_BASE_URL, or pass --allow-any-target if you mean it.\n",
        $baseUrl,
        $productionUrl,
    ));

    exit(2);
}

try {
    $ein = Ein::normalize(Env::EIN);
    $missingEin = Ein::normalize(Env::MISSING_EIN);
    // The same batch `smoke-live.php` sends, so the two signatures describe the
    // same set of organizations rather than differing by batch size.
    $bulkEins = Ein::normalizeMany(\array_slice(Env::BULK_EINS, 0, Env::BULK_PROBE_LIMIT));
} catch (PactmanException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");

    exit(2);
}

$client = new PactmanClient(apiKey: $apiKey, baseUrl: $baseUrl, timeout: 20.0, retry: ['maxRetries' => 2]);

/**
 * The batches to try, in the order `smoke-live.php` would arrive at them.
 *
 * That run keeps the first bulk response any of its probes returns, and the
 * probes go in a fixed order: the partial-success batch first, because its
 * envelope is the only one carrying the item-level `errors` a batch with a miss
 * comes back with, then the duplicate probe, which is what a key whose bulk EINs
 * are allowlisted falls back to — such a key refuses the whole batch the moment
 * an EIN with no record is in it.
 *
 * Recording a batch the run will never send would disagree with every run on
 * batch composition alone, and report the difference as the API moving.
 *
 * @var list<list<string>> $bulkAttempts
 */
$bulkAttempts = [];

if ($bulkEins !== []) {
    $bulkAttempts[] = [...$bulkEins, $missingEin];
}

if (\count($bulkEins) >= 2) {
    $bulkAttempts[] = [$bulkEins[1], $bulkEins[0], $bulkEins[1]];
}

$say(sprintf('Target        %s', $baseUrl));
$say(sprintf(
    'Subjects      %s · bulk %s',
    $ein,
    $bulkEins === [] ? 'none' : implode(', ', $bulkEins),
));
$say(sprintf('Cost          up to %d billable request(s)', 1 + \count($bulkAttempts)));
$say();

/**
 * Runs one lookup and reduces it to a signature, or reports why it could not.
 *
 * @param callable(): PactmanResult $call
 *
 * @return array<string, string>|null
 */
$record = static function (string $label, callable $call) use ($say, $redact): ?array {
    try {
        $result = $call();
    } catch (\Throwable $error) {
        $say(sprintf('  %s failed — %s', str_pad($label, 8), $redact($error->getMessage())));

        return null;
    }

    // A non-JSON body leaves the raw text in place rather than an envelope, and
    // there is no shape in a string to record. The instanceof is what tells a
    // static analyser what a closure handed in as a value returns; it costs
    // nothing and says at the one place it matters what came back.
    if (!$result instanceof PactmanResult || !\is_array($result->raw)) {
        $say(sprintf('  %s no response body to record', str_pad($label, 8)));

        return null;
    }

    $signature = Contract::signatureOf($result->raw);

    $say(sprintf('  %s %d paths', str_pad($label, 8), \count($signature)));

    return $signature;
};

$single = $record('single', static fn () => $client->nonprofits->check($ein));

$bulk = null;

foreach ($bulkAttempts as $attempt) {
    $bulk = $record('bulk', static fn () => $client->nonprofits->checkBulk($attempt));

    if ($bulk !== null) {
        break;
    }
}

if ($single === null && $bulk === null) {
    fwrite(STDERR, "\nNothing was recorded. The baseline on disk is unchanged.\n");

    exit(1);
}

/**
 * A half that could not be recorded keeps whatever is already on disk.
 *
 * A key whose allowlist refuses the batch, or a lookup that timed out, is a
 * reason to record nothing new — not a reason to throw away a good recording made
 * on a day the call worked. Overwriting it with null would delete the standard
 * the bulk checks are held against, and the run that noticed would be the one
 * that stopped failing.
 *
 * @var array<string, mixed> $existing
 */
$existing = [];

if (is_file(BASELINE_PATH)) {
    $decoded = json_decode((string) file_get_contents(BASELINE_PATH), true);

    if (\is_array($decoded)) {
        /** @var array<string, mixed> $decoded */
        $existing = $decoded;
    }
}

/** @var list<string> $kept */
$kept = [];

/**
 * @param array<string, string>|null $recorded
 */
$half = static function (string $kind, ?array $recorded) use ($existing, &$kept): mixed {
    if ($recorded !== null) {
        return ['signature' => $recorded];
    }

    $stored = $existing[$kind] ?? null;

    if (\is_array($stored) && ($stored['signature'] ?? null) !== null) {
        $kept[] = $kind;
    }

    return $stored;
};

$baseline = [
    'note' => NOTE,
    'recordedAt' => gmdate('Y-m-d\TH:i:s\Z'),
    'baseUrl' => $baseUrl,
    'sdkVersion' => Version::VERSION,
    'single' => $half('single', $single),
    'bulk' => $half('bulk', $bulk),
];

if ($dryRun) {
    $say("\n--dry-run: nothing written.");

    exit(0);
}

file_put_contents(
    BASELINE_PATH,
    json_encode(
        $baseline,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . "\n",
);

$say(sprintf(
    "\nWrote src/response-baseline.json — recorded from %s on SDK %s.",
    $baseUrl,
    Version::VERSION,
));

if ($kept !== []) {
    $say(sprintf(
        'The %s half was left as it was — this run could not record it.',
        implode(' and ', $kept),
    ));
}

$say('Commit it. Every smoke run from now on is held against it.');
