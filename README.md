# Wrap

A minimal, fluent **Result-like wrapper** for PHP 8.3+.

* Safely execute code and keep either a value (**ok**) or a captured exception (**fail**)
* Transform values (`then`, `map`, `filter`, `reduce`)
* Use expressive flow helpers (`when`, `unless`, `branch`)
* Add modern chain helpers (`andThen`, `ensure`, `recoverWhen`, `mapError`, `rethrowWhen`, `tap`, `tryTap`, `failWhen`)
* Support optional pipelines (`keep`, `whenValue`, `whenValueThen`) and error side-effects (`tapError`, `tryTapError`)
* **Make exception behavior explicit** with `safe*`, `try*`, and `rethrow*` helpers

---

## Installation

```bash
composer require tetthys/wrap
````

---

## Quick Start

```php
use Tetthys\Wrap\Wrap;

$result = Wrap::handle(fn() => riskyOperation())
    ->tryOk(fn($v) => logger()->info('ok', ['value' => $v]))       // swallows callback exceptions
    ->tryFail(fn($e) => logger()->warning('fail', ['msg' => $e->getMessage()])) // swallows callback exceptions
    ->rescue(fn() => 42)                                          // recover from failure
    ->then(fn($v) => $v * 2)
    ->getValueOr(0);

echo $result; // 84
```

---

## Exception Policy (Important)

Wrap deliberately separates **how exceptions are handled** by method name.
You should be able to tell whether an exception is **thrown**, **captured**, or **swallowed** just by reading the chain.

### 1) **Thrown (escape the Wrap boundary)**

These methods **do not catch** exceptions from callbacks or explicitly rethrow errors:

* `then`, `map`, `filter`, `reduce`
* `ok`, `fail`
* `rethrowWhen`
* `rescueWhen` (when predicate does not match)
* `rescueExcept` (when error matches except list)
* `throwIfFailed`, `rethrow`, `rethrowRoot`

Use these when an error **must propagate** to the caller.

---

### 2) **Captured (invalidate the chain)**

These methods **catch exceptions and convert them into a failed Wrap state**
(using `InvalidArgumentException` with the original error as `previous`):

* `safeThen`, `safeMap`, `safeFilter`, `safeReduce`
* `tap`, `tapError`
* `when`, `unless`, `branch`
* `ensure`, `safeKeep`
* `whenValue`, `whenValueThen`
* **Added:** `safeOk`, `safeFail`

Use these when errors should stay **inside** the Wrap pipeline.

---

### 3) **Swallowed (ignored)**

These methods **ignore callback exceptions entirely** and keep the current state:

* `tryTap`, `tryTapError`
* **Added:** `tryOk`, `tryFail`

Use these for logging, metrics, tracing, or any side effects that must **never break the flow**.

---

### Recommended rule of thumb

* Logging / metrics / monitoring → `try*`
* Business logic that may fail → `safe*`
* Domain or application boundaries → `throwIfFailed` / `rethrow*`

---

## Construction

### `Wrap::handle(callable $callback)`

Runs a callback and captures its return value or any thrown `Throwable`.

```php
$wrap = Wrap::handle(fn() => 42);
$wrap = Wrap::handle(fn() => throw new RuntimeException('boom'));
```

### `Wrap::fromValue(mixed $value)`

Creates a successful Wrap.

```php
$wrap = Wrap::fromValue(['a' => 1]);
```

### `Wrap::fromError(Throwable $error)`

Creates a failed Wrap.

```php
$wrap = Wrap::fromError(new RuntimeException('x'));
```

---

## Side Effects

### `ok(callable $callback)` / `fail(callable $callback)`

Runs only on success / failure.

⚠ **If the callback throws, the exception is thrown immediately.**

```php
Wrap::handle(fn() => 10)
    ->ok(fn($v) => logger()->info("Value: $v"));
```

---

### `safeOk(callable $callback)` / `safeFail(callable $callback)` ✅

Runs only on success / failure.

If the callback throws, the chain is **invalidated**.

```php
Wrap::handle(fn() => 10)
    ->safeOk(fn($v) => riskyLog($v))
    ->then(fn($v) => $v + 1);
```

---

### `tryOk(callable $callback)` / `tryFail(callable $callback)` ✅

Runs only on success / failure.

If the callback throws, the exception is **swallowed**.

```php
Wrap::handle(fn() => 10)
    ->tryOk(fn($v) => riskyLog($v))
    ->then(fn($v) => $v + 1);
```

---

### `always(callable $callback): void`

Always runs (finally-style) and **ends the chain**.

Receives `(bool $ok, ?Throwable $error, mixed $value)`.

---

### `finally(callable $callback)`

Always runs and continues the chain.
If the callback throws, the chain is invalidated.

---

## Transformations

### `then(callable $callback)`

Transforms the stored value on success.

⚠ If the callback throws, the exception is **thrown**.

```php
$out = Wrap::handle(fn() => 10)
    ->then(fn(int $x) => $x + 5)
    ->then(fn(int $x) => (string) ($x * 2))
    ->getValueOr('fallback');
```

### `safeThen(callable $callback)`

Catches exceptions and invalidates instead of throwing.

---

## Iterable Operators

`map`, `filter`, and `reduce` require the stored value to be `iterable`.

* `map` / `filter` / `reduce` → **throw**
* `safeMap` / `safeFilter` / `safeReduce` → **invalidate**

---

## Recovery

### `rescue(callable $fallback)`

On failure, provides a fallback value and flips the state to success.

✅ The fallback may accept **zero arguments** or **one `Throwable` argument**.

```php
Wrap::handle(fn() => throw new RuntimeException('oops'))
    ->rescue(fn() => 123)
    ->getValueOr(-1);
```

```php
Wrap::handle(fn() => throw new RuntimeException('oops'))
    ->rescue(fn(Throwable $e) => 123);
```

---

### `recoverWhen(string|callable $matcher, callable $fallback)`

Recover **only when** the failure matches a class-string or predicate.

---

## Flat-mapping

### `andThen(callable $callbackReturningWrap)`

Like `then()`, but flattens another `Wrap` into the chain.

---

## Validation

### `ensure(callable $predicate, string|callable $message = 'Ensure failed')`

Invalidates the chain when the predicate returns `false`.

---

## Optional Value Flow

Helpers for pipelines where `null` means “no work to do”:

* `keep`, `safeKeep`
* `whenValue`, `whenValueThen`

---

## Error Utilities

### `throwIfFailed(?callable $factory = null)` ✅

Escapes the Wrap boundary.
Throws the captured error if failed.

---

### `rethrow()` ✅

Throws the captured error as-is.
If invalidated, throws the wrapper exception.

---

### `rethrowRoot()` ✅

Throws the **root cause** error (the `previous` exception when wrapped).

```php
Wrap::handle(fn() => 1)
    ->safeThen(fn() => throw new RuntimeException('root'))
    ->rethrowRoot();
```

---

### `rethrowWhen(string|callable $matcher)`

Throws the captured error only when it matches.

---

### `tapError` / `tryTapError`

* `tapError` → callback throws → invalidate
* `tryTapError` → callback throws → swallow

---

## Extraction & Accessors

* `isOk()`
* `getError()`
* `getValue()`
* `getValueOr()`
* `getValueOrCall()`
* `getValueOrNull()`
* `getOrThrow()`

---

## Optional Global Helper

```php
function wrap(callable $callback): Wrap
{
    return Wrap::handle($callback);
}
```

---

## License

MIT