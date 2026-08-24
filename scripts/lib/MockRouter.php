<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Dev;

/**
 * A stand-in for the Nonprofit Check Plus API, so the examples can be run in CI
 * without a real key or network access.
 *
 * Only the two check endpoints are implemented, with the same envelope shape,
 * auth header, batch limit, bulk matching semantics and cumulative check count
 * as the real service. Records come from {@see Fixtures}.
 *
 * PHP's built-in server runs the router afresh for every request, so the
 * billing-cycle total and the transient-failure budget live in a small JSON file
 * rather than in memory.
 */
final class MockRouter
{
    private const MAX_BULK_EINS = 50;
    private const SINGLE_PATTERN = '#^/api/entities/nonprofitcheck/v1/us/ein/(\d{9})$#';
    private const BULK_PATH = '/api/entities/nonprofitcheckbulk/v1/us/eins';

    /** How long the `slow` control EIN holds a response open, in seconds. */
    private const SLOW_RESPONSE_SECONDS = 5;

    /** How many times the `transientFailure` control EIN fails before succeeding. */
    private const TRANSIENT_FAILURES = 2;

    public function __construct(
        private readonly string $validKey,
        private readonly string $stateFile,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            validKey: getenv('MOCK_API_KEY') ?: 'mock-key',
            stateFile: getenv('MOCK_STATE_FILE') ?: sys_get_temp_dir() . '/pactman-mock-state.json',
        );
    }

    /** Handles the request the built-in server is currently serving. */
    public function handle(string $method, string $path, string $rawBody): void
    {
        if (!$this->authorized()) {
            $this->send(401, [
                'code' => 401,
                'message' => 'Unauthorized',
                'errors' => [['resource' => 'nonprofitcheck', 'reason' => 'Invalid API Key']],
                'data' => null,
            ]);

            return;
        }

        if ($method === 'GET' && preg_match(self::SINGLE_PATTERN, $path, $matches) === 1) {
            $this->handleSingle($matches[1]);

            return;
        }

        if ($method === 'POST' && $path === self::BULK_PATH) {
            $decoded = $rawBody === '' ? null : json_decode($rawBody, true);
            $this->handleBulk($decoded);

            return;
        }

        $this->send(404, ['code' => 404, 'message' => 'Not Found', 'errors' => null, 'data' => null]);
    }

    private function handleSingle(string $ein): void
    {
        if ($ein === Fixtures::CONTROL_EINS['rateLimited']) {
            $this->send(
                429,
                $this->errorEnvelope(429, 'Too Many Requests', [
                    ['resource' => 'nonprofitcheck', 'reason' => 'Rate limit exceeded'],
                ]),
                ['Retry-After' => '1'],
            );

            return;
        }

        if ($ein === Fixtures::CONTROL_EINS['transientFailure']) {
            if ($this->takeTransientFailure()) {
                $this->send(503, $this->errorEnvelope(503, 'Service Unavailable', [
                    ['resource' => 'nonprofitcheck', 'reason' => 'Upstream temporarily unavailable'],
                ]));

                return;
            }

            $this->send(200, $this->envelope(
                Fixtures::organization(Fixtures::EINS['publicCharity']),
                null,
                $this->consume(1),
            ));

            return;
        }

        if ($ein === Fixtures::CONTROL_EINS['slow']) {
            sleep(self::SLOW_RESPONSE_SECONDS);

            $this->send(200, $this->envelope(
                Fixtures::organization(Fixtures::EINS['publicCharity']),
                null,
                $this->consume(1),
            ));

            return;
        }

        if (!Fixtures::has($ein)) {
            $this->send(404, $this->errorEnvelope(404, 'Not Found', [[
                'resource' => 'nonprofitcheck',
                'reason' => 'A nonprofit with this EIN does not exist in our records',
            ]]));

            return;
        }

        $this->send(200, $this->envelope(Fixtures::organization($ein), null, $this->consume(1)));
    }

    private function handleBulk(mixed $eins): void
    {
        if (!is_array($eins) || !array_is_list($eins)) {
            $this->send(400, $this->errorEnvelope(400, 'Bad Request', [[
                'resource' => 'nonprofitcheckbulk',
                'reason' => 'The nonprofit check bulk API expects an array of EINs as part of the '
                    . 'HTTP POST request body',
            ]]));

            return;
        }

        if (count($eins) > self::MAX_BULK_EINS) {
            $this->send(400, $this->errorEnvelope(400, 'Bad Request', [[
                'resource' => 'nonprofitcheckbulk',
                'reason' => sprintf(
                    'A maximum of %d EINs can be supplied to the nonprofit check bulk API',
                    self::MAX_BULK_EINS,
                ),
            ]]));

            return;
        }

        /** @var list<string> $eins */
        // Every submitted EIN is counted, duplicates included.
        $this->consume(count($eins));

        // The real service selects with `WHERE ein IN (...)`: duplicates collapse
        // to one row and the result order is the database's, not the request's.
        // Sorting here keeps that difference visible instead of accidentally matching.
        $matched = array_values(array_filter(array_unique($eins), Fixtures::has(...)));
        sort($matched);

        $notFound = array_values(array_filter(
            $eins,
            static fn (string $ein): bool => !Fixtures::has($ein),
        ));

        // Unmatched EINs are refunded, so the count reflects records served.
        $count = $this->consume(-count($notFound));

        if ($matched === []) {
            $this->send(404, $this->errorEnvelope(404, 'Not Found', [[
                'resource' => 'nonprofitcheckbulk',
                'reason' => 'There are no matching nonprofits in our records for this set of EINs',
            ]], $count));

            return;
        }

        $errors = $notFound === [] ? null : [[
            'resource' => 'nonprofitcheckbulk',
            'reason' => 'There are no matching nonprofits in our records for this set of EINs',
            'code' => 404,
            'eins' => $notFound,
        ]];

        $this->send(200, $this->envelope(
            array_map(Fixtures::organization(...), $matched),
            $errors,
            $count,
        ));
    }

    /**
     * The success envelope. `nonprofit_check_count` is the billing-cycle total.
     *
     * @return array<string, mixed>
     */
    private function envelope(mixed $data, mixed $errors, int $checkCount): array
    {
        return [
            'code' => 200,
            'message' => 'OK',
            'errors' => $errors,
            'data' => $data,
            'timeTaken' => 2 + random_int(0, 40),
            'nonprofit_check_count' => $checkCount,
        ];
    }

    /**
     * @param list<array<string, mixed>>|null $errors
     *
     * @return array<string, mixed>
     */
    private function errorEnvelope(int $code, string $message, ?array $errors, ?int $checkCount = null): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'errors' => $errors,
            'data' => null,
            'timeTaken' => 1,
            'nonprofit_check_count' => $checkCount ?? $this->readState()['checksUsedThisCycle'],
        ];
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function send(int $status, array $body, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('X-Request-Id: mock-' . bin2hex(random_bytes(4)));

        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo json_encode($body, JSON_THROW_ON_ERROR);
    }

    private function authorized(): bool
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $provided = null;

        foreach ($headers as $name => $value) {
            if (is_string($value) && strtolower((string) $name) === 'authorization') {
                $provided = $value;

                break;
            }
        }

        $provided ??= $_SERVER['HTTP_AUTHORIZATION'] ?? null;

        return is_string($provided) && hash_equals("Bearer {$this->validKey}", $provided);
    }

    /**
     * Adds `$delta` to the billing-cycle total and returns the new value.
     *
     * A negative delta refunds, which is how unmatched bulk EINs stop counting.
     */
    private function consume(int $delta): int
    {
        return $this->mutateState(static function (array $state) use ($delta): array {
            $state['checksUsedThisCycle'] += $delta;

            return $state;
        })['checksUsedThisCycle'];
    }

    /** True while failures remain; resets the budget once it is exhausted. */
    private function takeTransientFailure(): bool
    {
        $failed = false;

        $this->mutateState(static function (array $state) use (&$failed): array {
            if ($state['transientFailuresLeft'] > 0) {
                --$state['transientFailuresLeft'];
                $failed = true;

                return $state;
            }

            $state['transientFailuresLeft'] = self::TRANSIENT_FAILURES;

            return $state;
        });

        return $failed;
    }

    /** @return array{checksUsedThisCycle: int, transientFailuresLeft: int} */
    private function readState(): array
    {
        $raw = @file_get_contents($this->stateFile);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return self::coerceState($decoded);
    }

    /**
     * Reads a decoded state file, falling back to a fresh cycle.
     *
     * @return array{checksUsedThisCycle: int, transientFailuresLeft: int}
     */
    private static function coerceState(mixed $decoded): array
    {
        $fresh = ['checksUsedThisCycle' => 0, 'transientFailuresLeft' => self::TRANSIENT_FAILURES];

        if (!is_array($decoded)) {
            return $fresh;
        }

        $used = $decoded['checksUsedThisCycle'] ?? null;
        $failures = $decoded['transientFailuresLeft'] ?? null;

        return [
            'checksUsedThisCycle' => is_int($used) ? $used : $fresh['checksUsedThisCycle'],
            'transientFailuresLeft' => is_int($failures) ? $failures : $fresh['transientFailuresLeft'],
        ];
    }

    /**
     * Reads, transforms and writes the state under an exclusive lock.
     *
     * @param callable(array{checksUsedThisCycle: int, transientFailuresLeft: int}): array{checksUsedThisCycle: int, transientFailuresLeft: int} $transform
     *
     * @return array{checksUsedThisCycle: int, transientFailuresLeft: int}
     */
    private function mutateState(callable $transform): array
    {
        $handle = fopen($this->stateFile, 'c+');

        if ($handle === false) {
            // Without a state file the mock still answers; only the running
            // totals stop accumulating. Never fail a request over bookkeeping.
            return $transform($this->readState());
        }

        try {
            flock($handle, LOCK_EX);

            $raw = stream_get_contents($handle);
            $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;

            $state = $transform(self::coerceState($decoded));

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
            fflush($handle);

            return $state;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
