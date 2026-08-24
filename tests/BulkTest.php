<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Tests;

use Pactman\NonprofitCheckPlus\Endpoints;
use Pactman\NonprofitCheckPlus\Exception\ValidationException;
use Pactman\NonprofitCheckPlus\Tests\Support\FakeHttpClient;
use Pactman\NonprofitCheckPlus\Tests\Support\Fixtures;
use Pactman\NonprofitCheckPlus\Tests\Support\Stub;
use PHPUnit\Framework\TestCase;

final class BulkTest extends TestCase
{
    /**
     * @param list<string>                       $eins
     * @param list<array<string, mixed>>|null    $errors
     *
     * @return array<string, mixed>
     */
    private static function bulkEnvelope(array $eins, ?array $errors = null): array
    {
        return Fixtures::envelope(
            array_map(static fn (string $ein): array => Fixtures::nonprofit(['ein' => $ein]), $eins),
            ['errors' => $errors, 'nonprofit_check_count' => count($eins)],
        );
    }

    public function testPostsTheNormalizedEinsAsAJsonArray(): void
    {
        $http = FakeHttpClient::always(new Stub(body: self::bulkEnvelope(['411787097', '996589560'])));

        Fixtures::client($http)->nonprofits->checkBulk(['41-1787097', '996589560']);

        $request = $http->lastRequest();

        self::assertSame('POST', $request->method);
        self::assertSame(Fixtures::BASE_URL . Endpoints::BULK_CHECK_PATH, $request->url);
        self::assertSame('application/json', $request->headers['Content-Type']);
        self::assertSame(['411787097', '996589560'], $http->jsonBody());
    }

    public function testSendsEinsInTheOrderSuppliedAndKeepsDuplicates(): void
    {
        $http = FakeHttpClient::always(new Stub(body: self::bulkEnvelope(['411787097'])));

        Fixtures::client($http)->nonprofits->checkBulk(['996589560', '411787097', '996589560']);

        self::assertSame(['996589560', '411787097', '996589560'], $http->jsonBody());
    }

    public function testDedupeCollapsesDuplicatesKeepingFirstSeenOrder(): void
    {
        $http = FakeHttpClient::always(new Stub(body: self::bulkEnvelope(['411787097'])));

        Fixtures::client($http)->nonprofits->checkBulk(
            ['996589560', '411787097', '996589560'],
            dedupe: true,
        );

        self::assertSame(['996589560', '411787097'], $http->jsonBody());
    }

    public function testCollectsNotFoundEinsFromASuccessfulResponse(): void
    {
        $http = FakeHttpClient::always(new Stub(body: self::bulkEnvelope(['411787097'], [[
            'resource' => 'nonprofitcheckbulk',
            'reason' => 'There are no matching nonprofits in our records for this set of EINs',
            'code' => 404,
            'eins' => ['999999999', '123456789'],
        ]])));

        $result = Fixtures::client($http)->nonprofits->checkBulk(['411787097', '999999999', '123456789']);

        self::assertSame(200, $result->status);
        self::assertCount(1, $result->organizations);
        self::assertSame(['999999999', '123456789'], $result->notFoundEins);
        self::assertCount(1, $result->errors);
        self::assertSame('nonprofitcheckbulk', $result->errors[0]->resource);
        self::assertSame(404, $result->errors[0]->code);
    }

    public function testAcceptsACommaSeparatedEinsString(): void
    {
        $http = FakeHttpClient::always(new Stub(body: self::bulkEnvelope(['411787097'], [[
            'resource' => 'nonprofitcheckbulk',
            'reason' => 'No record',
            'eins' => '999999999, 123456789',
        ]])));

        $result = Fixtures::client($http)->nonprofits->checkBulk(['411787097', '999999999']);

        self::assertSame(['999999999', '123456789'], $result->notFoundEins);
    }

    public function testByEinIndexesTheResponseRegardlessOfOrder(): void
    {
        // The API answered in an order that does not match the request.
        $http = FakeHttpClient::always(new Stub(body: self::bulkEnvelope(['996589560', '411787097'])));

        $result = Fixtures::client($http)->nonprofits->checkBulk(['411787097', '996589560']);
        $byEin = $result->byEin();

        self::assertCount(2, $byEin);
        self::assertSame('411787097', $byEin['411787097']->ein);
        self::assertSame('996589560', $byEin['996589560']->ein);
    }

    public function testByEinLooksUpAnEinWhateverPhpDidToTheKey(): void
    {
        // PHP stores '411787097' under an int key and '042103594' under a string
        // one. Lookup canonicalizes the same way, so both forms still resolve.
        $http = FakeHttpClient::always(new Stub(body: self::bulkEnvelope(['411787097', '042103594'])));

        $byEin = Fixtures::client($http)->nonprofits->checkBulk(['411787097', '042103594'])->byEin();

        self::assertSame('411787097', $byEin['411787097']->ein);
        self::assertSame('042103594', $byEin['042103594']->ein, 'a leading zero must not be lost');
        self::assertArrayHasKey('042103594', $byEin);
    }

    public function testByEinSkipsRecordsWithoutAnEin(): void
    {
        $record = Fixtures::nonprofit();
        unset($record['ein']);

        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope([$record, Fixtures::nonprofit()])));

        $result = Fixtures::client($http)->nonprofits->checkBulk(['411787097']);

        self::assertCount(2, $result->organizations);

        $byEin = $result->byEin();

        self::assertCount(1, $byEin, 'the record without an ein has no key to file it under');
        self::assertSame('411787097', $byEin['411787097']->ein);
    }

    public function testAcceptsDataWrappedInAnOrganizationsKey(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope([
            'organizations' => [Fixtures::nonprofit()],
        ])));

        $result = Fixtures::client($http)->nonprofits->checkBulk(['411787097']);

        self::assertCount(1, $result->organizations);
        self::assertSame('411787097', $result->organizations[0]->ein);
    }

    public function testOrganizationsIsEmptyWhenTheApiReturnedNoData(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope(null)));

        $result = Fixtures::client($http)->nonprofits->checkBulk(['411787097']);

        self::assertSame([], $result->organizations);
        self::assertSame([], $result->notFoundEins);
    }

    public function testRejectsAnEmptyBatchLocally(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope([])));

        try {
            Fixtures::client($http)->nonprofits->checkBulk([]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $error) {
            self::assertStringContainsString('at least one EIN', $error->getMessage());
            self::assertSame(0, $http->requestCount());
        }
    }

    public function testRejectsAnOverLimitBatchLocally(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope([])));
        $eins = array_map(
            static fn (int $index): string => str_pad((string) (400000000 + $index), 9, '0', STR_PAD_LEFT),
            range(1, Endpoints::MAX_BULK_EINS + 1),
        );

        try {
            Fixtures::client($http)->nonprofits->checkBulk($eins);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $error) {
            self::assertStringContainsString('at most 50 EINs per request, received 51', $error->getMessage());
            self::assertStringContainsString('does not chunk automatically', $error->getMessage());
            self::assertSame(0, $http->requestCount());
        }
    }

    public function testDedupeIsAppliedBeforeTheLimitIsChecked(): void
    {
        $http = FakeHttpClient::always(new Stub(body: self::bulkEnvelope(['411787097'])));
        $eins = array_fill(0, Endpoints::MAX_BULK_EINS + 10, '411787097');

        $result = Fixtures::client($http)->nonprofits->checkBulk($eins, dedupe: true);

        self::assertSame(['411787097'], $http->jsonBody());
        self::assertCount(1, $result->organizations);
    }

    public function testOneMalformedEinRejectsTheWholeBatch(): void
    {
        $http = FakeHttpClient::always(new Stub(body: Fixtures::envelope([])));

        try {
            Fixtures::client($http)->nonprofits->checkBulk(['411787097', 'nope', '996589560']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $error) {
            self::assertSame(0, $http->requestCount());
            self::assertCount(1, $error->issues);
            self::assertSame(1, $error->issues[0]->index);
            self::assertSame('nope', $error->issues[0]->value);
        }
    }
}
