# Wrap

> Minimal Result-like wrapper with fluent map/filter/reduce and conditional helpers for PHP 8.3+.

---

## Installation

```bash
composer require tetthys/wrap
```

---

## Basic Example

```php
use Tetthys\Wrap\Wrap;

$result = Wrap::handle(fn() => riskyOperation())
    ->ok(fn($v) => \Log::info("Success: {$v}"))
    ->fail(fn($e) => \Log::error($e->getMessage()))
    ->rescue(fn() => 42)
    ->map(fn($v) => $v * 2)
    ->then(fn($v) => $v + 1)
    ->always(fn($ok, $err, $val) => \Log::debug('Done', compact('ok', 'val')))
    ->getValueOr(0);

echo $result; // e.g. 85
```

---

## Core Methods

### `Wrap::handle(callable $callback)`

Safely executes a callable and captures either its return value or thrown exception.

```php
$wrap = Wrap::handle(fn() => 1 / 0);
```

---

### `ok(fn($value))`

Runs only when successful — typically for side effects such as logging.

```php
->ok(fn($v) => \Log::info("Value: {$v}"));
```

---

### `fail(fn($error))`

Runs only when failed — typically for alerts or exception logs.

```php
->fail(fn($e) => \Log::error($e->getMessage()));
```

---

### `rescue(fn($error))`

Provides a fallback value on failure, **flipping the state to success** so that chaining continues.

```php
->rescue(fn() => 'default value');
```

---

### `map(fn($value))`

Transforms each element if the stored value is iterable.

```php
->map(fn($v) => $v * 2);
```

---

### `then(fn($value))`

Transforms any (scalar or complex) value on success.
Alias of `map()` conceptually, but not limited to iterables.

```php
->then(fn($v) => $v + 1);
```

---

### `filter(fn($value, $key = null))`

Filters iterable values by predicate.

```php
->filter(fn($v) => $v > 10);
```

---

### `reduce(fn($acc, $value, $key = null), $initial)`

Reduces iterable values into a single accumulator.

```php
->reduce(fn($acc, $v) => $acc + $v, 0);
```

---

### `always(fn($ok, $error, $value))`

Always runs (similar to `finally`).

```php
->always(fn($ok, $err, $val) => \Log::debug('Finished', compact('ok', 'err')));
```

---

## Conditional Helpers (New)

### `when(fn($value): bool, fn($value))`

Run a callback **only when the predicate returns true**.

```php
Wrap::handle(fn() => 10)
    ->when(fn($v) => $v > 5, fn($v) => echo "✅ Greater than 5");
```

---

### `unless(fn($value): bool, fn($value))`

Run a callback **only when the predicate returns false**.

```php
Wrap::handle(fn() => 3)
    ->unless(fn($v) => $v > 5, fn($v) => echo "❌ Less or equal to 5");
```

---

### `whenTrue(fn($value))`

Boolean-specialized helper: run only when `(bool)$value === true`.

```php
Wrap::handle(fn() => true)
    ->whenTrue(fn() => echo "It's true!");
```

---

### `whenFalse(fn($value))`

Boolean-specialized helper: run only when `(bool)$value === false`.

```php
Wrap::handle(fn() => false)
    ->whenFalse(fn() => echo "It's false!");
```

---

## Accessors

| Method                 | Description                               | Example                         |
| ---------------------- | ----------------------------------------- | ------------------------------- |
| `isOk()`               | Returns whether operation succeeded.      | `if ($wrap->isOk()) echo "OK";` |
| `getError()`           | Returns the captured exception or `null`. | `$err = $wrap->getError();`     |
| `getValue()`           | Returns stored value (may be `null`).     | `$v = $wrap->getValue();`       |
| `getValueOr($default)` | Returns stored value or fallback default. | `$v = $wrap->getValueOr(0);`    |

---

## Example: Conditional Flow

```php
Wrap::handle(fn() => 10)
    ->then(fn(int $v) => $v > 5)
    ->whenTrue(fn() => echo "✅ Enough balance")
    ->whenFalse(fn() => echo "❌ Not enough");
```

Output:

```
✅ Enough balance
```

---

## Optional Helper Function

You can define a global `wrap()` function in your project for convenience.

**helpers.php**

```php
<?php

declare(strict_types=1);

if (!function_exists('wrap')) {
    /**
     * Shortcut for Wrap::handle()
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

**composer.json**

```json
{
  "autoload": {
    "files": ["helpers.php"]
  }
}
```

Then run:

```bash
composer dump-autoload
```

**Usage:**

```php
$result = wrap(fn() => riskyOperation())
    ->rescue(fn() => 42)
    ->getValueOr(0);
```

---

## License

MIT © Tetthys