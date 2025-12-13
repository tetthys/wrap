# Wrap

A minimal, fluent **Result-like wrapper** for PHP 8.3+.  
Use it to run code safely, compose transformations, attach side-effects, and keep error-handling explicit without nested `try/catch`.

## Installation

```bash
composer require tetthys/wrap
````

## Quick start

```php
use Tetthys\Wrap\Wrap;

$value = Wrap::handle(fn() => 10)
    ->ok(fn($v) => logger()->info("ok:$v"))
    ->then(fn(int $v) => $v + 5)
    ->getValueOr(-1);

echo $value; // 15
```

---

## Construction

### `Wrap::handle(callable $callback)`

Executes `$callback` and captures either the returned value (success) or a thrown `Throwable` (failure).

```php
$ok = Wrap::handle(fn() => 42);

$fail = Wrap::handle(function () {
    throw new RuntimeException("boom");
});
```

### `Wrap::fromValue(mixed $value)`

Creates a successful wrap.

```php
$wrap = Wrap::fromValue(["a" => 1]);
```

### `Wrap::fromError(Throwable $error)`

Creates a failed wrap.

```php
$wrap = Wrap::fromError(new RuntimeException("x"));
```

---

## Observing state (side-effects)

### `ok(callable $callback)`

Runs only when the wrap is successful.

```php
Wrap::handle(fn() => 10)
    ->ok(fn($v) => logger()->info("value:$v"));
```

### `fail(callable $callback)`

Runs only when the wrap has failed.

```php
Wrap::handle(fn() => throw new RuntimeException("x"))
    ->fail(fn($e) => logger()->error($e->getMessage()));
```

---

## Transforming values

### `then(callable $callback)`

Transforms the stored value on success.

```php
$out = Wrap::handle(fn() => 10)
    ->then(fn(int $x) => $x + 5)
    ->then(fn(int $x) => (string) ($x * 2))
    ->getValueOr("fallback");

echo $out; // "30"
```

### `safeThen(callable $callback)`

Same as `then()`, but if the callback throws, the chain becomes failed (invalidated) instead of throwing.

```php
$wrap = Wrap::handle(fn() => 1)->safeThen(function () {
    throw new RuntimeException("explode");
});

$wrap->isOk();     // false
$wrap->getError(); // InvalidArgumentException (previous = RuntimeException)
```

---

## Working with iterables

> `map/filter/reduce` require the stored value to be iterable.
> If it is not iterable, the chain becomes failed (invalidated).

### `map(callable $mapper)`

Maps over an iterable and re-materializes into an array (keys preserved).

```php
$out = Wrap::handle(fn() => ["a" => 1, "b" => 2])
    ->map(fn($v, $k) => $k . $v)
    ->getValueOr([]);

var_export($out); // ["a" => "a1", "b" => "b2"]
```

### `safeMap(callable $mapper)`

Like `map()`, but if the mapper throws, the chain is invalidated (previous error preserved).

### `filter(callable $predicate)`

Filters an iterable (keys preserved).

```php
$out = Wrap::handle(fn() => ["x" => 1, "y" => 2, "z" => 3, "w" => 4])
    ->filter(fn($v) => $v % 2 === 0)
    ->getValueOr([]);

var_export($out); // ["y" => 2, "w" => 4]
```

### `safeFilter(callable $predicate)`

Like `filter()`, but if the predicate throws, the chain is invalidated.

### `reduce(callable $reducer, mixed $initial)`

Reduces an iterable into a single value.

```php
$sum = Wrap::handle(fn() => [1, 2, 3, 4, 5])
    ->reduce(fn(int $acc, int $v) => $acc + $v, 0)
    ->getValueOr(-1);

echo $sum; // 15
```

### `safeReduce(callable $reducer, mixed $initial)`

Like `reduce()`, but if the reducer throws, the chain is invalidated.

---

## Recovery

### `rescue(callable $callback)`

On failure, provides a fallback value, clears the error, flips the state to success, and allows chaining to continue.

```php
$out = Wrap::handle(fn() => throw new RuntimeException("err"))
    ->rescue(fn() => [1, 2, 3, 4])
    ->filter(fn($v) => $v % 2 === 0) // [2, 4]
    ->map(fn($v) => $v * 10)         // [20, 40]
    ->reduce(fn($acc, $v) => $acc + $v, 0)
    ->getValueOr(-1);

echo $out; // 60
```

---

## Finalizers

### `always(callable $callback) : void`

Always runs (success or failure). Does **not** continue chaining.

Receives: `(bool $ok, ?Throwable $err, mixed $val)`

```php
Wrap::handle(fn() => 10)->always(function (bool $ok, ?Throwable $err, mixed $val) {
    // $ok=true, $err=null, $val=10
});
```

### `finally(callable $callback) : self`

Always runs and **continues chaining**.
If the callback throws, the chain becomes failed (invalidated).

```php
$wrap = Wrap::handle(fn() => 5)
    ->finally(fn(bool $ok, ?Throwable $err, mixed $val) => null)
    ->then(fn(int $v) => $v + 1);

$wrap->getValue(); // 6
```

---

## Conditional helpers (side-effect oriented)

### `when(callable $predicate, callable $callback)`

Runs `$callback($value)` only when `$predicate($value)` is true.

```php
$log = [];

Wrap::handle(fn() => 10)
    ->when(fn(int $v) => $v > 5, function ($v) use (&$log) {
        $log[] = "when:$v";
    });

var_export($log); // ["when:10"]
```

### `unless(callable $predicate, callable $callback)`

Runs `$callback($value)` only when `$predicate($value)` is false.

```php
$log = [];

Wrap::handle(fn() => 3)
    ->unless(fn(int $v) => $v > 5, function ($v) use (&$log) {
        $log[] = "unless:$v";
    });

var_export($log); // ["unless:3"]
```

### `whenTrue(callable $callback)` / `whenFalse(callable $callback)`

Shortcuts based on `(bool)$value`.

```php
$log = [];

Wrap::handle(fn() => 123)
    ->whenTrue(fn() => $log[] = "T")
    ->whenFalse(fn() => $log[] = "F");

Wrap::handle(fn() => 0)
    ->whenTrue(fn() => $log[] = "T")
    ->whenFalse(fn() => $log[] = "F");

var_export($log); // ["T", "F"]
```

### `branch(callable $onTrue, callable $onFalse)`

If/else style side-effect branching based on `(bool)$value`.

```php
$log = [];

Wrap::handle(fn() => 1)->branch(
    fn() => $log[] = "T",
    fn() => $log[] = "F",
);

Wrap::handle(fn() => 0)->branch(
    fn() => $log[] = "T",
    fn() => $log[] = "F",
);

var_export($log); // ["T", "F"]
```

> If a predicate or callback throws inside `when/unless/branch`, the chain is invalidated and the thrown exception is preserved as `previous` (per tests).

---

## New helpers (based on tests)

### `andThen(callable $callback)`

Flat-maps into another `Wrap`. The callback must return a `Wrap`.

```php
$out = Wrap::handle(fn() => 10)
    ->andThen(fn(int $v) => Wrap::handle(fn() => $v + 5)) // returns Wrap
    ->then(fn(int $v) => $v * 2)
    ->getValueOr(-1);

echo $out; // 30
```

* If already failed, the callback is not executed.
* If the callback does not return a `Wrap`, the chain is invalidated.
* If the returned `Wrap` failed, its error becomes the current error.

### `ensure(callable $predicate, string|callable|null $message = null)`

Asserts a condition on the success value; invalidates the chain if the predicate is false.

```php
$wrap = Wrap::handle(fn() => 10)
    ->ensure(fn(int $v) => $v > 5, "too-small");

$wrap->isOk(); // true
```

Message can be a callable to build a message from the value:

```php
Wrap::handle(fn() => 3)
    ->ensure(fn(int $v) => $v > 5, fn(int $v) => "bad:$v");
```

### `mapError(callable $mapper)`

Transforms the captured error (failure state remains failure).

```php
$wrap = Wrap::handle(fn() => throw new RuntimeException("orig"))
    ->mapError(fn(Throwable $e) => new InvalidArgumentException("mapped", 0, $e));

$wrap->getError(); // InvalidArgumentException(previous=RuntimeException)
```

### `recoverWhen(string|callable $match, callable $fallback)`

Recovers from failure only when the error matches.

* Match by exception class-string:

```php
$wrap = Wrap::handle(fn() => throw new RuntimeException("x"))
    ->recoverWhen(RuntimeException::class, fn() => 99);

$wrap->getValue(); // 99
```

* Or match by predicate:

```php
$wrap = Wrap::handle(fn() => throw new RuntimeException("x"))
    ->recoverWhen(fn(Throwable $e) => $e->getMessage() === "x", fn() => "ok");
```

### `tap(callable $callback)` / `tryTap(callable $callback)`

Run a side-effect on success without changing the value.

```php
$wrap = Wrap::handle(fn() => 5)
    ->tap(fn(int $v) => logger()->info("seen:$v"));
```

* `tap()` invalidates the chain if the callback throws.
* `tryTap()` swallows callback exceptions and keeps the chain OK.

### `failWhen(string|callable $match, callable $callback)`

Runs only when failed and the error matches.

```php
Wrap::handle(fn() => throw new RuntimeException("x"))
    ->failWhen(RuntimeException::class, fn(RuntimeException $e) => logger()->error($e->getMessage()));
```

Match can also be a predicate:

```php
Wrap::handle(fn() => throw new RuntimeException("x"))
    ->failWhen(fn(Throwable $e) => $e->getMessage() === "x", fn() => /* ... */ null);
```

### `rethrowWhen(string|callable $match)`

Re-throws the captured error when it matches.

```php
Wrap::handle(fn() => throw new RuntimeException("x"))
    ->rethrowWhen(RuntimeException::class); // throws RuntimeException("x")
```

---

## Accessors / extraction

```php
$wrap = Wrap::handle(fn() => 0);

$wrap->isOk();            // bool
$wrap->getError();        // ?Throwable
$wrap->getValue();        // mixed

$wrap->getValueOr(999);   // mixed (default if failed OR value is null)
$wrap->getValueOrCall(fn() => "L"); // lazy default
$wrap->getValueOrNull();  // mixed|null (null if failed)
$wrap->getOrThrow();      // mixed (throws captured error on failure)
```

Error mapping with `getOrThrow()`:

```php
Wrap::handle(fn() => throw new RuntimeException("boom"))
    ->getOrThrow(fn($err) => new InvalidArgumentException("wrapped", 0, $err));
```

---

## Optional global helper

Define a `wrap()` helper as a shortcut for `Wrap::handle()`:

```php
<?php

if (!function_exists('wrap')) {
    /**
     * Shortcut for Wrap::handle().
     *
     * @template TResult
     * @param callable(): TResult $callback
     * @return \Tetthys\Wrap\Wrap<TResult, \Throwable>
     */
    function wrap(callable $callback): \Tetthys\Wrap\Wrap
    {
        return \Tetthys\Wrap\Wrap::handle($callback);
    }
}
```

Enable it via Composer:

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

---

## License

MIT © Tetthys