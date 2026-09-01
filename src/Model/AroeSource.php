<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Model;

/**
 * IRS Automatic Revocation of Exemption findings.
 *
 * @property-read string|null $revocation_code
 * @property-read string|null $revocation_date
 * @property-read string|null $reinstatement_date
 */
final class AroeSource extends SourceView
{
}
