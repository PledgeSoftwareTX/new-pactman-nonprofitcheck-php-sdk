<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Tests;

use Pactman\NonprofitCheckPlus\Exception\ValidationException;
use Pactman\NonprofitCheckPlus\Model\Nonprofit;
use Pactman\NonprofitCheckPlus\Tests\Support\FakeHttpClient;
use Pactman\NonprofitCheckPlus\Tests\Support\Fixtures;
use Pactman\NonprofitCheckPlus\Tests\Support\Stub;
use PHPUnit\Framework\TestCase;

final class CheckTest extends TestCase
{
    public function testSendsAnAuthenticatedGetToTheNormalizedEin(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope(Fixtures::nonprofit())));

        Fixtures::client($http)->nonprofits->check('41-1787097');

        $request = $http->lastRequest();

        self::assertSame('GET', $request->method);
        self::assertSame(
            Fixtures::BASE_URL . '/api/entities/nonprofitcheck/v1/us/ein/411787097',
            $request->url,
        );
        self::assertSame('Bearer ' . Fixtures::API_KEY, $request->headers['Authorization']);
        self::assertSame('application/json', $request->headers['Accept']);
        self::assertNull($request->body);
        self::assertArrayNotHasKey('Content-Type', $request->headers);
    }

    public function testHyphenatedAndBareEinsAreTheSameRequest(): void
    {
        $http = new FakeHttpClient([new Stub(body: Fixtures::envelope(Fixtures::nonprofit()))]);
        $client = Fixtures::client($http);

        $client->nonprofits->check('41-1787097');
        $client->nonprofits->check('411787097');

        self::assertSame($http->requests[0]->url, $http->requests[1]->url);
    }

    public function testShapesTheEnvelopeIntoAResult(): void
    {
        $http = FakeHttpClient::always(new Stub(
            body: Fixtures::envelope(Fixtures::nonprofit(), ['timeTaken' => 12, 'nonprofit_check_count' => 1284]),
            headers: ['content-type' => 'application/json', 'x-request-id' => 'req-abc123'],
        ));

        $result = Fixtures::client($http)->nonprofits->check('411787097');

        self::assertSame(200, $result->status);
        self::assertSame(1284, $result->checkCount);
        self::assertSame(12.0, $result->timeTakenMs);
        self::assertSame('req-abc123', $result->requestId);
        self::assertSame([], $result->errors);
        self::assertInstanceOf(Nonprofit::class, $result->nonprofit);
        self::assertSame('EXAMPLE NONPROFIT', $result->nonprofit->organization_name);
        self::assertTrue($result->nonprofit->pub78_verified);
    }

    public function testReadsAlternativeCorrelationHeaders(): void
    {
        foreach (['x-request-id', 'x-correlation-id', 'request-id'] as $header) {
            $http = FakeHttpClient::always(new Stub(
                body: Fixtures::envelope(Fixtures::nonprofit()),
                headers: [$header => 'id-' . $header],
            ));

            $result = Fixtures::client($http)->nonprofits->check('411787097');

            self::assertSame('id-' . $header, $result->requestId);
        }
    }

    public function testRequestIdIsNullWhenTheServerSendsNone(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope(Fixtures::nonprofit()), headers: []));

        self::assertNull(Fixtures::client($http)->nonprofits->check('411787097')->requestId);
    }

    public function testNonprofitIsNullWhenTheApiReturnedNoRecord(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope(null)));

        $result = Fixtures::client($http)->nonprofits->check('411787097');

        self::assertNull($result->nonprofit);
        self::assertSame(200, $result->status);
    }

    public function testUnwrapsASingleRecordDeliveredAsAnArray(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope([Fixtures::nonprofit()])));

        $result = Fixtures::client($http)->nonprofits->check('411787097');

        self::assertInstanceOf(Nonprofit::class, $result->nonprofit);
        self::assertSame('411787097', $result->nonprofit->ein);
    }

    public function testRawPreservesTheWholeEnvelope(): void
    {
        $envelope = Fixtures::envelope(Fixtures::nonprofit());
        $http = FakeHttpClient::always(new Stub(body: $envelope));

        $result = Fixtures::client($http)->nonprofits->check('411787097');

        self::assertSame($envelope, $result->raw);
    }

    public function testFieldsThisSdkDoesNotDeclareStayReadable(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope(Fixtures::nonprofit([
            'state_charity_registration_status' => 'ACTIVE',
            'watchlist_screening' => ['provider' => 'example', 'matches' => 0],
        ]))));

        $nonprofit = Fixtures::client($http)->nonprofits->check('411787097')->nonprofit;

        self::assertNotNull($nonprofit);
        self::assertSame('ACTIVE', $nonprofit->get('state_charity_registration_status'));
        self::assertSame(['provider' => 'example', 'matches' => 0], $nonprofit->get('watchlist_screening'));
        self::assertTrue($nonprofit->has('watchlist_screening'));
    }

    public function testDistinguishesAnAbsentFieldFromANullOne(): void
    {
        $record = Fixtures::nonprofit();
        unset($record['organization_name_aka']);

        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope($record)));
        $nonprofit = Fixtures::client($http)->nonprofits->check('411787097')->nonprofit;

        self::assertNotNull($nonprofit);
        self::assertFalse($nonprofit->has('organization_name_aka'));
        self::assertNull($nonprofit->get('organization_name_aka'));
        self::assertTrue($nonprofit->has('revocation_code'));
        self::assertNull($nonprofit->get('revocation_code'));
    }

    public function testNullAndFalseSurviveAsDistinctValues(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope(Fixtures::nonprofit([
            'pub78_verified' => false,
            'bmf_status' => null,
        ]))));

        $nonprofit = Fixtures::client($http)->nonprofits->check('411787097')->nonprofit;

        self::assertNotNull($nonprofit);
        self::assertFalse($nonprofit->pub78_verified);
        self::assertNull($nonprofit->bmf_status);
    }

    public function testAMalformedEinCostsNoRequest(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope(Fixtures::nonprofit())));

        try {
            Fixtures::client($http)->nonprofits->check('4117870');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException) {
            self::assertSame(0, $http->requestCount());
        }
    }

    public function testPerRequestHeadersCannotDisplaceTheCredential(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope(Fixtures::nonprofit())));

        Fixtures::client($http)->nonprofits->check('411787097', headers: [
            'Authorization' => 'Bearer not-your-key',
            'X-Trace-Id' => 'trace-1',
        ]);

        self::assertSame('Bearer ' . Fixtures::API_KEY, $http->lastRequest()->headers['Authorization']);
        self::assertSame('trace-1', $http->lastRequest()->headers['X-Trace-Id']);
    }

    public function testDefaultHeadersAreSentOnEveryRequest(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope(Fixtures::nonprofit())));

        $client = Fixtures::client($http, defaultHeaders: ['X-Tenant' => 'acme']);
        $client->nonprofits->check('411787097');

        self::assertSame('acme', $http->lastRequest()->headers['X-Tenant']);
    }

    public function testCheckCountIsNullWhenTheApiOmitsIt(): void
    {
        $envelope = Fixtures::envelope(Fixtures::nonprofit());
        unset($envelope['nonprofit_check_count'], $envelope['timeTaken']);

        $http = FakeHttpClient::always(new Stub(body: $envelope));
        $result = Fixtures::client($http)->nonprofits->check('411787097');

        self::assertNull($result->checkCount);
        self::assertNull($result->timeTakenMs);
    }

    public function testASuccessfulResponseWithANonJsonBodyKeepsTheEvidence(): void
    {
        $http = FakeHttpClient::always(new Stub(bodyText: '<html>maintenance</html>'));

        $result = Fixtures::client($http)->nonprofits->check('411787097');

        self::assertSame('<html>maintenance</html>', $result->raw);
        self::assertNull($result->nonprofit);
        self::assertSame(200, $result->status);
    }
}
