<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Tests;

use Pactman\NonprofitCheckPlus\Model\Nonprofit;
use Pactman\NonprofitCheckPlus\Sources;
use PHPUnit\Framework\TestCase;

/**
 * Holds `src/response-contract.json` and the documented model in sync.
 *
 * The contract is what this package promises each response looks like. The
 * `@property-read` annotations on {@see Nonprofit} are what an editor tells a
 * developer to expect. A field in one and not the other is drift, and drift is
 * how an SDK starts lying about the API.
 */
final class ResponseContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function contract(): array
    {
        $decoded = json_decode(
            (string) file_get_contents(__DIR__ . '/../src/response-contract.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded, 'src/response-contract.json is not a JSON object.');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return array<string, mixed> */
    private static function section(string $name): array
    {
        $section = self::contract()[$name] ?? null;

        self::assertIsArray($section, "The contract declares no `{$name}` section.");

        /** @var array<string, mixed> $section */
        return $section;
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function documentedProperties(string $class): array
    {
        $doc = (new \ReflectionClass($class))->getDocComment();

        self::assertIsString($doc, "{$class} has no docblock.");

        // The type may contain spaces (`list<array<string, mixed>>|null`), so
        // scan lazily up to the `$name` that ends every annotation.
        preg_match_all('/@property-read\s+.+?\$(\w+)/', $doc, $matches);

        return $matches[1];
    }

    public function testTheContractAndTheModelDeclareTheSameFields(): void
    {
        $contract = array_keys(self::section('nonprofit'));
        $documented = self::documentedProperties(Nonprofit::class);

        sort($contract);
        sort($documented);

        self::assertSame(
            $contract,
            $documented,
            'src/response-contract.json and Nonprofit\'s @property-read list have drifted apart.',
        );
    }

    public function testEveryProjectedFieldExistsInTheContract(): void
    {
        $contract = array_keys(self::section('nonprofit'));
        $reflection = new \ReflectionClass(Sources::class);

        foreach (['PUB78_FIELDS', 'BMF_FIELDS', 'AROE_FIELDS', 'OFAC_FIELDS'] as $constant) {
            /** @var array<string, string> $mapping */
            $mapping = $reflection->getConstant($constant);

            foreach ($mapping as $target => $wireField) {
                self::assertContains(
                    $wireField,
                    $contract,
                    "Sources::{$constant}['{$target}'] maps to '{$wireField}', which the contract does not declare.",
                );
            }
        }
    }

    public function testTheEnvelopeContractCoversTheFieldsTheResultReads(): void
    {
        $envelope = self::section('envelope');

        foreach (['code', 'message', 'errors', 'timeTaken', 'nonprofit_check_count'] as $field) {
            self::assertArrayHasKey($field, $envelope);
        }
    }

    public function testTheErrorDetailContractCoversTheFieldsTheModelReads(): void
    {
        $detail = self::section('errorDetail');

        foreach (['resource', 'reason', 'code', 'eins'] as $field) {
            self::assertArrayHasKey($field, $detail);
        }
    }

    public function testTheContractStatesShapesAndNeverValues(): void
    {
        foreach (self::section('nonprofit') as $field => $shape) {
            self::assertIsString($shape, "{$field} declares a non-string shape.");
            self::assertNotSame('', trim($shape), "{$field} declares an empty shape.");
        }
    }
}
