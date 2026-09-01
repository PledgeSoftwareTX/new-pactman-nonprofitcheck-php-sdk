<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Tests;

use Pactman\NonprofitCheckPlus\Model\Nonprofit;
use Pactman\NonprofitCheckPlus\Sources;
use Pactman\NonprofitCheckPlus\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class SourcesTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @param list<string>         $omit      Fields to delete outright, so the record can express
     *     "the API returned no field at all" as distinct from "the API returned null".
     */
    private static function nonprofit(array $overrides = [], array $omit = []): Nonprofit
    {
        $record = Fixtures::nonprofit($overrides);

        foreach ($omit as $field) {
            unset($record[$field]);
        }

        return new Nonprofit($record);
    }

    public function testPub78CopiesFieldsWithoutRenamingTheirMeaning(): void
    {
        $pub78 = Sources::pub78(self::nonprofit());

        self::assertNotNull($pub78);
        self::assertTrue($pub78->verified);
        self::assertSame('Example Nonprofit', $pub78->organization_name);
        self::assertSame('411787097', $pub78->ein);
        self::assertSame('Westfield', $pub78->city);
        self::assertSame('MA', $pub78->state);
        self::assertSame('0', $pub78->indicator);
        self::assertSame('12/12/2025 12:00:00 AM', $pub78->most_recent);
        self::assertIsArray($pub78->organization_types);
    }

    public function testBmfCopiesFieldsFromBothPrefixedAndBareNames(): void
    {
        $bmf = Sources::bmf(self::nonprofit());

        self::assertNotNull($bmf);
        self::assertTrue($bmf->status);
        self::assertSame('EXAMPLE NONPROFIT', $bmf->organization_name);
        self::assertSame('03', $bmf->subsection);
        // Bare wire names that belong to the BMF are grouped here too.
        self::assertSame('501(c)(3) Public Charity', $bmf->subsection_description);
        self::assertSame('10', $bmf->foundation_code);
        self::assertSame('2024', $bmf->ruling_year);
        self::assertSame('00', $bmf->filing_req_code);
        self::assertSame('12/09/2025 12:00:00 AM', $bmf->most_recent);
    }

    public function testAroeAndOfacProjectTheirOwnFields(): void
    {
        $nonprofit = self::nonprofit([
            'revocation_code' => 'A',
            'revocation_date' => '5/15/2024 12:00:00 AM',
            'reinstatement_date' => null,
        ]);

        $aroe = Sources::aroe($nonprofit);
        $ofac = Sources::ofac($nonprofit);

        self::assertNotNull($aroe);
        self::assertSame('A', $aroe->revocation_code);
        self::assertSame('5/15/2024 12:00:00 AM', $aroe->revocation_date);
        self::assertNull($aroe->reinstatement_date);

        self::assertNotNull($ofac);
        self::assertStringContainsString('NOT included', (string) $ofac->status);
    }

    public function testReturnsNullOnlyWhenTheSourceWasNotReturnedAtAll(): void
    {
        $nonprofit = self::nonprofit(omit: [
            'pub78_church_message', 'pub78_organization_name', 'pub78_ein', 'pub78_verified',
            'pub78_city', 'pub78_state', 'pub78_indicator', 'organization_types', 'most_recent_pub78',
        ]);

        self::assertNull(Sources::pub78($nonprofit));
        self::assertNotNull(Sources::bmf($nonprofit), 'the other sources are unaffected');
    }

    public function testAnExplicitNegativeIsNotAnAbsentSource(): void
    {
        $pub78 = Sources::pub78(self::nonprofit(['pub78_verified' => false]));

        self::assertNotNull($pub78, 'false is a finding, not an absence');
        self::assertFalse($pub78->verified);
        self::assertTrue($pub78->has('verified'));
    }

    public function testANullFieldIsStillAReturnedField(): void
    {
        $ofac = Sources::ofac(self::nonprofit(['ofac_status' => null]));

        self::assertNotNull($ofac);
        self::assertTrue($ofac->has('status'), 'the API returned the field, as null');
        self::assertNull($ofac->status);
    }

    public function testOnlyTheReturnedKeysAppearInAProjection(): void
    {
        $pub78 = Sources::pub78(self::nonprofit(omit: ['pub78_city', 'pub78_state']));

        self::assertNotNull($pub78);
        self::assertFalse($pub78->has('city'));
        self::assertFalse($pub78->has('state'));
        self::assertTrue($pub78->has('verified'));
    }

    public function testProjectionsDoNotDeriveAnything(): void
    {
        // A record where every source disagrees. Nothing collapses them.
        $nonprofit = self::nonprofit([
            'bmf_status' => true,
            'pub78_verified' => false,
            'irs_bmf_pub78_conflict' => true,
            'revocation_code' => 'A',
        ]);

        self::assertTrue(Sources::bmf($nonprofit)?->status);
        self::assertFalse(Sources::pub78($nonprofit)?->verified);
        self::assertSame('A', Sources::aroe($nonprofit)?->revocation_code);
        self::assertTrue($nonprofit->irs_bmf_pub78_conflict);
    }

    public function testAProjectionIsIterableAndSerializable(): void
    {
        $aroe = Sources::aroe(self::nonprofit(['revocation_code' => 'A']));

        self::assertNotNull($aroe);
        self::assertSame('A', $aroe['revocation_code']);
        self::assertSame('A', $aroe->get('revocation_code'));
        self::assertArrayHasKey('revocation_code', $aroe->toArray());
        self::assertStringContainsString('revocation_code', (string) json_encode($aroe, JSON_THROW_ON_ERROR));
        self::assertGreaterThan(0, count($aroe));

        $seen = [];

        foreach ($aroe as $key => $_value) {
            $seen[] = $key;
        }

        self::assertContains('revocation_code', $seen);
    }

    public function testAProjectionCannotBeMutated(): void
    {
        $aroe = Sources::aroe(self::nonprofit());

        self::assertNotNull($aroe);
        $this->expectException(\LogicException::class);

        $aroe['revocation_code'] = 'tampered';
    }

    public function testOrganizationTypesAreExposedAsTheApiSentThem(): void
    {
        $nonprofit = self::nonprofit(['organization_types' => [
            ['organization_type' => 'x', 'deductibility_limitation' => '50%', 'future_note' => 'kept'],
            'not an object',
        ]]);

        $entries = $nonprofit->organizationTypes();

        self::assertCount(1, $entries, 'non-object entries are skipped');
        self::assertSame('50%', $entries[0]['deductibility_limitation']);
        self::assertSame('kept', $entries[0]['future_note'], 'unknown members survive');
    }

    public function testOrganizationTypesIsSafeToIterateWhenNull(): void
    {
        self::assertSame([], self::nonprofit(['organization_types' => null])->organizationTypes());
        self::assertSame([], self::nonprofit(omit: ['organization_types'])->organizationTypes());
    }
}
