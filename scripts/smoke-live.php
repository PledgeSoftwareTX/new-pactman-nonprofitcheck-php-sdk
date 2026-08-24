<?php

/**
 * Checks a live deployment against the two documents that describe what its
 * responses are supposed to look like.
 *
 * `src/response-contract.json` is what this package *promises*, derived from the
 * documented model on {@see \Pactman\NonprofitCheckPlus\Model\Nonprofit}. A
 * failure there means the API no longer matches what the SDK tells its users to
 * expect. It is checked in and identical for everyone, which is what lets these
 * checks fail on the very first run rather than needing a recording to compare
 * against.
 *
 * `src/response-baseline.json` is what production *returned*, recorded once by
 * `composer baseline:record` and committed. A failure there means production
 * moved — in any direction, including in the fields the contract deliberately
 * leaves as a bare `string`, where the promise is too loose to notice anything.
 * The recording is never written by a run: a baseline that rewrites itself agrees
 * with the API by construction and can never fail.
 *
 *   PACTMAN_API_KEY=... php scripts/smoke-live.php
 *   php scripts/smoke-live.php 41-1787097 996589560
 *   PACTMAN_BASE_URL=http://127.0.0.1:8787 MOCK_API_KEY=mock-key php scripts/smoke-live.php
 *
 * With no arguments the subjects are the ones `scripts/lib/Env.php` names, which
 * are the organizations the committed recording describes. Passing your own
 * replaces them, and the recording then describes different records — the
 * comparison is strict either way, so read a diff with that in mind.
 *
 * No value from any response is ever printed. Exits non-zero when any check
 * fails.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Contract;
use Pactman\NonprofitCheckPlus\Dev\Env;
use Pactman\NonprofitCheckPlus\Ein;
use Pactman\NonprofitCheckPlus\Exception\PactmanException;
use Pactman\NonprofitCheckPlus\PactmanClient;

Env::loadEnvFile();

// --- colour ------------------------------------------------------------------

/**
 * ANSI colour, when there is someone there to see it.
 *
 * This report is read by eye far more often than it is piped, and a failed check
 * that looks exactly like a passed one is a failed check that gets scrolled past.
 * Off when stdout is not a terminal, when NO_COLOR is set (no-color.org) or when
 * TERM says dumb — a redirected log stays plain text, with no escape sequences to
 * confuse whatever reads it next. FORCE_COLOR overrides all of that, for a CI log
 * that is rendered with colour even though nothing it is written to is a
 * terminal.
 */
$colour = Env::get('NO_COLOR') !== null
    ? false
    : Env::get('FORCE_COLOR') !== null
        || (\function_exists('posix_isatty') && @posix_isatty(STDOUT) && Env::get('TERM') !== 'dumb');

const CODES = ['red' => 31, 'green' => 32, 'yellow' => 33, 'dim' => 2];

$paint = static fn (?string $shade, string $text): string => $colour && $shade !== null
    ? "\033[" . CODES[$shade] . "m{$text}\033[0m"
    : $text;

const STATUS = ['pass' => '✓', 'fail' => '✗', 'skip' => '–'];

/**
 * How each status is coloured. A pass gets a green mark and plain text — a report
 * that is mostly passes should read as text, not as a wall of green — while a
 * failure is red for its whole line, mark and message together, because the
 * message is the part worth finding.
 */
const STATUS_COLOR = ['pass' => null, 'fail' => 'red', 'skip' => 'dim'];

$mark = static fn (string $status): string => $paint(
    $status === 'pass' ? 'green' : STATUS_COLOR[$status],
    STATUS[$status],
);

// --- the run -----------------------------------------------------------------

$apiKey = Env::get(Env::API_KEY_ENV) ?? Env::get('MOCK_API_KEY');

if ($apiKey === null) {
    fwrite(STDERR, sprintf(
        "No API key. Put %s in .env, or export it, and run this again.\n",
        Env::API_KEY_ENV,
    ));

    exit(2);
}

$redact = static fn (string $value): string => str_replace($apiKey, '[redacted]', $value);

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$given = \array_slice($arguments, 1);

$singleEin = $given[0] ?? Env::EIN;
$bulkSubjects = \count($given) > 1 ? \array_slice($given, 1) : Env::BULK_EINS;

try {
    $ein = Ein::normalize($singleEin);
    $missingEin = Ein::normalize(Env::MISSING_EIN);
    $bulkEins = Ein::normalizeMany(\array_slice($bulkSubjects, 0, Env::BULK_PROBE_LIMIT));
} catch (PactmanException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");

    exit(2);
}

$baseUrl = Env::get('PACTMAN_BASE_URL');

$client = new PactmanClient(
    apiKey: $apiKey,
    baseUrl: $baseUrl,
    timeout: 20.0,
    retry: ['maxRetries' => 2],
);

printf("Target        %s\n", $client->baseUrl());
printf("Subjects      %s · bulk %s\n", $ein, $bulkEins === [] ? 'none' : implode(', ', $bulkEins));
printf("\n");

/** @var array<string, array<string, string>> $observed */
$observed = [];
/** @var array<string, string> $absent */
$absent = [];

try {
    $single = $client->nonprofits->check($ein);
    printf(
        "  single check   HTTP %d in %sms\n",
        $single->status,
        var_export($single->timeTakenMs, true),
    );

    $observed['single'] = \is_array($single->raw) ? Contract::signatureOf($single->raw) : [];
} catch (PactmanException $error) {
    $absent['single'] = 'the single check failed — ' . $redact($error->getMessage());
    printf("  single check   %s\n", $absent['single']);
}

/**
 * The batches to try, in the order `record-baseline.php` records them.
 *
 * The partial-success batch first, because its envelope is the only one carrying
 * the item-level `errors` a batch with a miss comes back with; then the duplicate
 * probe, which is what a key whose bulk EINs are allowlisted falls back to — such
 * a key refuses the whole batch the moment an EIN with no record is in it.
 *
 * Sending a batch the recorder never sends would disagree with the recording on
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

$absent['bulk'] = 'no bulk subjects were given, so no bulk response was returned';

foreach ($bulkAttempts as $attempt) {
    try {
        $bulk = $client->nonprofits->checkBulk($attempt);
        printf(
            "  bulk check     HTTP %d, %d organization(s)\n",
            $bulk->status,
            \count($bulk->organizations),
        );

        $observed['bulk'] = \is_array($bulk->raw) ? Contract::signatureOf($bulk->raw) : [];
        unset($absent['bulk']);

        break;
    } catch (PactmanException $error) {
        $absent['bulk'] = 'the bulk check failed — ' . $redact($error->getMessage());
    }
}

if (isset($absent['bulk'])) {
    printf("  bulk check     %s\n", $absent['bulk']);
}

// --- the documents ------------------------------------------------------------

/** @var array<string, mixed> $contract */
$contract = json_decode(
    (string) file_get_contents(__DIR__ . '/../src/response-contract.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);

$baselinePath = __DIR__ . '/../src/response-baseline.json';

/**
 * The committed recording. An unreadable or absent one is a failure, not a shrug:
 * the whole point of the file is that every run is held against it, and a run
 * that silently passes for want of one is the state this replaced.
 */
if (!is_file($baselinePath)) {
    fwrite(STDERR, sprintf(
        "\nsrc/response-baseline.json is missing — record it against production with "
            . "`composer baseline:record` and commit it.\n",
    ));

    exit(1);
}

/** @var array<string, mixed> $recording */
$recording = json_decode(
    (string) file_get_contents($baselinePath),
    true,
    flags: JSON_THROW_ON_ERROR,
);

// --- the checks ---------------------------------------------------------------

/** @var list<array{status: string, group: string, name: string, detail: string, changes: string}> $results */
$results = [];

$report = static function (
    string $group,
    string $name,
    string $status,
    string $detail,
    string $changes = '',
) use (&$results): void {
    $results[] = [
        'status' => $status,
        'group' => $group,
        'name' => $name,
        'detail' => $detail,
        'changes' => $changes,
    ];
};

foreach (['single', 'bulk'] as $kind) {
    if (!isset($observed[$kind]) || $observed[$kind] === []) {
        $reason = $absent[$kind] ?? "the {$kind} check returned no body to check";

        foreach (['types', 'fields', 'recording'] as $name) {
            $report($kind, $name, 'skip', $reason);
        }

        continue;
    }

    $signature = $observed[$kind];
    $paths = \count($signature);
    $expected = Contract::composeExpected($contract, $kind);

    // Values the contract permits no form of: a boolean that turned into a
    // string, a timestamp that turned ISO.
    $types = Contract::contractDiff($expected, $signature);

    $report(
        $kind,
        'types',
        $types === [] ? 'pass' : 'fail',
        $types === []
            ? "{$paths} paths carry the predicted types and value formats"
            : sprintf(
                'the live %s response carries values this package does not predict — %s',
                $kind,
                Contract::summarizeChanges($types),
            ),
        $types === []
            ? ''
            : Contract::formatChanges($types)
                . "\n      reconcile src/response-contract.json and Nonprofit's @property-read "
                . 'list with the API, once the change is understood and intended',
    );

    // Fields the API sent that the package does not predict, and fields it
    // predicts that the API did not send. Both directions fail.
    $fields = Contract::coverageDiff($expected, $signature);

    $report(
        $kind,
        'fields',
        $fields['changes'] === [] ? 'pass' : 'fail',
        $fields['changes'] === []
            ? sprintf(
                '%d paths, all predicted · %d predicted under a null or empty parent',
                $paths,
                $fields['unreachable'],
            )
            : sprintf(
                'the live %s response and this package disagree on which fields exist — %s',
                $kind,
                Contract::summarizeChanges($fields['changes']),
            ),
        $fields['changes'] === []
            ? ''
            : Contract::formatChanges($fields['changes'])
                . "\n      reconcile src/response-contract.json and Nonprofit's @property-read "
                . 'list with the API, once the change is understood and intended',
    );

    // The same response, held against the committed recording of production.
    // Strict in both directions and on every token: a path that appeared, a path
    // that disappeared, a value whose form moved.
    $recorded = $recording[$kind] ?? null;

    if (!\is_array($recorded) || !\is_array($recorded['signature'] ?? null)) {
        $report(
            $kind,
            'recording',
            'fail',
            sprintf(
                'no %s shape is recorded in src/response-baseline.json — record it against '
                    . 'production with `composer baseline:record` and commit it',
                $kind,
            ),
        );

        continue;
    }

    /** @var array<string, string> $before */
    $before = $recorded['signature'];

    $drift = [
        ...Contract::schemaDiff($before, $signature),
        ...Contract::typeDiff($before, $signature),
    ];

    $report(
        $kind,
        'recording',
        $drift === [] ? 'pass' : 'fail',
        $drift === []
            ? "{$paths} paths, identical to the recording"
            : sprintf(
                'the live %s response no longer matches the recording made from %s on %s — %s',
                $kind,
                \is_string($recording['baseUrl'] ?? null) ? $recording['baseUrl'] : 'production',
                \is_string($recording['recordedAt'] ?? null) ? $recording['recordedAt'] : 'an earlier date',
                Contract::summarizeChanges($drift),
            ),
        $drift === []
            ? ''
            : Contract::formatChanges($drift)
                . "\n      if production moved and the move is intended, re-record with "
                . '`composer baseline:record` and commit the diff',
    );
}

// --- the report ---------------------------------------------------------------

const NAME_WIDTH = 14;

$printed = '';

foreach ($results as $result) {
    if ($result['group'] !== $printed) {
        $printed = $result['group'];
        printf("\n%s  the live response against the contract and the recording\n", $printed);
    }

    // Padded before it is coloured: the escape sequences carry no width, and
    // padding a string that contains them pushes the column out by their length
    // on every line that has any.
    $line = str_pad($result['name'], NAME_WIDTH) . $result['detail'];

    printf("  %s %s\n", $mark($result['status']), $paint(STATUS_COLOR[$result['status']], $line));

    if ($result['changes'] !== '') {
        printf("%s\n", $paint('dim', $result['changes']));
    }
}

$counts = ['pass' => 0, 'fail' => 0, 'skip' => 0];

foreach ($results as $result) {
    ++$counts[$result['status']];
}

printf("\nSummary\n");
printf(
    "  %d checks: %s, %s, %d skipped\n",
    \count($results),
    $paint('green', "{$counts['pass']} passed"),
    $paint($counts['fail'] > 0 ? 'red' : null, "{$counts['fail']} failed"),
    $counts['skip'],
);

exit($counts['fail'] > 0 ? 1 : 0);
