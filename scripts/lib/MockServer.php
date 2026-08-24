<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Dev;

/**
 * Starts the fixture API on a loopback port, for the examples and for CI.
 *
 * PHP's built-in server does the serving; this class owns the child process, the
 * port, and the state file, and shuts all three down on the way out.
 */
final class MockServer
{
    /** @param resource $process */
    private function __construct(
        private $process,
        public readonly int $port,
        public readonly string $apiKey,
        private readonly string $stateFile,
        private bool $stopped = false,
    ) {
    }

    /**
     * Starts the server and waits for it to accept connections.
     *
     * @param int|null $port `null` picks a free port.
     */
    public static function start(?int $port = null, ?string $apiKey = null): self
    {
        $port ??= self::freePort();
        $apiKey ??= getenv('MOCK_API_KEY') ?: 'mock-key';
        $stateFile = tempnam(sys_get_temp_dir(), 'pactman-mock-') ?: sys_get_temp_dir() . '/pactman-mock-state.json';

        // A fresh cycle for every server, so usage examples start from zero.
        file_put_contents($stateFile, json_encode(['checksUsedThisCycle' => 0, 'transientFailuresLeft' => 2]));

        $command = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg(__DIR__ . '/../mock-router.php'),
        );

        $process = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                // The built-in server logs every request to stderr; discard both
                // so the examples own the terminal.
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes,
            null,
            ['MOCK_API_KEY' => $apiKey, 'MOCK_STATE_FILE' => $stateFile] + getenv(),
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the mock server.');
        }

        $server = new self($process, $port, $apiKey, $stateFile);

        if (!$server->waitUntilListening()) {
            $server->stop();

            throw new \RuntimeException("The mock server did not start listening on port {$port}.");
        }

        return $server;
    }

    /** The loopback URL the server is listening on. Always bound to 127.0.0.1. */
    public function baseUrl(): string
    {
        return "http://127.0.0.1:{$this->port}";
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        @unlink($this->stateFile);
    }

    public function __destruct()
    {
        $this->stop();
    }

    private function waitUntilListening(float $timeoutSeconds = 10.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.2);

            if (is_resource($socket)) {
                fclose($socket);

                return true;
            }

            usleep(50_000);
        }

        return false;
    }

    /** Binds port 0 to have the OS name a free port, then releases it. */
    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            throw new \RuntimeException('Could not reserve a port: ' . (is_string($error) ? $error : 'unknown error'));
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        if ($port === 0) {
            throw new \RuntimeException('Could not determine a free port.');
        }

        return $port;
    }
}
