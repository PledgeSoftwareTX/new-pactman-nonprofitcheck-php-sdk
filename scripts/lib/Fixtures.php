<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Dev;

/**
 * Fixture organizations for the examples and the mock server.
 *
 * Scenarios like a revoked exemption, an OFAC match, a cross-source conflict or
 * an unknown future field cannot be summoned on demand from the production API.
 * They are declared here once so an example can demonstrate the handling and the
 * mock server can serve the record.
 *
 * Field names and values mirror the shapes documented in the Pactman API
 * reference. The EINs are illustrative and are not real organizations.
 */
final class Fixtures
{
    /** The two OFAC sentences the API returns. It reports prose, not a boolean. */
    public const OFAC_NO_MATCH = 'This organization was NOT included in the Office of Foreign Assets '
        . 'Control Specially Designated Nationals (SDN) list.';

    public const OFAC_POSSIBLE_MATCH = 'This organization may be included in the Office of Foreign '
        . 'Assets Control Specially Designated Nationals(SDN) list. A close match was found with '
        . 'the Special Designated National with UID: 41234';

    /**
     * Named EINs the examples refer to, so no example hard-codes a bare number.
     *
     * @var array<string, string>
     */
    public const EINS = [
        /** A 501(c)(3) public charity with every source returned and nothing adverse. */
        'publicCharity' => '411787097',
        /** A second clean organization, for bulk examples. */
        'publicCharitySecond' => '996589560',
        /** A 501(c)(3) private foundation — different foundation and filing codes. */
        'privateFoundation' => '042103594',
        /** A record with most optional identity fields returned as null. */
        'sparseIdentity' => '060646700',
        /** Address fields that are present but disagree with each other. */
        'inconsistentAddress' => '311580204',
        /** Every source date is old, for the freshness and re-review examples. */
        'staleData' => '362167048',
        /** Listed in the IRS Automatic Revocation of Exemption data, not reinstated. */
        'revoked' => '237112796',
        /** Revoked and subsequently reinstated — both dates present. */
        'reinstated' => '133039601',
        /** A possible OFAC SDN match. */
        'ofacMatch' => '954367818',
        /** OFAC screening returned no value for this organization. */
        'ofacUnavailable' => '061553389',
        /** BMF and Publication 78 disagree; `irs_bmf_pub78_conflict` is true. */
        'conflicted' => '521693387',
        /** Carries fields and an enum value this SDK version does not know about. */
        'futureFields' => '237324370',
        /** Production's shape plus the source fields only newer deployments return. */
        'pendingSourceFields' => '046001341',
        /** Well-formed, but no record exists. */
        'noRecord' => '999999999',
    ];

    /**
     * EINs the mock server answers with a specific failure, for the error examples.
     *
     * @var array<string, string>
     */
    public const CONTROL_EINS = [
        /** Always answers HTTP 429 with `Retry-After: 1`. */
        'rateLimited' => '900000429',
        /** Answers HTTP 503 twice, then succeeds. */
        'transientFailure' => '900000503',
        /** Holds the response open, so a short timeout expires. */
        'slow' => '900000408',
    ];

    /** @var array<string, mixed> */
    private const DEDUCTIBILITY_PUBLIC_CHARITY = [
        'organization_type' => 'Deductions for donations to public charities are generally limited '
            . 'to 50 percent of adjusted gross income (AGI). This limit increases to 60% of AGI for '
            . 'cash donations. For Non-Cash assets held for more than one year, the limit is 30% of AGI.',
        'deductibility_limitation' => '50%',
        'deductibility_status_description' => 'PC',
    ];

    /** @var array<string, mixed> */
    private const DEDUCTIBILITY_PRIVATE_FOUNDATION = [
        'organization_type' => 'Deductions for donations to private foundations are generally '
            . 'limited to 30 percent of adjusted gross income (AGI). For Non-Cash assets held for '
            . 'more than one year, the limit is 20% of AGI.',
        'deductibility_limitation' => '30%',
        'deductibility_status_description' => 'PF',
    ];

    /**
     * The API formats every date as `M/DD/YYYY h:mm:ss AM`.
     *
     * Fixture dates are generated relative to today, so the freshness examples
     * stay meaningful however long after they were written they are run.
     */
    public static function apiDate(int $daysAgo): string
    {
        $date = new \DateTimeImmutable("-{$daysAgo} days");

        return $date->format('n/d/Y') . ' ' . ltrim($date->format('h:i:s A'), '0');
    }

    /** True when the mock server has a record for this EIN. */
    public static function has(string $ein): bool
    {
        return array_key_exists($ein, self::organizations());
    }

    /**
     * A record for `$ein`. Each call builds a fresh array, so an example that
     * mutates one cannot affect a later call.
     *
     * @return array<string, mixed>
     */
    public static function organization(string $ein): array
    {
        return self::organizations()[$ein];
    }

    /**
     * Every field this package predicts on an organization.
     *
     * Read from `src/response-contract.json` rather than from a fixture, so that
     * what the SDK claims to know is stated in one place. A fixture is an example
     * of a record; the contract is the promise, and the promise is what drift is
     * measured against. See EX-25: a field outside this set is newer than this
     * SDK, which is not an error, but is worth knowing about.
     *
     * @return list<string>
     */
    public static function knownNonprofitFields(): array
    {
        $contract = json_decode(
            (string) file_get_contents(__DIR__ . '/../../src/response-contract.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        /** @var array{nonprofit: array<string, mixed>} $contract */
        return array_keys($contract['nonprofit']);
    }

    /**
     * Every organization the mock server can return, keyed by EIN.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function organizations(): array
    {
        // PHP canonicalizes a numeric-string array key to an int, so the map is
        // keyed by array-key and looked up by the same rule. Callers always pass
        // an EIN string, which resolves either way.
        /** @var array<string, array<string, mixed>> $organizations */
        $organizations = [
            self::EINS['publicCharity'] => self::publicCharity(
                self::EINS['publicCharity'],
                'Meals Today Example Nonprofit',
                [
                    'organization_name_aka' => 'MEALS TODAY E.N',
                    'pub78_organization_name' => 'Meals Today Example Nonprofit, Inc.',
                ],
            ),

            self::EINS['publicCharitySecond'] => self::publicCharity(
                self::EINS['publicCharitySecond'],
                'Aborjaily Example Nonprofit',
                [
                    'organization_name_aka' => 'ABORJAILY E.N',
                    'city' => 'SPRINGFIELD',
                    'pub78_city' => 'Springfield',
                    'zip' => '01103-1420',
                    'address_line1' => '19 HAMPDEN ST',
                    'address_line2' => null,
                ],
            ),

            self::EINS['privateFoundation'] => self::publicCharity(
                self::EINS['privateFoundation'],
                'Hartwell Family Example Foundation',
                [
                    'organization_name_aka' => null,
                    // A private foundation files a 990-PF, so it carries no general
                    // 990 filing requirement.
                    'filing_req_code' => '00',
                    'organization_types' => [self::DEDUCTIBILITY_PRIVATE_FOUNDATION],
                    'subsection_description' => '501(c)(3) Private Foundation',
                    'foundation_code' => '04',
                    'foundation_code_description' => 'Private non-operating foundation',
                    'foundation_type_code' => 'pf',
                    'foundation_type_description' => 'Private non-operating foundation',
                    'foundation_509a_status' => 'N/A',
                    'ruling_month' => '11',
                    'ruling_year' => '1998',
                ],
            ),

            // Optional identity fields the API had no value for. `null` here means
            // "the API returned no value", which is not the same as "this did not match".
            self::EINS['sparseIdentity'] => self::publicCharity(
                self::EINS['sparseIdentity'],
                'Quiet Harbor Example Trust',
                [
                    'organization_name_aka' => null,
                    'address_line1' => 'PO BOX 118',
                    'address_line2' => null,
                    'city' => 'ROCKPORT',
                    'state' => 'ME',
                    'state_name' => null,
                    'zip' => null,
                    'pub78_city' => null,
                    'pub78_state' => null,
                    'group_exemption' => null,
                    'ruling_month' => null,
                    'ruling_year' => null,
                ],
                // No OFAC key at all: the source was not reported for this
                // organization, which is not the same as a null status or a
                // no-match result.
                ['ofac_status'],
            ),

            // Every address component is present, and they contradict one another:
            // the state code says Massachusetts, the state name and the ZIP say
            // Maine, and address_line2 holds a placeholder. Transcription damage of
            // this kind survives any check that only asks whether a field came back
            // non-null.
            self::EINS['inconsistentAddress'] => self::publicCharity(
                self::EINS['inconsistentAddress'],
                'Harbor Light Example Alliance',
                [
                    'organization_name_aka' => null,
                    'address_line1' => '12 SEA STREET',
                    'address_line2' => 'N/A',
                    'city' => 'ROCKPORT',
                    'state' => 'MA',
                    'state_name' => 'Maine',
                    'zip' => '04856',
                    'pub78_city' => 'Rockport',
                    'pub78_state' => 'MA',
                ],
            ),

            // Nothing adverse, but every source is well out of date. A workflow with
            // a re-review rule should notice this even though the findings look clean.
            self::EINS['staleData'] => self::publicCharity(
                self::EINS['staleData'],
                'Long Quiet Example Foundation',
                [
                    'organization_info_last_modified' => self::apiDate(700),
                    'most_recent_pub78' => self::apiDate(640),
                    'most_recent_bmf' => self::apiDate(610),
                ],
            ),

            self::EINS['revoked'] => self::publicCharity(
                self::EINS['revoked'],
                'Lapsed Filings Example Society',
                [
                    'organization_name_aka' => null,
                    'pub78_verified' => false,
                    'pub78_indicator' => null,
                    'organization_types' => null,
                    'bmf_status' => false,
                    'subsection_description' => '501(c)(3) Public Charity',
                    'exempt_status_code' => '25',
                    'revocation_code' => '01',
                    'revocation_date' => self::apiDate(1260),
                    'reinstatement_date' => null,
                ],
            ),

            self::EINS['reinstated'] => self::publicCharity(
                self::EINS['reinstated'],
                'Second Chance Example Alliance',
                [
                    'organization_name_aka' => 'SECOND CHANCE E.A',
                    'revocation_code' => '01',
                    'revocation_date' => self::apiDate(1260),
                    'reinstatement_date' => self::apiDate(520),
                ],
            ),

            self::EINS['ofacMatch'] => self::publicCharity(
                self::EINS['ofacMatch'],
                'Overseas Relief Example Fund',
                [
                    'organization_name_aka' => 'OVERSEAS RELIEF E.F',
                    'ofac_status' => self::OFAC_POSSIBLE_MATCH,
                ],
            ),

            // The API returned nothing for OFAC. Absent is not the same as "no match".
            self::EINS['ofacUnavailable'] => self::publicCharity(
                self::EINS['ofacUnavailable'],
                'Riverbend Example Coalition',
                [
                    'ofac_status' => null,
                ],
            ),

            // BMF says exempt, Publication 78 does not list the organization, and
            // the API flags the disagreement rather than picking a winner.
            self::EINS['conflicted'] => self::publicCharity(
                self::EINS['conflicted'],
                'Crosscheck Example Institute',
                [
                    'organization_name' => 'CROSSCHECK EXAMPLE INSTITUTE',
                    'organization_name_aka' => null,
                    'pub78_verified' => false,
                    'pub78_organization_name' => null,
                    'pub78_city' => null,
                    'pub78_state' => null,
                    'pub78_indicator' => null,
                    'organization_types' => null,
                    'most_recent_pub78' => self::apiDate(26),
                    'bmf_organization_name' => 'CROSSCHECK EXAMPLE INST',
                    'bmf_status' => true,
                    'irs_bmf_pub78_conflict' => true,
                ],
            ),

            // A response from a newer API version: fields this SDK has never heard
            // of, and an enum value outside the documented set.
            self::EINS['futureFields'] => self::publicCharity(
                self::EINS['futureFields'],
                'Forward Compatible Example Trust',
                [
                    'foundation_type_code' => 'zz',
                    'foundation_type_description' => 'A classification added after this SDK was published',
                    'organization_types' => [
                        [
                            ...self::DEDUCTIBILITY_PUBLIC_CHARITY,
                            'deductibility_status_description' => 'XX',
                            'future_deductibility_note' => 'An unknown member of a known object',
                        ],
                    ],
                    'state_charity_registration_status' => 'ACTIVE',
                    'watchlist_screening' => [
                        'provider' => 'example',
                        'matches' => 0,
                        'list_published_date' => self::apiDate(5),
                    ],
                ],
            ),

            // A deployment running ahead of production. Every other fixture is the
            // shape entities.pactman.org returns today; this one adds the ten source
            // fields that are built but not yet released there. This package
            // deliberately does not declare them (see Model\Nonprofit), so they
            // exercise the path that keeps undeclared fields readable through get()
            // instead of dropping them.
            self::EINS['pendingSourceFields'] => self::publicCharity(
                self::EINS['pendingSourceFields'],
                'Ahead Of Production Example Fund',
                [
                    'organization_name_aka' => null,
                    'pub78_source_org_type_1' => 'PC',
                    'pub78_source_org_type_2' => null,
                    'pub78_source_org_type_3' => null,
                    'bmf_city' => 'WESTFIELD',
                    'bmf_state' => 'MA',
                    'bmf_street_address' => '50 LOWELL AVE APT 3B',
                    'bmf_source_pf_filing_req_cd' => '0',
                    'bmf_deductability_text' => 'Contributions are deductible',
                    'ofac_list_published_date' => self::apiDate(5),
                    'aroe_list_published_date' => self::apiDate(12),
                ],
            ),
        ];

        return $organizations;
    }

    /**
     * A complete, unremarkable public charity. Scenarios override from here.
     *
     * @param array<string, mixed> $overrides
     * @param list<string>         $omit      Keys to delete outright, so the record can express
     *     "the API returned no field at all" as distinct from "the API returned null".
     *
     * @return array<string, mixed>
     */
    private static function publicCharity(
        string $ein,
        string $name,
        array $overrides = [],
        array $omit = [],
    ): array {
        $organization = [
            'pactman_org_url' => sprintf(
                'https://pactman.org/profile/nonprofit/%s-%s',
                self::slug($name),
                substr($ein, -4),
            ),
            'organization_info_last_modified' => self::apiDate(40),

            'ein' => $ein,
            'organization_name' => strtoupper($name),
            'organization_name_aka' => null,
            'address_line1' => '50 LOWELL AVE',
            'address_line2' => 'APT 3B',
            'city' => 'WESTFIELD',
            'state' => 'MA',
            'state_name' => 'Massachusetts',
            'zip' => '01085-2643',
            'filing_req_code' => '01',

            'pub78_church_message' => null,
            'pub78_organization_name' => $name,
            'pub78_ein' => $ein,
            'pub78_verified' => true,
            'pub78_city' => 'Westfield',
            'pub78_state' => 'MA',
            'pub78_indicator' => '0',
            'organization_types' => [self::DEDUCTIBILITY_PUBLIC_CHARITY],
            'most_recent_pub78' => self::apiDate(26),

            'bmf_church_message' => null,
            'bmf_organization_name' => strtoupper($name),
            'bmf_ein' => $ein,
            'bmf_status' => true,
            'bmf_subsection' => '03',
            'most_recent_bmf' => self::apiDate(20),
            'subsection_description' => '501(c)(3) Public Charity',
            'foundation_code' => '10',
            'foundation_code_description' => 'Public charity described in section 509(a)(1) or (2)',
            'foundation_type_code' => 'pc',
            'foundation_type_description' => 'Public charity described in section 509(a)(1) or (2)',
            'foundation_509a_status' => 'N/A',
            'ruling_month' => '07',
            'ruling_year' => '2024',
            'group_exemption' => '0000',
            'exempt_status_code' => '01',

            'ofac_status' => self::OFAC_NO_MATCH,

            'revocation_code' => null,
            'revocation_date' => null,
            'reinstatement_date' => null,

            'irs_bmf_pub78_conflict' => false,
            'report_date' => self::apiDate(0),

            ...$overrides,
        ];

        foreach ($omit as $key) {
            unset($organization[$key]);
        }

        return $organization;
    }

    private static function slug(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
    }
}
