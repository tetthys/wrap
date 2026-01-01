<?php
// src/Wrap.php

declare(strict_types=1);

namespace Tetthys\Wrap;

use InvalidArgumentException;
use ReflectionFunction;
use ReflectionMethod;
use Throwable;

final class Wrap
{
    private function __construct(
        private bool $ok = false,
        private mixed $value = null,
        private ?Throwable $error = null,
    ) {}

    public static function handle(callable $callback): self
    {
        try {
            return new self(true, $callback(), null);
        } catch (Throwable $e) {
            return new self(false, null, $e);
        }
    }

    public static function fromValue(mixed $value): self
    {
        return new self(true, $value, null);
    }

    public static function fromError(Throwable $error): self
    {
        return new self(false, null, $error);
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

    public function getValueOr(mixed $default): mixed
    {
        return ($this->ok && $this->value !== null) ? $this->value : $default;
    }

    public function getValueOrCall(callable $defaultFn): mixed
    {
        return ($this->ok && $this->value !== null) ? $this->value : $defaultFn();
    }

    public function getOrThrow(?callable $factory = null): mixed
    {
        if ($this->ok) {
            return $this->value;
        }

        if ($factory !== null) {
            throw $factory($this->error);
        }

        throw $this->error ?? new InvalidArgumentException('Wrap is in failed state.');
    }

    public function then(callable $callback): self
    {
        if (!$this->ok) {
            return $this;
        }

        return new self(true, $callback($this->value), null);
    }

    public function andThen(callable $callback): self
    {
        if (!$this->ok) {
            return $this;
        }

        $next = $callback($this->value);

        if (!$next instanceof self) {
            return $this->invalidate('Wrap::andThen callback must return an instance of Wrap');
        }

        return $next;
    }

    public function rescue(callable $fallback): self
    {
        if ($this->ok || $this->error === null) {
            return $this;
        }

        try {
            $v = self::callFallback($fallback, $this->error);
            return new self(true, $v, null);
        } catch (Throwable $e) {
            return $this->invalidate('Exception in rescue()', $e);
        }
    }

    public function mapError(callable $mapper): self
    {
        if ($this->ok || $this->error === null) {
            return $this;
        }

        try {
            $mapped = $mapper($this->error);
        } catch (Throwable $e) {
            return $this->invalidate('Exception in mapError()', $e);
        }

        if (!$mapped instanceof Throwable) {
            return $this->invalidate('mapError() mapper must return Throwable');
        }

        return new self(false, null, $mapped);
    }

    public function map(callable $mapper): self
    {
        if (!$this->ok) {
            return $this;
        }

        if (!is_iterable($this->value)) {
            return $this->invalidate('Wrap::map requires iterable value');
        }

        $out = [];
        foreach ($this->value as $k => $v) {
            $out[$k] = $mapper($v, $k);
        }

        return new self(true, $out, null);
    }

    public function filter(callable $predicate): self
    {
        if (!$this->ok) {
            return $this;
        }

        if (!is_iterable($this->value)) {
            return $this->invalidate('Wrap::filter requires iterable value');
        }

        $out = [];
        foreach ($this->value as $k => $v) {
            if ($predicate($v, $k)) {
                $out[$k] = $v;
            }
        }

        return new self(true, $out, null);
    }

    public function reduce(callable $reducer, mixed $initial): self
    {
        if (!$this->ok) {
            return $this;
        }

        if (!is_iterable($this->value)) {
            return $this->invalidate('Wrap::reduce requires iterable value');
        }

        $acc = $initial;
        foreach ($this->value as $k => $v) {
            $acc = $reducer($acc, $v, $k);
        }

        return new self(true, $acc, null);
    }

    private function invalidate(string $message, ?Throwable $previous = null): self
    {
        return new self(false, null, new InvalidArgumentException($message, 0, $previous));
    }

    private static function callFallback(callable $fallback, Throwable $error): mixed
    {
        // Supports 0-arg fallback and 1-arg fallback(Throwable), including array-callables.
        $ref = is_array($fallback)
            ? new ReflectionMethod($fallback[0], $fallback[1])
            : new ReflectionFunction($fallback);

        return $ref->getNumberOfParameters() === 0
            ? $fallback()
            : $fallback($error);
    }
}
