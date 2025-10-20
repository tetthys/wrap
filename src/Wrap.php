<?php

declare(strict_types=1);

namespace Tetthys\Wrap;

use InvalidArgumentException;
use Throwable;

/**
 * Minimal fluent Result-like wrapper with functional map/filter/reduce
 * and conditional helpers (when, unless, whenTrue, whenFalse, branch).
 *
 * Design notes:
 * - This is a mutable wrapper: each operation mutates the current instance.
 * - Errors are captured as Throwables; failure state disables most operations unless rescued.
 * - Iterable operators (map/filter/reduce) require the stored value to be iterable.
 * - Conditional helpers are side-effect oriented by design and do NOT modify the stored value.
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
     * @param callable(): TResult $callback Callback to execute safely.
     * @return self<TResult, Throwable> A Wrap initialized as success with the callback's return value,
     *                                  or as failure with the thrown Throwable captured.
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

    /**
     * Construct a success Wrap from a raw value.
     *
     * @template TValue
     * @param TValue $value
     * @return self<TValue, Throwable>
     */
    public static function fromValue(mixed $value): self
    {
        $self = new self();
        $self->ok = true;
        $self->value = $value;
        return $self;
    }

    /**
     * Construct a failure Wrap from a Throwable.
     *
     * @template TErr of Throwable
     * @param TErr $error
     * @return self<mixed, TErr>
     */
    public static function fromError(Throwable $error): self
    {
        $self = new self();
        $self->ok = false;
        $self->error = $error;
        return $self;
    }

    /**
     * Run a side-effect callback only when successful.
     *
     * @param callable(TSuccess): void $callback Receives the stored success value.
     * @return self<TSuccess, TError>
     */
    public function ok(callable $callback): self
    {
        if ($this->ok) {
            $callback($this->value);
        }
        return $this;
    }

    /**
     * Run a side-effect callback only when failed.
     *
     * @param callable(TError): void $callback Receives the captured Throwable.
     * @return self<TSuccess, TError>
     */
    public function fail(callable $callback): self
    {
        if (!$this->ok && $this->error) {
            /** @var TError $err */
            $err = $this->error;
            $callback($err);
        }
        return $this;
    }

    /**
     * Rescue from failure by providing a fallback value, flipping to success.
     * Clears the previous error and allows the chain to continue.
     *
     * @param callable(TError): TSuccess $callback Receives the error and returns a replacement value.
     * @return self<TSuccess, TError>
     */
    public function rescue(callable $callback): self
    {
        if (!$this->ok && $this->error) {
            /** @var TError $err */
            $err = $this->error;
            $this->value = $callback($err);
            $this->ok = true; // allow further chaining
            $this->error = null; // clear previous error
        }
        return $this;
    }

    /**
     * Always run (finally-style). Does not change chain state (void).
     *
     * @param callable(bool, ?Throwable, mixed): void $callback Receives (ok, error, value).
     */
    public function always(callable $callback): void
    {
        $callback($this->ok, $this->error, $this->value);
    }

    /**
     * Always run (finally-style) but continue the chain.
     * If the callback throws, the chain is invalidated with the thrown Throwable preserved as previous.
     *
     * @param callable(bool, ?Throwable, mixed): void $callback Receives (ok, error, value).
     * @return self<TSuccess, TError>
     */
    public function finally(callable $callback): self
    {
        try {
            $callback($this->ok, $this->error, $this->value);
        } catch (Throwable $e) {
            return $this->invalidate(
                "Exception in finally(): {$e->getMessage()}",
                $e,
            );
        }
        return $this;
    }

    /** @return bool Whether the chain is in success state. */
    public function isOk(): bool
    {
        return $this->ok;
    }

    /**
     * @return TError|null The captured error if any, otherwise null.
     */
    public function getError(): ?Throwable
    {
        return $this->error;
    }

    /**
     * @return TSuccess|mixed The raw stored value; may be null when failed or by intent.
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * Return value or default when failed or when value is null.
     * NOTE: If your domain treats "success + null" as a valid result, prefer getValueOrNull()/getOrThrow().
     *
     * @template TDefault
     * @param TDefault $default Fallback value if failed or value is null.
     * @return TSuccess|TDefault
     */
    public function getValueOr(mixed $default): mixed
    {
        return $this->ok && $this->value !== null ? $this->value : $default;
    }

    /**
     * Lazy fallback variant: compute default only if needed.
     *
     * @template TDefault
     * @param callable(): TDefault $defaultFn
     * @return TSuccess|TDefault
     */
    public function getValueOrCall(callable $defaultFn): mixed
    {
        return $this->ok && $this->value !== null ? $this->value : $defaultFn();
    }

    /**
     * Return the stored value on success even if null; null on failure.
     *
     * @return TSuccess|null
     */
    public function getValueOrNull(): mixed
    {
        return $this->ok ? $this->value : null;
    }

    /**
     * Return the stored value on success; otherwise throw the captured error or a custom one.
     *
     * @param null|callable(?Throwable): Throwable $factory Optional factory to map the captured error to a different exception.
     * @return TSuccess
     * @throws Throwable If failed and no factory is provided, throws the captured error (or InvalidArgumentException if missing).
     */
    public function getOrThrow(?callable $factory = null): mixed
    {
        if ($this->ok) {
            return $this->value;
        }
        if ($factory) {
            throw $factory($this->error);
        }
        throw $this->error ??
            new InvalidArgumentException("Wrap is in failed state.");
    }

    // -----------------------------------------------------
    // Functional core
    // -----------------------------------------------------

    /**
     * Transform each element when the current value is iterable.
     * The result is re-materialized into an array (keys preserved).
     *
     * @param callable(mixed, mixed=): mixed $mapper Receives (value, key) and returns new value.
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
     * Safer variant of map(): catches exceptions in mapper and invalidates instead of throwing.
     *
     * @param callable(mixed, mixed=): mixed $mapper
     * @return self<TSuccess, TError>
     */
    public function safeMap(callable $mapper): self
    {
        if (!$this->ok) {
            return $this;
        }
        if (!is_iterable($this->value)) {
            return $this->invalidate("Wrap::safeMap requires iterable value");
        }

        $result = [];
        try {
            foreach ($this->value as $k => $v) {
                $result[$k] = $mapper($v, $k);
            }
        } catch (Throwable $e) {
            return $this->invalidate(
                "Exception in safeMap(): {$e->getMessage()}",
                $e,
            );
        }
        $this->value = $result;
        return $this;
    }

    /**
     * Transform the stored (scalar or any) value when successful.
     * Conceptually similar to map(), but not limited to iterables.
     *
     * @template TNext
     * @param callable(TSuccess): TNext $callback Receives the stored value and returns next value.
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
     * Safer variant of then(): catches exceptions in callback and invalidates instead of throwing.
     *
     * @template TNext
     * @param callable(TSuccess): TNext $callback
     * @return self<TNext, TError>
     */
    public function safeThen(callable $callback): self
    {
        if (!$this->ok) {
            return $this;
        }
        try {
            /** @var self<TNext, TError> $this */
            $this->value = $callback($this->value);
        } catch (Throwable $e) {
            return $this->invalidate(
                "Exception in safeThen(): {$e->getMessage()}",
                $e,
            );
        }
        return $this;
    }

    /**
     * Keep items that satisfy the predicate (iterables only; keys preserved).
     *
     * @param callable(mixed, mixed=): bool $predicate Receives (value, key).
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
     * Safer variant of filter(): catches exceptions in predicate and invalidates instead of throwing.
     *
     * @param callable(mixed, mixed=): bool $predicate
     * @return self<TSuccess, TError>
     */
    public function safeFilter(callable $predicate): self
    {
        if (!$this->ok) {
            return $this;
        }
        if (!is_iterable($this->value)) {
            return $this->invalidate("Wrap::safeFilter requires iterable value");
        }

        $result = [];
        try {
            foreach ($this->value as $k => $v) {
                if ($predicate($v, $k)) {
                    $result[$k] = $v;
                }
            }
        } catch (Throwable $e) {
            return $this->invalidate(
                "Exception in safeFilter(): {$e->getMessage()}",
                $e,
            );
        }
        $this->value = $result;
        return $this;
    }

    /**
     * Reduce an iterable into a single accumulator.
     *
     * @template TAcc
     * @param callable(TAcc, mixed, mixed=): TAcc $reducer Receives (accumulator, value, key).
     * @param TAcc $initial Initial accumulator value.
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

    /**
     * Safer variant of reduce(): catches exceptions in reducer and invalidates instead of throwing.
     *
     * @template TAcc
     * @param callable(TAcc, mixed, mixed=): TAcc $reducer
     * @param TAcc $initial
     * @return self<TAcc, TError>
     */
    public function safeReduce(callable $reducer, mixed $initial): self
    {
        if (!$this->ok) {
            return $this;
        }
        if (!is_iterable($this->value)) {
            return $this->invalidate("Wrap::safeReduce requires iterable value");
        }

        $acc = $initial;
        try {
            foreach ($this->value as $k => $v) {
                $acc = $reducer($acc, $v, $k);
            }
        } catch (Throwable $e) {
            return $this->invalidate(
                "Exception in safeReduce(): {$e->getMessage()}",
                $e,
            );
        }

        /** @var self<TAcc, TError> $this */
        $this->value = $acc;
        return $this;
    }

    // -----------------------------------------------------
    // Conditional helpers (side-effect oriented)
    // -----------------------------------------------------

    /**
     * Conditionally run a side-effect callback when predicate(value) === true.
     * Does not modify the stored value; returns self for fluent chaining.
     * If the callback or predicate throws, the chain is invalidated (previous error preserved).
     *
     * @param callable(TSuccess): bool  $predicate
     * @param callable(TSuccess): void $callback
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
            return $this->invalidate("Exception in when(): {$e->getMessage()}", $e);
        }

        return $this;
    }

    /**
     * Conditionally run a side-effect callback when predicate(value) === false.
     * Does not modify the stored value; returns self for fluent chaining.
     * If the callback or predicate throws, the chain is invalidated (previous error preserved).
     *
     * @param callable(TSuccess): bool  $predicate
     * @param callable(TSuccess): void $callback
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
            return $this->invalidate("Exception in unless(): {$e->getMessage()}", $e);
        }

        return $this;
    }

    /**
     * Boolean-specialized helper: run callback when (bool) value === true.
     * Equivalent to: when(fn($v) => (bool)$v === true, $callback)
     *
     * @param callable(TSuccess): void $callback
     * @return self<TSuccess, TError>
     */
    public function whenTrue(callable $callback): self
    {
        return $this->when(static fn($v): bool => (bool) $v === true, $callback);
    }

    /**
     * Boolean-specialized helper: run callback when (bool) value === false.
     * Equivalent to: unless(fn($v) => (bool)$v === true, $callback)
     *
     * @param callable(TSuccess): void $callback
     * @return self<TSuccess, TError>
     */
    public function whenFalse(callable $callback): self
    {
        return $this->unless(static fn($v): bool => (bool) $v === true, $callback);
    }

    /**
     * if/else-style branching helper for boolean-like values.
     * Executes $onTrue if (bool)value === true, otherwise $onFalse.
     * Does not modify the stored value; returns self for fluent chaining.
     * If any branch throws, the chain is invalidated with that Throwable preserved as previous.
     *
     * @param callable(TSuccess): void $onTrue
     * @param callable(TSuccess): void $onFalse
     * @return self<TSuccess, TError>
     */
    public function branch(callable $onTrue, callable $onFalse): self
    {
        if (!$this->ok) {
            return $this;
        }

        try {
            if ((bool) $this->value === true) {
                $onTrue($this->value);
            } else {
                $onFalse($this->value);
            }
        } catch (Throwable $e) {
            return $this->invalidate("Exception in branch(): {$e->getMessage()}", $e);
        }

        return $this;
    }

    /**
     * Flip to failed state with a standardized InvalidArgumentException.
     * The original Throwable (if any) is attached as "previous" for better debugging.
     *
     * @param string $message Error message explaining why the chain was invalidated.
     * @param Throwable|null $previous Optional previous Throwable to preserve root cause.
     * @return self<TSuccess, TError>
     */
    private function invalidate(
        string $message,
        ?Throwable $previous = null,
    ): self {
        $this->ok = false;
        $this->error = new InvalidArgumentException($message, 0, $previous);
        return $this;
    }
}
