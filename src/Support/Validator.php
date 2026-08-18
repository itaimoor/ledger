<?php

declare(strict_types=1);

namespace Ledger\Support;

use DateTimeImmutable;
use Ledger\Exceptions\ValidationException;

/**
 * Whitelist validator for JSON request bodies.
 *
 * The declared rules are the whitelist: validate() rejects any field in the payload that
 * no rule mentions, so there is no second list to keep in sync. Values are only handed
 * back by validate(), which means a caller cannot use an unvalidated value by mistake.
 *
 *     $data = (new Validator($request->body()))
 *         ->enum('type', ['in', 'out'])
 *         ->int('amount_paisa', min: 1)
 *         ->date('entry_date')
 *         ->string('description', max: 500, required: false)
 *         ->validate();
 *
 * Types are checked strictly. "185000" is not an acceptable amount; a client that sends
 * money as a string is a client with a bug worth surfacing.
 */
final class Validator
{
    /** @var list<string> */
    private array $declared = [];

    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, string> */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public function string(string $field, int $max, int $min = 1, bool $required = true): self
    {
        if (!$this->present($field, $required)) {
            return $this;
        }

        $value = $this->data[$field];

        if (!is_string($value)) {
            return $this->fail($field, 'Must be text.');
        }

        $value = trim($value);
        $length = mb_strlen($value);

        if ($length < $min) {
            return $this->fail($field, "Must be at least {$min} characters.");
        }

        if ($length > $max) {
            return $this->fail($field, "Must be at most {$max} characters.");
        }

        return $this->accept($field, $value);
    }

    public function int(string $field, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX, bool $required = true): self
    {
        if (!$this->present($field, $required)) {
            return $this;
        }

        $value = $this->data[$field];

        if (!is_int($value)) {
            return $this->fail($field, 'Must be a whole number.');
        }

        if ($value < $min) {
            return $this->fail($field, "Must be at least {$min}.");
        }

        if ($value > $max) {
            return $this->fail($field, "Must be at most {$max}.");
        }

        return $this->accept($field, $value);
    }

    /** A database identifier: a positive integer, never a numeric string. */
    public function id(string $field, bool $required = true): self
    {
        return $this->int($field, min: 1, required: $required);
    }

    /** @param list<string> $options */
    public function enum(string $field, array $options, bool $required = true): self
    {
        if (!$this->present($field, $required)) {
            return $this;
        }

        $value = $this->data[$field];

        if (!is_string($value) || !in_array($value, $options, true)) {
            return $this->fail($field, 'Must be one of: ' . implode(', ', $options) . '.');
        }

        return $this->accept($field, $value);
    }

    public function bool(string $field, bool $required = true): self
    {
        if (!$this->present($field, $required)) {
            return $this;
        }

        $value = $this->data[$field];

        if (!is_bool($value)) {
            return $this->fail($field, 'Must be true or false.');
        }

        return $this->accept($field, $value);
    }

    /** ISO date, and a real one: 2026-02-30 is rejected. */
    public function date(
        string $field,
        ?string $min = null,
        ?string $max = null,
        bool $required = true,
    ): self {
        if (!$this->present($field, $required)) {
            return $this;
        }

        $value = $this->data[$field];

        if (!is_string($value)) {
            return $this->fail($field, 'Must be a date in YYYY-MM-DD format.');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            return $this->fail($field, 'Must be a real date in YYYY-MM-DD format.');
        }

        if ($min !== null && $value < $min) {
            return $this->fail($field, "Must be on or after {$min}.");
        }

        if ($max !== null && $value > $max) {
            return $this->fail($field, "Must be on or before {$max}.");
        }

        return $this->accept($field, $value);
    }

    public function email(string $field, bool $required = true): self
    {
        if (!$this->present($field, $required)) {
            return $this;
        }

        $value = $this->data[$field];

        if (!is_string($value)) {
            return $this->fail($field, 'Must be an email address.');
        }

        $value = mb_strtolower(trim($value));

        if (mb_strlen($value) > 255 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return $this->fail($field, 'Must be a valid email address.');
        }

        return $this->accept($field, $value);
    }

    /**
     * @return array<string, mixed> every declared field; absent optional fields are null
     *
     * @throws ValidationException
     */
    public function validate(): array
    {
        foreach (array_keys($this->data) as $key) {
            if (!in_array((string) $key, $this->declared, true)) {
                $this->errors[(string) $key] = 'Unknown field.';
            }
        }

        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }

        return $this->values;
    }

    /**
     * The returned array mirrors the request: a field the client omitted is absent from
     * it, and a field the client sent as null is present and null. PATCH handlers need
     * that difference — "leave the client name alone" and "clear the client name" are
     * different instructions.
     */
    private function present(string $field, bool $required): bool
    {
        $this->declared[] = $field;

        if (!array_key_exists($field, $this->data)) {
            if ($required) {
                $this->errors[$field] = 'This field is required.';
            }

            return false;
        }

        $value = $this->data[$field];

        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = 'This field is required.';
            } else {
                $this->values[$field] = null;
            }

            return false;
        }

        return true;
    }

    private function accept(string $field, mixed $value): self
    {
        $this->values[$field] = $value;

        return $this;
    }

    private function fail(string $field, string $message): self
    {
        $this->errors[$field] = $message;

        return $this;
    }
}
