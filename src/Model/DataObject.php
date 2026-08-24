<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Model;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use Traversable;

/**
 * An immutable view over a decoded JSON object.
 *
 * Field names mirror the wire format exactly, so what you read in the Pactman
 * API reference is what you access in code — there is no rename table to keep in
 * sync. Fields the API adds in a future version stay readable through
 * {@see get()} without a deserialization failure or an SDK upgrade.
 *
 * Read a field however suits the call site — all three reach the same value:
 *
 * ```php
 * $nonprofit->organization_name;          // property syntax, autocompleted
 * $nonprofit['organization_name'];        // array syntax
 * $nonprofit->get('some_future_field');   // anything this release does not declare
 * ```
 *
 * **`isset()` here answers "did the API return this field?"** — it reports true
 * for a field returned as `null`, unlike PHP's usual array semantics. That
 * distinction is load-bearing for this API: "no data for this source" and "this
 * source says null" route differently, and collapsing them loses a finding. Use
 * {@see get()} to read the value, {@see has()} or `isset()` to ask whether the
 * API sent the field at all.
 *
 * @implements ArrayAccess<string, mixed>
 * @implements IteratorAggregate<string, mixed>
 */
abstract class DataObject implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /** @param array<string, mixed> $fields */
    public function __construct(protected readonly array $fields = [])
    {
    }

    /** True when the API returned this field, including when it returned it as `null`. */
    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    /**
     * The field's value, or `$default` when the API did not return the field.
     *
     * A field returned as `null` yields `null`, not `$default`.
     */
    public function get(string $field, mixed $default = null): mixed
    {
        return array_key_exists($field, $this->fields) ? $this->fields[$field] : $default;
    }

    /**
     * The unmodified fields, exactly as they were decoded.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->fields;
    }

    public function __get(string $field): mixed
    {
        return $this->get($field);
    }

    public function __isset(string $field): bool
    {
        return $this->has($field);
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && $this->has($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return is_string($offset) ? $this->get($offset) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new LogicException(static::class . ' is an immutable view of an API response.');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new LogicException(static::class . ' is an immutable view of an API response.');
    }

    public function count(): int
    {
        return count($this->fields);
    }

    /** @return Traversable<string, mixed> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->fields);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->fields;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->fields;
    }
}
