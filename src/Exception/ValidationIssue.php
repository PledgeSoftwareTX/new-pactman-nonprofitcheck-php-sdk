<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Exception;

use JsonSerializable;

/** One item that failed local validation. */
final class ValidationIssue implements JsonSerializable
{
    public function __construct(
        /** Human-readable reason the value was rejected. */
        public readonly string $message,
        /** Position in the input collection, for bulk calls. */
        public readonly ?int $index = null,
        /** The offending value, as supplied by the caller. */
        public readonly mixed $value = null,
    ) {
    }

    /** @return array{message: string, index: int|null, value: mixed} */
    public function toArray(): array
    {
        return ['message' => $this->message, 'index' => $this->index, 'value' => $this->value];
    }

    /** @return array{message: string, index: int|null, value: mixed} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
