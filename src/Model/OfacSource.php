<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Model;

/**
 * OFAC Specially Designated Nationals findings.
 *
 * @property-read string|null $status The finding as the API phrases it. This is prose, not a
 *     flag; the API does not currently return a boolean match indicator, and this SDK does not
 *     invent one by matching on the wording.
 * @property-read string|null $list_published_date
 */
final class OfacSource extends SourceView
{
}
