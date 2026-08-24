<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Tests;

use Pactman\NonprofitCheckPlus\Ein;
use Pactman\NonprofitCheckPlus\Exception\ErrorOrigin;
use Pactman\NonprofitCheckPlus\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EinTest extends TestCase
{
    /** @return iterable<string, array{mixed, string}> */
    public static function acceptedEins(): iterable
    {
        yield 'hyphenated' => ['41-1787097', '411787097'];
        yield 'bare digits' => ['411787097', '411787097'];
        yield 'surrounding whitespace' => ['  41-1787097  ', '411787097'];
        yield 'tab padded' => ["\t411787097\n", '411787097'];
        yield 'leading zero' => ['04-2103594', '042103594'];
    }

    #[DataProvider('acceptedEins')]
    public function testNormalizesAcceptedShapes(mixed $input, string $expected): void
    {
        self::assertSame($expected, Ein::normalize($input));
        self::assertTrue(Ein::isValid($input));
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejectedEins(): iterable
    {
        yield 'too short' => ['41178709'];
        yield 'too long' => ['4117870977'];
        yield 'letter' => ['41-178709A'];
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'null' => [null];
        yield 'integer' => [411787097];
        yield 'array' => [['411787097']];
        yield 'dot separated' => ['41.1787097'];
        yield 'hyphen in the wrong place' => ['411-787097'];
        yield 'two hyphens' => ['41-178-7097'];
    }

    #[DataProvider('rejectedEins')]
    public function testRejectsMalformedShapes(mixed $input): void
    {
        self::assertFalse(Ein::isValid($input));

        $this->expectException(ValidationException::class);
        Ein::normalize($input);
    }

    public function testNormalizeReportsTheOffendingValue(): void
    {
        try {
            Ein::normalize('nope');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $error) {
            self::assertSame(ErrorOrigin::Local, $error->origin);
            self::assertCount(1, $error->issues);
            self::assertSame('nope', $error->issues[0]->value);
            self::assertNull($error->issues[0]->index);
            self::assertStringContainsString('9 digits', $error->issues[0]->message);
        }
    }

    public function testNormalizeManyKeepsOrderAndDuplicates(): void
    {
        self::assertSame(
            ['996589560', '411787097', '996589560'],
            Ein::normalizeMany(['996589560', '41-1787097', '996589560']),
        );
    }

    public function testNormalizeManyReportsEveryFailureAtOnce(): void
    {
        try {
            Ein::normalizeMany(['411787097', 'nope', '996589560', '1234']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $error) {
            self::assertCount(2, $error->issues);
            self::assertSame([1, 3], array_map(
                static fn ($issue) => $issue->index,
                $error->issues,
            ));
            self::assertStringContainsString('2 of 4 EINs are invalid', $error->getMessage());
            self::assertStringContainsString('at index 1, 3', $error->getMessage());
            self::assertStringContainsString('No request was sent', $error->getMessage());
        }
    }

    public function testValidationIssuesSerializeWithoutLosingTheInput(): void
    {
        try {
            Ein::normalizeMany(['nope']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $error) {
            $serialized = $error->toArray();

            self::assertSame('validation', $serialized['category']);
            self::assertSame('local', $serialized['origin']);
            self::assertSame([['message' => $error->issues[0]->message, 'index' => 0, 'value' => 'nope']], $serialized['issues']);
        }
    }

    public function testEinLengthIsNine(): void
    {
        self::assertSame(9, Ein::LENGTH);
    }
}
