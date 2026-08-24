<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Exception;

/**
 * Input failed local validation. No HTTP request was sent.
 *
 * Distinguishable from an API-side 400 by `$error->origin === ErrorOrigin::Local`.
 */
final class ValidationException extends PactmanException
{
    /** @var list<ValidationIssue> */
    public readonly array $issues;

    /** @param list<ValidationIssue> $issues */
    public function __construct(string $message, array $issues = [])
    {
        parent::__construct($message, ErrorCategory::Validation, ErrorOrigin::Local);
        $this->issues = $issues;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [...parent::toArray(), 'issues' => array_map(
            static fn (ValidationIssue $issue): array => $issue->toArray(),
            $this->issues,
        )];
    }
}
