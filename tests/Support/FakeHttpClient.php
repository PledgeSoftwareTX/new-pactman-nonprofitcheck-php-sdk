<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Tests\Support;

use Pactman\NonprofitCheckPlus\Http\HttpClientInterface;
use Pactman\NonprofitCheckPlus\Http\HttpRequest;
use Pactman\NonprofitCheckPlus\Http\HttpResponse;

/**
 * Serves `$stubs` in order; the last one repeats once the queue is exhausted, so
 * a retry test can end on a stable outcome.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<HttpRequest> Every request this client was asked to send. */
    public array $requests = [];

    private int $index = 0;

    /** @param non-empty-list<Stub> $stubs */
    public function __construct(private readonly array $stubs)
    {
        if ($stubs === []) {
            throw new \InvalidArgumentException('FakeHttpClient was created with an empty stub list.');
        }
    }

    /** A client that answers every request with one canned response. */
    public static function always(Stub $stub): self
    {
        return new self([$stub]);
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;

        $stub = $this->stubs[min($this->index, count($this->stubs) - 1)];
        ++$this->index;

        if ($stub->throws !== null) {
            throw $stub->throws;
        }

        return new HttpResponse($stub->status, $stub->headers, $stub->bodyText());
    }

    /** How many requests reached this client. */
    public function requestCount(): int
    {
        return count($this->requests);
    }

    public function lastRequest(): HttpRequest
    {
        return $this->requests[count($this->requests) - 1];
    }

    /**
     * The decoded JSON payload of a recorded request.
     *
     * @return mixed
     */
    public function jsonBody(int $index = 0): mixed
    {
        $body = $this->requests[$index]->body ?? null;

        return $body === null ? null : json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    }
}
