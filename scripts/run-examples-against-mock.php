<?php

/**
 * Runs every documented example against the bundled fixture API.
 *
 * This is what CI runs. An example that stops working is a documentation bug,
 * and this catches it on the push that caused it rather than in somebody's
 * editor three weeks later.
 *
 *   php scripts/run-examples-against-mock.php                 # pass/fail only
 *   EXAMPLES_VERBOSE=1 php scripts/run-examples-against-mock.php   # with output
 *   php scripts/run-examples-against-mock.php ex-22 ex-23     # a subset
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\MockServer;

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$filters = array_slice($arguments, 1);
$verbose = getenv('EXAMPLES_VERBOSE') !== false && getenv('EXAMPLES_VERBOSE') !== '';

$examples = glob(__DIR__ . '/../examples/*.php') ?: [];
sort($examples);

if ($filters !== []) {
    $examples = array_values(array_filter($examples, static function (string $path) use ($filters): bool {
        foreach ($filters as $filter) {
            if (str_contains(basename($path), $filter)) {
                return true;
            }
        }

        return false;
    }));
}

if ($examples === []) {
    fwrite(STDERR, "No examples matched.\n");

    exit(1);
}

// One server for the whole run. Every example inherits PACTMAN_BASE_URL, so the
// ones that would otherwise start their own fixture API share this instance.
$server = MockServer::start();

printf("Fixture API on %s — running %d example(s)\n\n", $server->baseUrl(), count($examples));

$failures = [];
$started = microtime(true);

foreach ($examples as $path) {
    $name = basename($path, '.php');

    // Capture through files rather than pipes. An example that prints more than
    // the pipe buffer holds would block forever waiting for a reader that is
    // itself waiting for the process to exit; a file cannot deadlock.
    $outFile = (string) tempnam(sys_get_temp_dir(), 'pactman-out-');
    $errFile = (string) tempnam(sys_get_temp_dir(), 'pactman-err-');

    $process = proc_open(
        sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($path)),
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['file', $errFile, 'w'],
        ],
        $pipes,
        dirname(__DIR__),
        [
            'PACTMAN_API_KEY' => $server->apiKey,
            'PACTMAN_BASE_URL' => $server->baseUrl(),
        ] + getenv(),
    );

    if (!is_resource($process)) {
        $failures[$name] = 'could not be started';
        @unlink($outFile);
        @unlink($errFile);

        continue;
    }

    $status = proc_close($process);

    $stdout = (string) file_get_contents($outFile);
    $stderr = (string) file_get_contents($errFile);

    @unlink($outFile);
    @unlink($errFile);

    if ($status === 0) {
        printf("  \033[32m✓\033[0m %s\n", $name);
    } else {
        printf("  \033[31m✗\033[0m %s (exit %d)\n", $name, $status);
        $failures[$name] = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
    }

    if ($verbose) {
        echo $stdout, $stderr === '' ? '' : "\n{$stderr}\n";
    }
}

$server->stop();

printf(
    "\n%d passed, %d failed in %.1fs\n",
    count($examples) - count($failures),
    count($failures),
    microtime(true) - $started,
);

foreach ($failures as $name => $detail) {
    printf("\n\033[31m%s\033[0m\n%s\n", $name, $detail);
}

exit($failures === [] ? 0 : 1);
