<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Exception;

use JsonSerializable;
use RuntimeException;
use Throwable;

/**
 * Base class for every exception this SDK throws.
 *
 * Catch this to catch everything the SDK can raise. Every subclass carries a
 * stable {@see ErrorCategory} and an {@see ErrorOrigin}, so callers branch on
 * the exception class or on the category — never on message text.
 *
 * API keys are never placed into an exception message, an exception property, or
 * any `toArray()` output.
 */
abstract class PactmanException extends RuntimeException implements JsonSerializable
{
    public function __construct(
        string $message,
        public readonly ErrorCategory $category,
        public readonly ErrorOrigin $origin,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * A serializable view of the exception. Never contains the API key.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => static::class,
            'message' => $this->getMessage(),
            'category' => $this->category->value,
            'origin' => $this->origin->value,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** True when `$value` is any exception thrown by this SDK. */
    public static function isPactmanError(mixed $value): bool
    {
        return $value instanceof self;
    }
}
