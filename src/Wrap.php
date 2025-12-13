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

    /**
     * Flat-map variant of then().
     * The callback MUST return another Wrap instance.
     *
     * Use this to avoid breaking the chain when an operation already returns Wrap:
     *   Wrap::handle(...)
     *     ->andThen(fn($v) => Wrap::handle(...))
     *     ->then(...)
     *
     * @template TNext
     * @param callable(TSuccess): self<TNext, Throwable> $callback
     * @return self<TNext, Throwable>
     */
    public function andThen(callable $callback): self
    {
        if (!$this->ok) {
            return $this;
        }

        try {
            $next = $callback($this->value);

            if (!$next instanceof self) {
                return $this->invalidate(
                    'Wrap::andThen callback must return an instance of Wrap'
                );
            }

            $this->ok = $next->ok;
            $this->error = $next->error;
            $this->value = $next->value;

            /** @var self<TNext, Throwable> $this */
            return $this;
        } catch (Throwable $e) {
            return $this->invalidate(
                "Exception in andThen(): {$e->getMessage()}",
                $e,
            );
        }
    }

    /**
     * Ensure that the predicate holds for the current value.
     * If it returns false, the chain is invalidated.
     *
     * This is useful to replace patterns like:
     *   ->then(fn($v) => condition ? $v : null)
     * with an explicit failure:
     *   ->ensure(fn($v) => condition, 'reason...')
     *
     * @param callable(TSuccess): bool $predicate
     * @param string|callable(TSuccess): string $message
     * @return self<TSuccess, Throwable>
     */
    public function ensure(callable $predicate, string|callable $message = 'Ensure failed'): self
    {
        if (!$this->ok) {
            return $this;
        }

        try {
            if (!$predicate($this->value)) {
                $msg = is_callable($message)
                    ? $message($this->value)
                    : $message;

                return $this->invalidate($msg);
            }
        } catch (Throwable $e) {
            return $this->invalidate(
                "Exception in ensure(): {$e->getMessage()}",
                $e,
            );
        }

        return $this;
    }

    /**
     * Transform the captured error without changing the failure state.
     *
     * Typical uses:
     * - Normalize infra exceptions into domain exceptions
     * - Add more context/message
     *
     * @param callable(Throwable): Throwable $mapper
     * @return self<TSuccess, TError>
     */
    public function mapError(callable $mapper): self
    {
        if ($this->ok || !$this->error) {
            return $this;
        }

        try {
            $this->error = $mapper($this->error);
        } catch (Throwable $e) {
            return $this->invalidate(
                "Exception in mapError(): {$e->getMessage()}",
                $e,
            );
        }

        return $this;
    }

    /**
     * Recover from failure only when the error matches a condition.
     *
     * Examples:
     *   ->recoverWhen(DepositQueryException::class, fn() => '0')
     *   ->recoverWhen(fn($e) => $e->getCode() === 429, fn() => '0')
     *
     * @param class-string<Throwable>|callable(Throwable): bool $when
     * @param callable(Throwable): TSuccess $fallback
     * @return self<TSuccess, Throwable>
     */
    public function recoverWhen(string|callable $when, callable $fallback): self
    {
        if ($this->ok || !$this->error) {
            return $this;
        }

        $err = $this->error;

        $match = is_string($when)
            ? $err instanceof $when
            : (bool) $when($err);

        if (!$match) {
            return $this;
        }

        /** @var TError $typedErr */
        $typedErr = $err;

        $this->value = $fallback($typedErr);
        $this->ok = true;
        $this->error = null;

        return $this;
    }

    /**
     * Run a side-effect callback only when successful.
     * If the callback throws, the chain is invalidated.
     *
     * Use for side effects you DO want to be part of the chain safety.
     *
     * @param callable(TSuccess): void $callback
     * @return self<TSuccess, TError>
     */
    public function tap(callable $callback): self
    {
        if (!$this->ok) {
            return $this;
        }

        try {
            $callback($this->value);
        } catch (Throwable $e) {
            return $this->invalidate(
                "Exception in tap(): {$e->getMessage()}",
                $e,
            );
        }

        return $this;
    }

    /**
     * Run a side-effect callback only when successful.
     * Exceptions thrown by the callback are swallowed and do NOT affect chain state.
     *
     * Use for logging/metrics/tracing where failure must not break main flow.
     *
     * @param callable(TSuccess): void $callback
     * @return self<TSuccess, TError>
     */
    public function tryTap(callable $callback): self
    {
        if ($this->ok) {
            try {
                $callback($this->value);
            } catch (Throwable) {
                // intentionally ignored
            }
        }

        return $this;
    }

    /**
     * Run a side-effect callback only when failed AND the error matches a condition.
     *
     * Examples:
     *   ->failWhen(DepositQueryException::class, fn(DepositQueryException $e) => report($e))
     *   ->failWhen(fn(Throwable $e) => $e->getCode() === 429, fn(Throwable $e) => sleep(1))
     *
     * @template TMatch of Throwable
     * @param class-string<TMatch>|callable(Throwable): bool $when
     * @param callable(TMatch): void $callback
     * @return self<TSuccess, TError>
     */
    public function failWhen(string|callable $when, callable $callback): self
    {
        if ($this->ok || !$this->error) {
            return $this;
        }

        $err = $this->error;

        $match = is_string($when)
            ? $err instanceof $when
            : (bool) $when($err);

        if (!$match) {
            return $this;
        }

        try {
            /** @var TMatch $typed */
            $typed = $err;
            $callback($typed);
        } catch (Throwable $e) {
            return $this->invalidate(
                "Exception in failWhen(): {$e->getMessage()}",
                $e,
            );
        }

        return $this;
    }

    /**
     * Rethrow the captured error when it matches a condition.
     *
     * Use when certain exceptions MUST escape the Wrap boundary.
     *
     * Examples:
     *   ->rethrowWhen(DepositQueryException::class)
     *
     * @param class-string<Throwable>|callable(Throwable): bool $when
     * @return self<TSuccess, TError>
     * @throws Throwable
     */
    public function rethrowWhen(string|callable $when): self
    {
        if ($this->ok || !$this->error) {
            return $this;
        }

        $err = $this->error;

        $match = is_string($when)
            ? $err instanceof $when
            : (bool) $when($err);

        if ($match) {
            throw $err;
        }

        return $this;
    }

    /**
     * Keep the current value only if predicate returns true.
     * If predicate returns false, the value becomes null (success state).
     *
     * This is useful for optional pipelines:
     *   Wrap::handle(...)->keep(fn($v) => $v !== null)->then(...)
     *
     * Note: This does NOT fail the chain; it just turns the value into null.
     *
     * @param callable(TSuccess): bool $predicate
     * @return self<TSuccess|null, TError>
     */
    public function keep(callable $predicate): self
    {
        if (!$this->ok) {
            return $this;
        }

        if (!$predicate($this->value)) {
            $this->value = null;
        }

        return $this;
    }

    /**
     * Safe variant of keep(): invalidates if predicate throws.
     *
     * @param callable(TSuccess): bool $predicate
     * @return self<TSuccess|null, TError>
     */
    public function safeKeep(callable $predicate): self
    {
        if (!$this->ok) {
            return $this;
        }

        try {
            if (!$predicate($this->value)) {
                $this->value = null;
            }
        } catch (Throwable $e) {
            return $this->invalidate(
                "Exception in safeKeep(): {$e->getMessage()}",
                $e,
            );
        }

        return $this;
    }

    /**
     * Runs callback only when current value is not null (success state).
     * Does not modify the stored value.
     *
     * @param callable(TSuccess): void $callback
     * @return self<TSuccess, TError>
     */
    public function whenValue(callable $callback): self
    {
        if (!$this->ok) {
            return $this;
        }

        if ($this->value === null) {
            return $this;
        }

        try {
            $callback($this->value);
        } catch (Throwable $e) {
            return $this->invalidate(
                "Exception in whenValue(): {$e->getMessage()}",
                $e,
            );
        }

        return $this;
    }

    /**
     * Transforms the value only when current value is not null (success state).
     * If value is null, keeps it null.
     *
     * @template TNext
     * @param callable(TSuccess): TNext $callback
     * @return self<TNext|null, TError>
     */
    public function whenValueThen(callable $callback): self
    {
        if (!$this->ok) {
            return $this;
        }

        if ($this->value === null) {
            /** @var self<TNext|null, TError> $this */
            return $this;
        }

        try {
            /** @var self<TNext|null, TError> $this */
            $this->value = $callback($this->value);
        } catch (Throwable $e) {
            return $this->invalidate(
                "Exception in whenValueThen(): {$e->getMessage()}",
                $e,
            );
        }

        return $this;
    }

    /**
     * Runs callback on failure; if callback throws, invalidates with previous preserved.
     *
     * @param callable(TError): void $callback
     * @return self<TSuccess, TError>
     */
    public function tapError(callable $callback): self
    {
        if ($this->ok || !$this->error) {
            return $this;
        }

        try {
            /** @var TError $err */
            $err = $this->error;
            $callback($err);
        } catch (Throwable $e) {
            return $this->invalidate(
                "Exception in tapError(): {$e->getMessage()}",
                $e,
            );
        }

        return $this;
    }

    /**
     * Runs callback on failure; swallows callback exceptions.
     *
     * @param callable(TError): void $callback
     * @return self<TSuccess, TError>
     */
    public function tryTapError(callable $callback): self
    {
        if ($this->ok || !$this->error) {
            return $this;
        }

        try {
            /** @var TError $err */
            $err = $this->error;
            $callback($err);
        } catch (Throwable $e) {
            // swallow
        }

        return $this;
    }
}
