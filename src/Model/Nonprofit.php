<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Model;

/**
 * A nonprofit record as returned by the US nonprofit check endpoints.
 *
 * Source-specific findings are flat on this object, prefixed by source
 * (`pub78_*`, `bmf_*`, `ofac_*`, and the revocation fields for the IRS Automatic
 * Revocation of Exemption list). See {@see \Pactman\NonprofitCheckPlus\Sources}
 * for grouped views.
 *
 * Every field is optional: the API omits fields it has no data for. Reading one
 * it did not return yields `null`; ask {@see DataObject::has()} to tell the two
 * apart. Fields introduced by a newer API version than this SDK knows about are
 * readable through {@see DataObject::get()}.
 *
 * Declared here is what the production API returns. Some deployments serve
 * additional source fields — the BMF address (`bmf_city`, `bmf_state`,
 * `bmf_street_address`), `bmf_source_pf_filing_req_cd`, `bmf_deductability_text`,
 * `pub78_source_org_type_1..3`, `ofac_list_published_date` and
 * `aroe_list_published_date`. They are not declared because production does not
 * return them; when it does, they stay readable through {@see DataObject::get()},
 * and this package will declare them in a release of its own.
 *
 * @property-read string|null $pactman_org_url Public Pactman profile URL for the organization.
 * @property-read string|null $organization_info_last_modified
 * @property-read string|null $ein
 * @property-read string|null $organization_name
 * @property-read string|null $organization_name_aka
 * @property-read string|null $address_line1
 * @property-read string|null $address_line2
 * @property-read string|null $city
 * @property-read string|null $state
 * @property-read string|null $state_name
 * @property-read string|null $zip
 * @property-read string|null $filing_req_code
 * @property-read string|null $pub78_church_message
 * @property-read string|null $pub78_organization_name
 * @property-read string|null $pub78_ein
 * @property-read bool|null $pub78_verified
 * @property-read string|null $pub78_city
 * @property-read string|null $pub78_state
 * @property-read string|null $pub78_indicator
 * @property-read list<array<string, mixed>|null>|null $organization_types Publication 78 deductibility entries.
 * @property-read string|null $most_recent_pub78
 * @property-read string|null $bmf_church_message
 * @property-read string|null $bmf_organization_name
 * @property-read string|null $bmf_ein
 * @property-read bool|null $bmf_status
 * @property-read string|null $bmf_subsection
 * @property-read string|null $most_recent_bmf
 * @property-read string|null $subsection_description
 * @property-read string|null $foundation_code
 * @property-read string|null $foundation_code_description
 * @property-read string|null $foundation_type_code
 * @property-read string|null $foundation_type_description
 * @property-read string|null $foundation_509a_status
 * @property-read string|null $ruling_month
 * @property-read string|null $ruling_year
 * @property-read string|null $group_exemption
 * @property-read string|null $exempt_status_code
 * @property-read string|null $ofac_status OFAC SDN finding. Prose, not a flag — see below.
 * @property-read string|null $revocation_code
 * @property-read string|null $revocation_date
 * @property-read string|null $reinstatement_date
 * @property-read bool|null $irs_bmf_pub78_conflict True when the IRS BMF and Publication 78 records disagree.
 * @property-read string|null $report_date
 */
final class Nonprofit extends DataObject
{
    /**
     * The Publication 78 deductibility entries, as the API sent them.
     *
     * Each entry carries `organization_type`, `deductibility_limitation` and
     * `deductibility_status_description`, plus anything a newer API version
     * added. Returns an empty list when the field was absent or null, so it is
     * always safe to iterate; ask `has('organization_types')` when the
     * difference matters.
     *
     * The API can also send a null in the list, for a Publication 78 row it
     * cannot resolve. Those are dropped rather than handed on, so every entry
     * this returns is an array and the indices are renumbered from zero — read
     * `get('organization_types')` when the gaps themselves matter.
     *
     * @return list<array<string, mixed>>
     */
    public function organizationTypes(): array
    {
        $entries = $this->get('organization_types');

        if (!is_array($entries)) {
            return [];
        }

        /** @var list<array<string, mixed>> $objects */
        $objects = array_values(array_filter($entries, 'is_array'));

        return $objects;
    }
}
