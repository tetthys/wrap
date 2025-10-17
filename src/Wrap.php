<?php

declare(strict_types=1);

namespace Tetthys\Wrap;

use InvalidArgumentException;
use Throwable;

/**
 * Minimal fluent Result-like wrapper with functional map/filter/reduce.
 *
 * Example:
 * ```php
 * $sum = Wrap::handle(fn() => [1, 2, 3, 4, 5])
 *     ->filter(fn($v) => $v % 2 === 0)
 *     ->map(fn($v) => $v ** 2)
 *     ->reduce(fn($acc, $v) => $acc + $v, 0)
 *     ->getValueOr(0); // 20
 * ```
 *
 * @template TSuccess
 * @template TError of Throwable
 */
final class Wrap
{
    private bool $ok = false;
    private ?Throwable $error = null;
    private mixed $value = null;

    /**
     * Run a callback safely and capture success or exception.
     *
     * @template TResult
     * @param callable(): TResult $callback
     * @return self<TResult, Throwable>
     */
    public static function handle(callable $callback): self
    {
        $self = new self();
        try {
            $self->value = $callback();
            $self->ok = true;
        } catch (Throwable $e) {
            $self->error = $e;
        }
        return $self;
    }

    /** Side-effect on success */
    public function ok(callable $callback): self
    {
        if ($this->ok) {
            $callback($this->value);
        }
        return $this;
    }

    /** Side-effect on failure */
    public function fail(callable $callback): self
    {
        if (!$this->ok && $this->error) {
            $callback($this->error);
        }
        return $this;
    }

    /** Fallback value factory on failure */
    public function rescue(callable $callback): self
    {
        if (!$this->ok && $this->error) {
            $this->value = $callback($this->error);
        }
        return $this;
    }

    /** Always run (finally) */
    public function always(callable $callback): void
    {
        $callback($this->ok, $this->error, $this->value);
    }

    public function isOk(): bool { return $this->ok; }
    public function getError(): ?Throwable { return $this->error; }
    public function getValue(): mixed { return $this->value; }

    /**
     * Return value or default when failed/null.
     *
     * @template TDefault
     * @param TDefault $default
     * @return TSuccess|TDefault
     */
    public function getValueOr(mixed $default): mixed
    {
        return $this->ok && $this->value !== null ? $this->value : $default;
    }

    // -----------------------------------------------------
    // Functional core: map / filter / reduce
    // -----------------------------------------------------

    /** @param callable(mixed, mixed=): mixed $mapper */
    public function map(callable $mapper): self
    {
        if (!$this->ok) return $this;
        if (!is_iterable($this->value)) return $this->invalidate('Wrap::map requires iterable value');

        $result = [];
        foreach ($this->value as $k => $v) {
            $result[$k] = $mapper($v, $k);
        }
        $this->value = $result;
        return $this;
    }

    /** @param callable(mixed, mixed=): bool $predicate */
    public function filter(callable $predicate): self
    {
        if (!$this->ok) return $this;
        if (!is_iterable($this->value)) return $this->invalidate('Wrap::filter requires iterable value');

        $result = [];
        foreach ($this->value as $k => $v) {
            if ($predicate($v, $k)) {
                $result[$k] = $v;
            }
        }
        $this->value = $result;
        return $this;
    }

    /**
     * @template TAcc
     * @param callable(TAcc, mixed, mixed=): TAcc $reducer
     * @param TAcc $initial
     * @return self<TAcc, TError>
     */
    public function reduce(callable $reducer, mixed $initial): self
    {
        if (!$this->ok) return $this;
        if (!is_iterable($this->value)) return $this->invalidate('Wrap::reduce requires iterable value');

        $acc = $initial;
        foreach ($this->value as $k => $v) {
            $acc = $reducer($acc, $v, $k);
        }

        /** @var self<TAcc, TError> $this */
        $this->value = $acc;
        return $this;
    }

    private function invalidate(string $message): self
    {
        $this->ok = false;
        $this->error = new InvalidArgumentException($message);
        return $this;
    }
}
