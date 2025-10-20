<?php

declare(strict_types=1);

namespace Tetthys\Wrap;

use InvalidArgumentException;
use Throwable;

/**
 * Minimal fluent Result-like wrapper with functional map/filter/reduce.
 *
 * @template TSuccess
 * @template TError of Throwable
 */
final class Wrap
{
    /** Whether the operation succeeded. */
    private bool $ok = false;

    /** Captured exception on failure. */
    private ?Throwable $error = null;

    /** Stored value (any type). */
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

    /** Run side-effect only when successful. */
    public function ok(callable $callback): self
    {
        if ($this->ok) {
            $callback($this->value);
        }
        return $this;
    }

    /** Run side-effect only when failed. */
    public function fail(callable $callback): self
    {
        if (!$this->ok && $this->error) {
            $callback($this->error);
        }
        return $this;
    }

    /**
     * Provide a fallback value on failure.
     * Also flips the state to success so that subsequent chains run.
     */
    public function rescue(callable $callback): self
    {
        if (!$this->ok && $this->error) {
            $this->value = $callback($this->error);
            $this->ok = true; // make chain continue after rescue
            $this->error = null; // clear previous error
        }
        return $this;
    }

    /** Always run (finally-style). */
    public function always(callable $callback): void
    {
        $callback($this->ok, $this->error, $this->value);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function getError(): ?Throwable
    {
        return $this->error;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

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
    // Functional core
    // -----------------------------------------------------

    /**
     * Transform each element when the current value is iterable.
     * (kept for array/collection pipelines)
     *
     * @param callable(mixed, mixed=): mixed $mapper
     * @return self<TSuccess, TError>
     */
    public function map(callable $mapper): self
    {
        if (!$this->ok) {
            return $this;
        }
        if (!is_iterable($this->value)) {
            return $this->invalidate("Wrap::map requires iterable value");
        }

        $result = [];
        foreach ($this->value as $k => $v) {
            $result[$k] = $mapper($v, $k);
        }
        $this->value = $result;
        return $this;
    }

    /**
     * Transform the stored (scalar or any) value when successful.
     * This matches the README’s fluent examples (alias of “map” conceptually,
     * but does not require an iterable).
     *
     * @template TNext
     * @param callable(TSuccess): TNext $callback
     * @return self<TNext, TError>
     */
    public function then(callable $callback): self
    {
        if (!$this->ok) {
            return $this;
        }

        /** @var self<TNext, TError> $this */
        $this->value = $callback($this->value);
        return $this;
    }

    /**
     * Keep items that satisfy the predicate (iterables only).
     *
     * @param callable(mixed, mixed=): bool $predicate
     * @return self<TSuccess, TError>
     */
    public function filter(callable $predicate): self
    {
        if (!$this->ok) {
            return $this;
        }
        if (!is_iterable($this->value)) {
            return $this->invalidate("Wrap::filter requires iterable value");
        }

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
     * Reduce an iterable into a single accumulator.
     *
     * @template TAcc
     * @param callable(TAcc, mixed, mixed=): TAcc $reducer
     * @param TAcc $initial
     * @return self<TAcc, TError>
     */
    public function reduce(callable $reducer, mixed $initial): self
    {
        if (!$this->ok) {
            return $this;
        }
        if (!is_iterable($this->value)) {
            return $this->invalidate("Wrap::reduce requires iterable value");
        }

        $acc = $initial;
        foreach ($this->value as $k => $v) {
            $acc = $reducer($acc, $v, $k);
        }

        /** @var self<TAcc, TError> $this */
        $this->value = $acc;
        return $this;
    }

    // -----------------------------------------------------
    // Conditional helpers (PHP 8.3+)
    // -----------------------------------------------------

    /**
     * Conditionally run a side-effect callback when predicate(value) === true.
     * Does not modify the stored value; returns self for fluent chaining.
     *
     * @param callable(TSuccess): bool  $predicate
     * @param callable(TSuccess): mixed $callback
     * @return self<TSuccess, TError>
     */
    public function when(callable $predicate, callable $callback): self
    {
        if (!$this->ok) {
            return $this;
        }

        try {
            if ($predicate($this->value)) {
                $callback($this->value);
            }
        } catch (Throwable $e) {
            return $this->invalidate("Exception in when(): {$e->getMessage()}");
        }

        return $this;
    }

    /**
     * Conditionally run a side-effect callback when predicate(value) === false.
     * Does not modify the stored value; returns self for fluent chaining.
     *
     * @param callable(TSuccess): bool  $predicate
     * @param callable(TSuccess): mixed $callback
     * @return self<TSuccess, TError>
     */
    public function unless(callable $predicate, callable $callback): self
    {
        if (!$this->ok) {
            return $this;
        }

        try {
            if (!$predicate($this->value)) {
                $callback($this->value);
            }
        } catch (Throwable $e) {
            return $this->invalidate("Exception in unless(): {$e->getMessage()}");
        }

        return $this;
    }

    /**
     * Boolean-specialized helper: run callback when (bool) value === true.
     * Equivalent to: when(fn($v) => (bool)$v === true, $callback)
     *
     * @param callable(TSuccess): mixed $callback
     * @return self<TSuccess, TError>
     */
    public function whenTrue(callable $callback): self
    {
        return $this->when(
            static fn($v): bool => (bool) $v === true,
            $callback
        );
    }

    /**
     * Boolean-specialized helper: run callback when (bool) value === false.
     * Equivalent to: unless(fn($v) => (bool)$v === true, $callback)
     *
     * @param callable(TSuccess): mixed $callback
     * @return self<TSuccess, TError>
     */
    public function whenFalse(callable $callback): self
    {
        return $this->unless(
            static fn($v): bool => (bool) $v === true,
            $callback
        );
    }

    /** Flip to failed state with a standardized InvalidArgumentException. */
    private function invalidate(string $message): self
    {
        $this->ok = false;
        $this->error = new InvalidArgumentException($message);
        return $this;
    }
}
