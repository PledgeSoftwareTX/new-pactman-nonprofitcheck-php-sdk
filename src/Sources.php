<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus;

use Pactman\NonprofitCheckPlus\Model\AroeSource;
use Pactman\NonprofitCheckPlus\Model\BmfSource;
use Pactman\NonprofitCheckPlus\Model\Nonprofit;
use Pactman\NonprofitCheckPlus\Model\OfacSource;
use Pactman\NonprofitCheckPlus\Model\Pub78Source;

/**
 * Grouped views over the source-specific findings on a {@see Nonprofit}.
 *
 * These are projections, not derivations: every key is copied 1:1 from a field
 * the API returned. Nothing here computes an "approved", "eligible" or "safe"
 * verdict, and nothing infers a value from another field.
 *
 * Each accessor returns `null` when the API returned no data at all for that
 * source. That keeps "the source was not returned" distinguishable from an
 * explicit negative such as `pub78_verified: false` or a `null` field.
 */
final class Sources
{
    /** @var array<string, string> */
    private const PUB78_FIELDS = [
        'verified' => 'pub78_verified',
        'organization_name' => 'pub78_organization_name',
        'ein' => 'pub78_ein',
        'city' => 'pub78_city',
        'state' => 'pub78_state',
        'indicator' => 'pub78_indicator',
        'church_message' => 'pub78_church_message',
        'source_org_type_1' => 'pub78_source_org_type_1',
        'source_org_type_2' => 'pub78_source_org_type_2',
        'source_org_type_3' => 'pub78_source_org_type_3',
        'organization_types' => 'organization_types',
        'most_recent' => 'most_recent_pub78',
    ];

    /** @var array<string, string> */
    private const BMF_FIELDS = [
        'status' => 'bmf_status',
        'organization_name' => 'bmf_organization_name',
        'ein' => 'bmf_ein',
        'city' => 'bmf_city',
        'state' => 'bmf_state',
        'street_address' => 'bmf_street_address',
        'church_message' => 'bmf_church_message',
        'subsection' => 'bmf_subsection',
        'subsection_description' => 'subsection_description',
        'foundation_code' => 'foundation_code',
        'foundation_code_description' => 'foundation_code_description',
        'foundation_type_code' => 'foundation_type_code',
        'foundation_type_description' => 'foundation_type_description',
        'foundation_509a_status' => 'foundation_509a_status',
        'ruling_month' => 'ruling_month',
        'ruling_year' => 'ruling_year',
        'group_exemption' => 'group_exemption',
        'exempt_status_code' => 'exempt_status_code',
        'filing_req_code' => 'filing_req_code',
        'pf_filing_req_cd' => 'bmf_source_pf_filing_req_cd',
        'deductability_text' => 'bmf_deductability_text',
        'most_recent' => 'most_recent_bmf',
    ];

    /** @var array<string, string> */
    private const AROE_FIELDS = [
        'revocation_code' => 'revocation_code',
        'revocation_date' => 'revocation_date',
        'reinstatement_date' => 'reinstatement_date',
        'list_published_date' => 'aroe_list_published_date',
    ];

    /** @var array<string, string> */
    private const OFAC_FIELDS = [
        'status' => 'ofac_status',
        'list_published_date' => 'ofac_list_published_date',
    ];

    /** Publication 78 findings, or `null` if the API returned none. */
    public static function pub78(Nonprofit $nonprofit): ?Pub78Source
    {
        $fields = self::project($nonprofit, self::PUB78_FIELDS);

        return $fields === null ? null : new Pub78Source($fields);
    }

    /** Business Master File findings, or `null` if the API returned none. */
    public static function bmf(Nonprofit $nonprofit): ?BmfSource
    {
        $fields = self::project($nonprofit, self::BMF_FIELDS);

        return $fields === null ? null : new BmfSource($fields);
    }

    /** Automatic Revocation of Exemption findings, or `null` if the API returned none. */
    public static function aroe(Nonprofit $nonprofit): ?AroeSource
    {
        $fields = self::project($nonprofit, self::AROE_FIELDS);

        return $fields === null ? null : new AroeSource($fields);
    }

    /** OFAC findings, or `null` if the API returned none. */
    public static function ofac(Nonprofit $nonprofit): ?OfacSource
    {
        $fields = self::project($nonprofit, self::OFAC_FIELDS);

        return $fields === null ? null : new OfacSource($fields);
    }

    /**
     * Copies the mapped fields into a new array, preserving `null` and `false`.
     *
     * Returns `null` only when every mapped field is absent from the response,
     * which is how "this source was not returned" is represented.
     *
     * @param array<string, string> $mapping
     *
     * @return array<string, mixed>|null
     */
    private static function project(Nonprofit $nonprofit, array $mapping): ?array
    {
        $projected = [];

        foreach ($mapping as $target => $wireField) {
            if ($nonprofit->has($wireField)) {
                $projected[$target] = $nonprofit->get($wireField);
            }
        }

        return $projected === [] ? null : $projected;
    }
}
