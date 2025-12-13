# Wrap

A minimal, fluent **Result-like wrapper** for PHP 8.3+.

* Safely execute code and keep either a value (**ok**) or a captured exception (**fail**)
* Transform values (`then`, `map`, `filter`, `reduce`)
* Use expressive flow helpers (`when`, `unless`, `branch`)
* Add modern chain helpers (`andThen`, `ensure`, `recoverWhen`, `mapError`, `rethrowWhen`, `tap`, `tryTap`, `failWhen`)
* Support optional pipelines (`keep`, `whenValue`, `whenValueThen`) and error side-effects (`tapError`, `tryTapError`)

---

## Installation

```bash
composer require tetthys/wrap
```

---

## Quick Start

```php
use Tetthys\Wrap\Wrap;

$result = Wrap::handle(fn() => riskyOperation())
    ->ok(fn($v) => logger()->info('ok', ['value' => $v]))
    ->fail(fn($e) => logger()->warning('fail', ['msg' => $e->getMessage()]))
    ->rescue(fn() => 42)
    ->then(fn($v) => $v * 2)
    ->getValueOr(0);

echo $result; // 84
```

---

## Construction

### `Wrap::handle(callable $callback)`

Runs a callback and captures its return value or any thrown `Throwable`.

```php
$wrap = Wrap::handle(fn() => 42);
$wrap = Wrap::handle(fn() => throw new RuntimeException('boom'));
```

### `Wrap::fromValue(mixed $value)`

Creates a successful wrap.

```php
$wrap = Wrap::fromValue(['a' => 1]);
```

### `Wrap::fromError(Throwable $error)`

Creates a failed wrap.

```php
$wrap = Wrap::fromError(new RuntimeException('x'));
```

---

## Side Effects

### `ok(callable $callback)`

Runs only on success.

```php
Wrap::handle(fn() => 10)
    ->ok(fn($v) => logger()->info("Value: $v"));
```

### `fail(callable $callback)`

Runs only on failure.

```php
Wrap::handle(fn() => throw new RuntimeException('x'))
    ->fail(fn($e) => logger()->error($e->getMessage()));
```

### `always(callable $callback): void`

Always runs (finally-style) and **ends the chain**.

Receives `(bool $ok, ?Throwable $error, mixed $value)`.

```php
Wrap::handle(fn() => 10)->always(function (bool $ok, ?Throwable $err, mixed $val) {
    logger()->debug('done', compact('ok', 'val'));
});
```

### `finally(callable $callback)`

Always runs (finally-style) but **continues the chain**.
If the callback throws, the chain is invalidated.

```php
Wrap::handle(fn() => 5)
    ->finally(fn() => cleanup())
    ->then(fn($v) => $v + 1);
```

---

## Transformations

### `then(callable $callback)`

Transforms the stored value on success (works for any type).

```php
$out = Wrap::handle(fn() => 10)
    ->then(fn(int $x) => $x + 5)
    ->then(fn(int $x) => (string)($x * 2))
    ->getValueOr('fallback'); // "30"
```

### `safeThen(callable $callback)`

Like `then()`, but catches exceptions and invalidates instead of throwing.

```php
$wrap = Wrap::handle(fn() => 1)
    ->safeThen(fn() => throw new RuntimeException('explode'));

$wrap->isOk(); // false
```

---

## Iterable Operators

These require the stored value to be `iterable`. Keys are preserved, and results are materialized into arrays.

### `map(callable $mapper)`

```php
$out = Wrap::handle(fn() => ['a' => 1, 'b' => 2])
    ->map(fn($v, $k) => $k.$v)
    ->getValueOr([]); // ['a' => 'a1', 'b' => 'b2']
```

### `safeMap(callable $mapper)`

Catches mapper exceptions and invalidates.

```php
$wrap = Wrap::handle(fn() => [1, 2, 3])
    ->safeMap(fn() => throw new RuntimeException('mapper-error'));
```

### `filter(callable $predicate)`

```php
$out = Wrap::handle(fn() => ['x' => 1, 'y' => 2, 'z' => 3])
    ->filter(fn($v) => $v % 2 === 0)
    ->getValueOr([]); // ['y' => 2]
```

### `safeFilter(callable $predicate)`

Catches predicate exceptions and invalidates.

### `reduce(callable $reducer, mixed $initial)`

```php
$sum = Wrap::handle(fn() => [1,2,3,4,5])
    ->reduce(fn(int $acc, int $v) => $acc + $v, 0)
    ->getValueOr(-1); // 15
```

### `safeReduce(callable $reducer, mixed $initial)`

Catches reducer exceptions and invalidates.

---

## Recovery

### `rescue(callable $callback)`

On failure, provides a fallback value and flips state to success (clears previous error).

```php
$out = Wrap::handle(fn() => throw new RuntimeException('oops'))
    ->rescue(fn() => 123)
    ->then(fn($v) => $v + 1)
    ->getValueOr(-1); // 124
```

### `recoverWhen(string|callable $matcher, callable $fallback)`

Recover **only when** the failure matches:

* a class-string (e.g. `RuntimeException::class`), or
* a predicate `fn(Throwable $e): bool => ...`

```php
$wrap = Wrap::handle(fn() => throw new RuntimeException('x'))
    ->recoverWhen(RuntimeException::class, fn() => 99);

$wrap->getValueOrNull(); // 99
```

```php
$wrap = Wrap::handle(fn() => throw new RuntimeException('x'))
    ->recoverWhen(fn(Throwable $e) => $e->getMessage() === 'x', fn() => 'ok');
```

---

## Flat-mapping

### `andThen(callable $callbackReturningWrap)`

Like `then()`, but expects the callback to return another `Wrap`, and **flattens** it into the current chain.

```php
$out = Wrap::handle(fn() => 10)
    ->andThen(fn(int $v) => Wrap::handle(fn() => $v + 5)) // Wrap(15)
    ->then(fn(int $v) => $v * 2)
    ->getValueOr(-1); // 30
```

If the returned wrap fails, the failure is propagated.

---

## Validation

### `ensure(callable $predicate, string|callable|null $message = null)`

Keeps success when predicate returns `true`.
Invalidates when predicate returns `false`.

```php
Wrap::handle(fn() => 10)
    ->ensure(fn(int $v) => $v > 5)
    ->then(fn($v) => $v * 2);
```

```php
$wrap = Wrap::handle(fn() => 3)
    ->ensure(fn(int $v) => $v > 5, 'too-small');

$wrap->isOk(); // false
```

---

## Conditional Helpers (success-only)

### `when(callable $predicate, callable $callback)`

Runs callback when predicate is true.

```php
Wrap::handle(fn() => 10)
    ->when(fn(int $v) => $v > 5, fn($v) => logger()->info("when:$v"));
```

### `unless(callable $predicate, callable $callback)`

Runs callback when predicate is false.

```php
Wrap::handle(fn() => 3)
    ->unless(fn(int $v) => $v > 5, fn($v) => logger()->info("unless:$v"));
```

### `whenTrue(callable $callback)` / `whenFalse(callable $callback)`

Boolean-specialized helpers (based on `(bool)$value`).

```php
Wrap::handle(fn() => 1)->whenTrue(fn() => logger()->info('T'));
Wrap::handle(fn() => 0)->whenFalse(fn() => logger()->info('F'));
```

### `branch(callable $onTrue, callable $onFalse)`

if/else-style side-effect branching.

```php
Wrap::handle(fn() => 10 > 5)
    ->branch(
        fn() => logger()->info('T'),
        fn() => logger()->info('F'),
    );
```

---

## Value Taps (success-only)

### `tap(callable $callback)`

Runs on success, **does not change value**. If callback throws, invalidates.

```php
Wrap::handle(fn() => 5)
    ->tap(fn(int $v) => logger()->info('seen', ['v' => $v]))
    ->then(fn(int $v) => $v + 1);
```

### `tryTap(callable $callback)`

Runs on success, but **swallows callback exceptions** (keeps chain ok).

```php
Wrap::handle(fn() => 1)
    ->tryTap(fn() => throw new RuntimeException('ignored'))
    ->then(fn(int $v) => $v + 1)
    ->getValueOr(-1); // 2
```

---

## Optional Value Flow (success-only)

These helpers are useful when `null` means “no work to do” without marking the chain as failed.

### `keep(callable $predicate)`

Keeps the value when predicate is true, otherwise turns the value into `null` (still ok).

```php
$wrap = Wrap::handle(fn() => 3)
    ->keep(fn(int $v) => $v > 5);

$wrap->isOk();     // true
$wrap->getValue(); // null
```

### `safeKeep(callable $predicate)`

Safe variant. If predicate throws, invalidates (previous preserved).

```php
Wrap::handle(fn() => 10)
    ->safeKeep(fn($v) => riskyCheck($v));
```

### `whenValue(callable $callback)`

Runs only when the current value is **not null**. Does not modify value.

```php
Wrap::handle(fn() => 5)
    ->whenValue(fn(int $v) => logger()->info('has-value', ['v' => $v]));
```

### `whenValueThen(callable $callback)`

Transforms only when value is **not null**. If value is `null`, callback is not run.

```php
$email = Wrap::handle(fn() => $userOrNull)
    ->whenValueThen(fn($u) => $u->email)
    ->getValueOrNull();
```

---

## Error Utilities

### `mapError(callable $mapper)`

Transforms the captured error and keeps failure state.

```php
$wrap = Wrap::handle(fn() => throw new RuntimeException('orig'))
    ->mapError(fn(Throwable $e) => new InvalidArgumentException('mapped', 0, $e));

$wrap->isOk(); // false
```

### `failWhen(string|callable $matcher, callable $callback)`

Runs an error side-effect only when failure matches a class-string or predicate.
If callback throws, invalidates (previous preserved).

```php
Wrap::handle(fn() => throw new RuntimeException('x'))
    ->failWhen(RuntimeException::class, fn(RuntimeException $e) => logger()->warning($e->getMessage()));
```

```php
Wrap::handle(fn() => throw new RuntimeException('x'))
    ->failWhen(fn(Throwable $e) => $e->getMessage() === 'x', fn() => logger()->warning('matched'));
```

### `rethrowWhen(string|callable $matcher)`

Rethrows the captured error when it matches.

```php
$wrap = Wrap::handle(fn() => throw new RuntimeException('x'));

$wrap->rethrowWhen(RuntimeException::class); // throws RuntimeException('x')
```

### `tapError(callable $callback)`

Runs only on failure and does not change the error.
If callback throws, invalidates (previous preserved).

```php
Wrap::handle(fn() => throw new RuntimeException('x'))
    ->tapError(fn(Throwable $e) => report($e));
```

### `tryTapError(callable $callback)`

Runs only on failure, but swallows callback exceptions and keeps the original error.

```php
Wrap::handle(fn() => throw new RuntimeException('orig'))
    ->tryTapError(fn() => throw new RuntimeException('ignored'));
```

---

## Extraction & Accessors

### `isOk()` / `getError()` / `getValue()`

```php
$wrap->isOk();
$wrap->getError(); // Throwable|null
$wrap->getValue(); // mixed
```

### `getValueOr(mixed $default)`

Returns stored value when ok and not null, otherwise default.

```php
$value = Wrap::handle(fn() => null)->getValueOr('fallback'); // 'fallback'
```

### `getValueOrCall(callable $defaultFn)`

Lazy default: only computed when needed.

```php
$value = Wrap::handle(fn() => throw new RuntimeException('e'))
    ->getValueOrCall(fn() => 'fallback');
```

### `getValueOrNull()`

Returns value on success (even if null), otherwise null.

```php
Wrap::handle(fn() => 7)->getValueOrNull(); // 7
Wrap::handle(fn() => throw new RuntimeException('e'))->getValueOrNull(); // null
```

### `getOrThrow(?callable $factory = null)`

Returns value on success; throws the captured error on failure.
Optionally map the error to another exception.

```php
$value = Wrap::handle(fn() => 55)->getOrThrow();
```

```php
Wrap::handle(fn() => throw new RuntimeException('boom'))
    ->getOrThrow(fn($err) => new InvalidArgumentException('wrapped', 0, $err));
```

---

## Optional Global Helper

If you prefer `wrap(fn() => ...)` syntax:

```php
<?php
// helpers.php

use Tetthys\Wrap\Wrap;

if (!function_exists('wrap')) {
    function wrap(callable $callback): Wrap
    {
        return Wrap::handle($callback);
    }
}
```

```json
{
  "autoload": {
    "files": ["helpers.php"]
  }
}
```

```bash
composer dump-autoload
```

Usage:

```php
$result = wrap(fn() => riskyOperation())
    ->rescue(fn() => 42)
    ->getValueOr(0);
```

---

## License

MIT
