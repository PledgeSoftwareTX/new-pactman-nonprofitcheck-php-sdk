<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Model;

/**
 * IRS Business Master File findings.
 *
 * @property-read bool|null $status
 * @property-read string|null $organization_name
 * @property-read string|null $ein
 * @property-read string|null $city
 * @property-read string|null $state
 * @property-read string|null $street_address
 * @property-read string|null $church_message
 * @property-read string|null $subsection
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
 * @property-read string|null $filing_req_code
 * @property-read string|null $pf_filing_req_cd
 * @property-read string|null $deductability_text
 * @property-read string|null $most_recent
 */
final class BmfSource extends SourceView
{
}
