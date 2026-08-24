<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Model;

/**
 * IRS Publication 78 findings.
 *
 * @property-read bool|null $verified
 * @property-read string|null $organization_name
 * @property-read string|null $ein
 * @property-read string|null $city
 * @property-read string|null $state
 * @property-read string|null $indicator
 * @property-read string|null $church_message
 * @property-read string|null $source_org_type_1
 * @property-read string|null $source_org_type_2
 * @property-read string|null $source_org_type_3
 * @property-read list<array<string, mixed>>|null $organization_types
 * @property-read string|null $most_recent
 */
final class Pub78Source extends SourceView
{
}
