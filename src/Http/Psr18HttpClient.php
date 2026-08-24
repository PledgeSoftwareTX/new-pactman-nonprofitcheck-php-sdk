<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends through any PSR-18 client — Guzzle, Symfony HttpClient, a test double.
 *
 * Requires `psr/http-client` and a PSR-17 factory; neither is a dependency of
 * this package, so this class is only loaded if you construct it.
 *
 * ```php
 * $client = new PactmanClient(
 *     apiKey: getenv('PACTMAN_API_KEY'),
 *     httpClient: new Psr18HttpClient($guzzle, $psr17, $psr17),
 * );
 * ```
 *
 * > **Your client owns the deadline.** PSR-18 has no per-request timeout, so the
 * > SDK's `timeout` option cannot be enforced through this adapter — configure
 * > the timeout on the client you pass in. Everything else is unchanged:
 * > retries, backoff, throttling and the error taxonomy still apply, and a
 * > timeout your client raises surfaces as a
 * > {@see \Pactman\NonprofitCheckPlus\Exception\NetworkException} rather than a
 * > {@see \Pactman\NonprofitCheckPlus\Exception\TimeoutException}, because the
 * > SDK will not guess at the cause by reading an exception message.
 */
final class Psr18HttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $psrRequest = $this->requestFactory->createRequest($request->method, $request->url);

        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }

        if ($request->body !== null) {
            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($request->body));
        }

        try {
            $response = $this->client->sendRequest($psrRequest);
        } catch (ClientExceptionInterface $exception) {
            throw new TransportException($exception->getMessage(), previous: $exception);
        }

        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }

        return new HttpResponse(
            status: $response->getStatusCode(),
            headers: $headers,
            body: (string) $response->getBody(),
        );
    }
}
