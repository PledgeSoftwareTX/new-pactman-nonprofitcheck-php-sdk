<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Http;

/**
 * The seam between this SDK and the network.
 *
 * {@see CurlHttpClient} is the default and needs nothing installed beyond
 * ext-curl. To send through your own stack instead — a shared pool, a proxy,
 * pinned certificates, a recording client in tests — implement this interface,
 * or wrap an existing PSR-18 client with {@see Psr18HttpClient}.
 *
 * An implementation is responsible only for sending one request and reporting
 * what came back. Retries, backoff, throttling, authentication and error
 * classification all live in {@see Transport}, above this seam.
 */
interface HttpClientInterface
{
    /**
     * Sends one request and returns the response, whatever its status.
     *
     * A non-2xx response is a normal return value, not a failure: the transport
     * maps statuses to the error taxonomy itself.
     *
     * @throws TransportException when no response was produced at all — the
     *     connection failed, or `$request->timeout` expired.
     */
    public function send(HttpRequest $request): HttpResponse;
}
