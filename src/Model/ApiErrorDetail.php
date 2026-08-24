<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Model;

use JsonSerializable;

/**
 * One entry from the response envelope's `errors` array.
 *
 * Item-level failures appear on successful responses too — a bulk request where
 * some EINs were not found returns HTTP 200 with entries here.
 */
final class ApiErrorDetail implements JsonSerializable
{
    /**
     * @param string|null          $resource The API resource the error came from.
     * @param string|null          $reason   Human-readable explanation.
     * @param int|null             $code     Status code for this specific failure, which may differ from the HTTP status.
     * @param list<string>         $eins     EINs this error applies to, for bulk requests. The API sends either a list or a comma-separated string; both arrive here as a list.
     * @param array<string, mixed> $raw      The entry exactly as the API sent it, including any field not named above.
     */
    public function __construct(
        public readonly ?string $resource = null,
        public readonly ?string $reason = null,
        public readonly ?int $code = null,
        public readonly array $eins = [],
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $entry */
    public static function fromArray(array $entry): self
    {
        $resource = $entry['resource'] ?? null;
        $reason = $entry['reason'] ?? null;
        $code = $entry['code'] ?? null;

        return new self(
            resource: is_string($resource) ? $resource : null,
            reason: is_string($reason) ? $reason : null,
            code: is_int($code) ? $code : null,
            eins: self::normalizeEins($entry['eins'] ?? null),
            raw: $entry,
        );
    }

    /**
     * The entry exactly as the API sent it. This is the form to log.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }

    /** @return list<string> */
    private static function normalizeEins(mixed $eins): array
    {
        if (is_array($eins)) {
            return array_values(array_filter($eins, 'is_string'));
        }

        if (is_string($eins)) {
            $parts = array_map('trim', explode(',', $eins));

            return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
        }

        return [];
    }
}
