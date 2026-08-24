<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Model;

/**
 * A grouped view over one source's findings on a {@see Nonprofit}.
 *
 * A projection, not a derivation: every key is copied 1:1 from a field the API
 * returned. Nothing here computes an "approved", "eligible" or "safe" verdict,
 * and nothing infers a value from another field.
 *
 * Only the keys the API actually returned are present, so `has()` still answers
 * "did the API send this?" exactly as it does on the organization itself.
 */
abstract class SourceView extends DataObject
{
}
