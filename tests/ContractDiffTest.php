<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Tests;

use Pactman\NonprofitCheckPlus\Dev\Contract;
use PHPUnit\Framework\TestCase;

/**
 * {@see Contract} decides what counts as the API having changed, and
 * `scripts/smoke-live.php` fails a run on its answer. The rules that are easy to
 * get subtly wrong are the ones about absence: a field that is missing because it
 * was removed, versus one that is missing because the object it lives in arrived
 * null. Getting that backwards either fails every green run or passes every
 * broken one, and neither is visible without a live deployment to try it against
 * — so it is pinned here.
 */
final class ContractDiffTest extends TestCase
{
    /**
     * The smallest expectation that still has a nested object and an array in it.
     *
     * @return array<string, string>
     */
    private static function expected(): array
    {
        return [
            'code' => 'number',
            'data' => 'null|object',
            'data.ein' => 'digits:9',
            'data.organization_types' => 'array|null',
            'data.organization_types[]' => 'object',
            'data.organization_types[].organization_type' => 'null|string',
            'errors' => 'array|null|string',
            'errors[]' => 'object',
            'errors[].reason' => 'string',
        ];
    }

    /**
     * A successful response: no errors, and this organization has no types.
     *
     * @return array<string, string>
     */
    private static function success(): array
    {
        return [
            'code' => 'number',
            'data' => 'object',
            'data.ein' => 'digits:9',
            'data.organization_types' => 'null',
            'errors' => 'null',
        ];
    }

    public function testPassesAResponseWhoseAbsentPathsAllSitUnderANullParent(): void
    {
        $result = Contract::coverageDiff(self::expected(), self::success());

        self::assertSame([], $result['changes']);
        // errors[], errors[].reason, organization_types[] and its one field.
        self::assertSame(4, $result['unreachable']);
    }

    public function testFailsAFieldThatWentMissingWhileItsParentWasThere(): void
    {
        $observed = self::success();
        unset($observed['data.ein']);

        $result = Contract::coverageDiff(self::expected(), $observed);

        self::assertSame(
            [['kind' => 'removed', 'path' => 'data.ein', 'token' => 'digits:9']],
            $result['changes'],
        );
    }

    public function testFailsAFieldTheApiInvented(): void
    {
        $observed = [...self::success(), 'data.new_field' => 'text'];

        $result = Contract::coverageDiff(self::expected(), $observed);

        self::assertSame(
            [['kind' => 'added', 'path' => 'data.new_field', 'token' => 'text']],
            $result['changes'],
        );
    }

    public function testReportsAVanishedContainerOnceNotOncePerFieldUnderIt(): void
    {
        $result = Contract::coverageDiff(
            self::expected(),
            ['code' => 'number', 'errors' => 'null'],
        );

        self::assertSame(
            [['kind' => 'removed', 'path' => 'data', 'token' => 'null|object']],
            $result['changes'],
        );
    }

    public function testTreatsAnArrayThatArrivedEmptyAsHavingNoRoomForItsElements(): void
    {
        $observed = [...self::success(), 'data.organization_types' => 'array'];

        $result = Contract::coverageDiff(self::expected(), $observed);

        self::assertSame([], $result['changes']);
    }

    public function testFailsAFieldMissingFromTheElementsAnArrayDidReturn(): void
    {
        $observed = [
            ...self::success(),
            'data.organization_types' => 'array',
            'data.organization_types[]' => 'object',
        ];

        $result = Contract::coverageDiff(self::expected(), $observed);

        self::assertSame(
            [[
                'kind' => 'removed',
                'path' => 'data.organization_types[].organization_type',
                'token' => 'null|string',
            ]],
            $result['changes'],
        );
    }

    public function testContractDiffReportsOnlyTheTokensThatOffend(): void
    {
        $observed = [...self::success(), 'data.ein' => 'digits:9|text'];

        self::assertSame(
            [[
                'kind' => 'changed',
                'path' => 'data.ein',
                'from' => 'digits:9',
                'to' => 'text',
            ]],
            Contract::contractDiff(self::expected(), $observed),
        );
    }

    public function testContractDiffLeavesAPathTheContractNeverHeardOfToCoverageDiff(): void
    {
        $observed = [...self::success(), 'data.new_field' => 'text'];

        self::assertSame([], Contract::contractDiff(self::expected(), $observed));
    }

    /** @return array<string, string> */
    private static function recorded(): array
    {
        return ['code' => 'number', 'data.ein' => 'digits:9', 'data.city' => 'text'];
    }

    public function testTheRecordingReportsAPathThatAppearedAndOneThatDisappeared(): void
    {
        $now = ['code' => 'number', 'data.ein' => 'digits:9', 'data.county' => 'text'];

        self::assertSame(
            [
                ['kind' => 'removed', 'path' => 'data.city', 'token' => 'text'],
                ['kind' => 'added', 'path' => 'data.county', 'token' => 'text'],
            ],
            Contract::schemaDiff(self::recorded(), $now),
        );
    }

    public function testTheRecordingReportsAValueWhoseFormMovedOnAPathBothHave(): void
    {
        $now = [...self::recorded(), 'data.ein' => 'digits:2-7'];

        self::assertSame(
            [[
                'kind' => 'changed',
                'path' => 'data.ein',
                'from' => 'digits:9',
                'to' => 'digits:2-7',
            ]],
            Contract::typeDiff(self::recorded(), $now),
        );
    }

    public function testTheRecordingSeesNoDifferenceInAnIdenticalSignature(): void
    {
        self::assertSame([], Contract::schemaDiff(self::recorded(), self::recorded()));
        self::assertSame([], Contract::typeDiff(self::recorded(), self::recorded()));
    }

    public function testComposeExpectedDescribesTheRecordUnderDataAndDataList(): void
    {
        $contract = [
            'envelope' => ['code' => 'number', 'errors' => 'array|null|string'],
            'errorDetail' => ['reason' => 'string'],
            'nonprofit' => ['ein' => 'digits:9|null'],
            'organizationType' => ['organization_type' => 'null|string'],
        ];

        $single = Contract::composeExpected($contract, 'single');
        $bulk = Contract::composeExpected($contract, 'bulk');

        self::assertSame('digits:9|null', $single['data.ein']);
        self::assertSame('digits:9|null', $bulk['data[].ein']);
        self::assertSame('null|object', $single['data']);
        self::assertSame('array|null', $bulk['data']);
    }

    public function testPermitsTreatsStringAsAWildcardOverEveryStringForm(): void
    {
        self::assertTrue(Contract::permits('null|string', 'text'));
        self::assertTrue(Contract::permits('null|string', 'digits:9'));
        self::assertTrue(Contract::permits('null|string', 'date'));
        self::assertTrue(Contract::permits('null|string', 'null'));

        // A named format is a promise, and a value that stops matching it fails
        // even though it is still, technically, a string.
        self::assertFalse(Contract::permits('date|null', 'date:iso'));
        self::assertFalse(Contract::permits('boolean|null', 'text'));
    }
}
