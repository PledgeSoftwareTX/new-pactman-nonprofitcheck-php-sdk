<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Tests;

use Pactman\NonprofitCheckPlus\Exception\ApiException;
use Pactman\NonprofitCheckPlus\Exception\AuthenticationException;
use Pactman\NonprofitCheckPlus\Exception\AuthorizationException;
use Pactman\NonprofitCheckPlus\Exception\BadRequestException;
use Pactman\NonprofitCheckPlus\Exception\ErrorCategory;
use Pactman\NonprofitCheckPlus\Exception\ErrorOrigin;
use Pactman\NonprofitCheckPlus\Exception\NetworkException;
use Pactman\NonprofitCheckPlus\Exception\NotFoundException;
use Pactman\NonprofitCheckPlus\Exception\PactmanException;
use Pactman\NonprofitCheckPlus\Exception\RateLimitException;
use Pactman\NonprofitCheckPlus\Exception\ServerException;
use Pactman\NonprofitCheckPlus\Exception\ValidationException;
use Pactman\NonprofitCheckPlus\Http\TransportException;
use Pactman\NonprofitCheckPlus\Tests\Support\FakeHttpClient;
use Pactman\NonprofitCheckPlus\Tests\Support\Fixtures;
use Pactman\NonprofitCheckPlus\Tests\Support\Stub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorsTest extends TestCase
{
    /** @return iterable<string, array{int, class-string<ApiException>, ErrorCategory}> */
    public static function statusMapping(): iterable
    {
        yield '400' => [400, BadRequestException::class, ErrorCategory::BadRequest];
        yield '401' => [401, AuthenticationException::class, ErrorCategory::Authentication];
        yield '403' => [403, AuthorizationException::class, ErrorCategory::Authorization];
        yield '404' => [404, NotFoundException::class, ErrorCategory::NotFound];
        yield '429' => [429, RateLimitException::class, ErrorCategory::RateLimit];
        yield '500' => [500, ServerException::class, ErrorCategory::Server];
        yield '503' => [503, ServerException::class, ErrorCategory::Server];
        yield '418' => [418, ApiException::class, ErrorCategory::Api];
    }

    #[DataProvider('statusMapping')]
    public function testMapsAStatusToItsException(int $status, string $expected, ErrorCategory $category): void
    {
        $http = FakeHttpClient::always(new Stub(status: $status, body: ['code' => $status, 'message' => 'Nope']));

        try {
            Fixtures::client($http, retry: false)->nonprofits->check('411787097');
            self::fail("Expected {$expected}.");
        } catch (ApiException $error) {
            self::assertSame($expected, $error::class);
            self::assertSame($category, $error->category);
            self::assertSame(ErrorOrigin::Api, $error->origin);
            self::assertSame($status, $error->status);
            self::assertTrue(PactmanException::isPactmanError($error));
        }
    }

    public function testEveryApiExceptionIsCatchableAsTheBaseClass(): void
    {
        $http = FakeHttpClient::always(new Stub(status: 404, body: ['code' => 404]));

        $this->expectException(PactmanException::class);
        Fixtures::client($http, retry: false)->nonprofits->check('411787097');
    }

    public function testPrefersTheErrorReasonsOverTheEnvelopeMessage(): void
    {
        $http = FakeHttpClient::always(new Stub(status: 400, body: [
            'code' => 400,
            'message' => 'Bad Request',
            'errors' => [
                ['resource' => 'nonprofitcheckbulk', 'reason' => 'First problem'],
                ['resource' => 'nonprofitcheckbulk', 'reason' => 'Second problem'],
            ],
        ]));

        try {
            Fixtures::client($http, retry: false)->nonprofits->check('411787097');
            self::fail('Expected a BadRequestException.');
        } catch (BadRequestException $error) {
            self::assertSame('First problem; Second problem', $error->getMessage());
            self::assertSame('First problem; Second problem', $error->apiMessage);
            self::assertSame(400, $error->apiCode);
            self::assertCount(2, $error->apiErrors);
        }
    }

    public function testFallsBackToTheEnvelopeMessage(): void
    {
        $http = FakeHttpClient::always(new Stub(status: 401, body: [
            'code' => 401,
            'message' => 'Unauthorized',
            'errors' => null,
        ]));

        try {
            Fixtures::client($http, retry: false)->nonprofits->check('411787097');
            self::fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $error) {
            self::assertSame('Unauthorized', $error->getMessage());
        }
    }

    public function testFallsBackToADefaultMessageWhenTheBodyCarriesNone(): void
    {
        $http = FakeHttpClient::always(new Stub(status: 401, body: ['code' => 401]));

        try {
            Fixtures::client($http, retry: false)->nonprofits->check('411787097');
            self::fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $error) {
            self::assertSame('The Pactman API key was rejected.', $error->getMessage());
        }
    }

    public function testPreservesMetadataWhenTheBodyIsNotJson(): void
    {
        $http = FakeHttpClient::always(new Stub(
            status: 502,
            bodyText: '<html><body>Bad Gateway</body></html>',
            headers: ['content-type' => 'text/html', 'x-request-id' => 'req-502'],
        ));

        try {
            Fixtures::client($http, retry: false)->nonprofits->check('411787097');
            self::fail('Expected a ServerException.');
        } catch (ServerException $error) {
            self::assertSame(502, $error->status);
            self::assertSame('req-502', $error->requestId);
            self::assertSame('<html><body>Bad Gateway</body></html>', $error->raw);
            self::assertNull($error->apiCode);
            self::assertStringContainsString('Bad Gateway', (string) $error->apiMessage);
        }
    }

    public function testPreservesMetadataWhenTheBodyIsEmpty(): void
    {
        $http = FakeHttpClient::always(new Stub(status: 500, headers: ['x-request-id' => 'req-500']));

        try {
            Fixtures::client($http, retry: false)->nonprofits->check('411787097');
            self::fail('Expected a ServerException.');
        } catch (ServerException $error) {
            self::assertSame('The Pactman API returned a server error (HTTP 500).', $error->getMessage());
            self::assertSame('req-500', $error->requestId);
            self::assertNull($error->raw);
            self::assertSame([], $error->apiErrors);
        }
    }

    public function testATransportFailureBecomesANetworkException(): void
    {
        $http = FakeHttpClient::always(Stub::networkFailure('Could not resolve host: entities.pactman.org'));

        try {
            Fixtures::client($http, retry: false)->nonprofits->check('411787097');
            self::fail('Expected a NetworkException.');
        } catch (NetworkException $error) {
            self::assertSame(ErrorCategory::Network, $error->category);
            self::assertSame(ErrorOrigin::Local, $error->origin);
            self::assertSame(1, $error->attempts);
            self::assertStringContainsString('Could not resolve host', $error->getMessage());
            self::assertInstanceOf(TransportException::class, $error->getPrevious());
        }
    }

    public function testSerializedErrorsAreSafeToLog(): void
    {
        $http = FakeHttpClient::always(new Stub(status: 429, body: [
            'code' => 429,
            'message' => 'Too Many Requests',
            'errors' => [['resource' => 'nonprofitcheck', 'reason' => 'Rate limit exceeded']],
        ], headers: ['retry-after' => '30', 'x-request-id' => 'req-429']));

        try {
            Fixtures::client($http, retry: false)->nonprofits->check('411787097');
            self::fail('Expected a RateLimitException.');
        } catch (RateLimitException $error) {
            $serialized = $error->toArray();

            self::assertSame('rate_limit', $serialized['category']);
            self::assertSame('api', $serialized['origin']);
            self::assertSame(429, $serialized['status']);
            self::assertSame(30.0, $serialized['retryAfterSeconds']);
            self::assertSame('req-429', $serialized['requestId']);
            self::assertStringNotContainsString(
                Fixtures::API_KEY,
                (string) json_encode($error, JSON_THROW_ON_ERROR),
            );
        }
    }

    public function testTheApiKeyNeverReachesAnyDiagnosticSurface(): void
    {
        $http = FakeHttpClient::always(new Stub(status: 401, body: ['code' => 401, 'message' => 'Unauthorized']));
        $client = Fixtures::client($http, retry: false);

        $caught = null;

        try {
            $client->nonprofits->check('411787097');
        } catch (AuthenticationException $error) {
            $caught = $error;
        }

        self::assertNotNull($caught);

        $surfaces = [
            'exception message' => $caught->getMessage(),
            'exception toArray' => print_r($caught->toArray(), true),
            'exception trace' => $caught->getTraceAsString(),
            'client toArray' => print_r($client->toArray(), true),
            'client json' => (string) json_encode($client, JSON_THROW_ON_ERROR),
            'client string' => (string) $client,
            'client print_r' => print_r($client, true),
            'client var_export' => var_export($client->toArray(), true),
        ];

        foreach ($surfaces as $name => $text) {
            self::assertStringNotContainsString(Fixtures::API_KEY, $text, "{$name} leaked the API key");
        }

        self::assertStringContainsString('[redacted]', print_r($client->toArray(), true));
    }

    public function testValidationErrorsAreLocalAndCarryTheirIssues(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope(null)));

        try {
            Fixtures::client($http)->nonprofits->check('nope');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $error) {
            self::assertSame(ErrorCategory::Validation, $error->category);
            self::assertSame(ErrorOrigin::Local, $error->origin);
            self::assertNotSame([], $error->issues);
        }
    }
}
