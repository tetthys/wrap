# Wrap

> A minimal, fluent, **Result-like wrapper** for PHP 8.3+ — featuring safe execution, functional operators (`map`, `filter`, `reduce`), and expressive conditional helpers (`when`, `unless`, `branch`, etc.).

---

## 🚀 Installation

```bash
composer require tetthys/wrap
```

---

## 💡 Quick Example

```php
use Tetthys\Wrap\Wrap;

$result = Wrap::handle(fn() => riskyOperation())
    ->ok(fn($v) => \Log::info("Success: {$v}"))
    ->fail(fn($e) => \Log::error($e->getMessage()))
    ->rescue(fn() => 42)
    ->then(fn($v) => $v * 2)
    ->always(fn($ok, $err, $val) => \Log::debug('Done', compact('ok', 'val')))
    ->getValueOr(0);

echo $result; // e.g. 84
```

---

## 🧩 Core API

### `Wrap::handle(callable $callback)`

Safely executes a callable and captures either its return value or any thrown `Throwable`.

```php
$wrap = Wrap::handle(fn() => 1 / 0);
```

---

### `ok(callable $callback)`

Runs only when successful — typically used for logging or side effects.

```php
->ok(fn($v) => \Log::info("Value: {$v}"));
```

---

### `fail(callable $callback)`

Runs only when failed — ideal for alerts or error logs.

```php
->fail(fn($e) => \Log::error($e->getMessage()));
```

---

### `rescue(callable $callback)`

Provides a fallback value **on failure**, flipping the state to *success* so the chain continues.

```php
->rescue(fn() => 'default value');
```

---

### `then(callable $callback)`

Transforms the stored value when successful (not limited to iterables).

```php
->then(fn($v) => $v + 1);
```

---

### `safeThen(callable $callback)`

Same as `then()`, but catches exceptions and marks the chain as failed instead of throwing.

```php
->safeThen(fn($v) => riskyTransform($v));
```

---

### `map(callable $mapper)`

Applies a function to each element when the value is iterable.

```php
->map(fn($v) => $v * 2);
```

---

### `safeMap(callable $mapper)`

Like `map()`, but automatically invalidates on exceptions inside the mapper.

---

### `filter(callable $predicate)`

Filters iterable values by predicate; preserves keys.

```php
->filter(fn($v) => $v > 10);
```

---

### `safeFilter(callable $predicate)`

Variant of `filter()` that invalidates if the predicate throws.

---

### `reduce(callable $reducer, mixed $initial)`

Reduces an iterable into a single accumulated value.

```php
->reduce(fn($acc, $v) => $acc + $v, 0);
```

---

### `safeReduce(callable $reducer, mixed $initial)`

Catches reducer exceptions and marks the chain as failed.

---

### `always(callable $callback)`

Always runs, like a `finally` block.
Receives `(bool $ok, ?Throwable $error, mixed $value)`.

```php
->always(fn($ok, $err, $val) => \Log::debug('Finished', compact('ok', 'val')));
```

---

### `finally(callable $callback)`

Similar to `always()`, but keeps the fluent chain alive; if the callback throws, the chain becomes failed.

```php
->finally(fn() => cleanup());
```

---

## ⚙️ Accessors & Extraction

| Method                                  | Description                                                    | Example                                   |
| :-------------------------------------- | :------------------------------------------------------------- | :---------------------------------------- |
| `isOk()`                                | Returns whether the chain succeeded.                           | `if ($wrap->isOk()) echo "OK";`           |
| `getError()`                            | Returns the captured exception or `null`.                      | `$err = $wrap->getError();`               |
| `getValue()`                            | Returns the stored value (may be `null`).                      | `$v = $wrap->getValue();`                 |
| `getValueOr($default)`                  | Returns value or default.                                      | `$v = $wrap->getValueOr(0);`              |
| `getValueOrCall(fn $factory)`           | Lazy default — calls the function only when needed.            | `$v = $wrap->getValueOrCall(fn() => 99);` |
| `getValueOrNull()`                      | Returns value on success, otherwise `null`.                    | `$v = $wrap->getValueOrNull();`           |
| `getOrThrow(?callable $factory = null)` | Returns value or throws (optionally map error via `$factory`). | `$v = $wrap->getOrThrow();`               |

---

## 🔀 Conditional Helpers

### `when(fn($value): bool, fn($value))`

Runs a callback **only when the predicate returns true**.

```php
Wrap::handle(fn() => 10)
    ->when(fn($v) => $v > 5, fn($v) => echo "✅ Greater than 5");
```

---

### `unless(fn($value): bool, fn($value))`

Runs a callback **only when the predicate returns false**.

```php
Wrap::handle(fn() => 3)
    ->unless(fn($v) => $v > 5, fn($v) => echo "❌ Less or equal to 5");
```

---

### `whenTrue(fn($value))`

Boolean-specialized shortcut for truthy values.

```php
Wrap::handle(fn() => true)
    ->whenTrue(fn() => echo "It's true!");
```

---

### `whenFalse(fn($value))`

Boolean-specialized shortcut for falsy values.

```php
Wrap::handle(fn() => false)
    ->whenFalse(fn() => echo "It's false!");
```

---

### `branch(fn($onTrue), fn($onFalse))`

Fluent `if / else`-style branching.

```php
Wrap::handle(fn() => 10 > 5)
    ->branch(
        fn() => echo "✅ Enough balance",
        fn() => echo "❌ Not enough"
    );
```

---

## 💬 Example: Conditional Flow

```php
Wrap::handle(fn() => 10)
    ->then(fn(int $v) => $v > 5)
    ->branch(
        fn() => echo "✅ Enough balance",
        fn() => echo "❌ Not enough"
    );
```

Output:

```
✅ Enough balance
```

---

## 🪄 Optional Global Helper

You can define a global helper `wrap()` for concise syntax.

**helpers.php**

```php
<?php

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

Usage:

```php
$result = wrap(fn() => riskyOperation())
    ->rescue(fn() => 42)
    ->getValueOr(0);
```

---

## 🧠 Why Use Wrap?

* ✨ Clean, functional, and chainable syntax
* 🧱 Zero dependencies (pure PHP)
* ⚡ Safe and exception-aware
* 🧩 Fluent conditional flow (`when`, `unless`, `branch`)
* 🧰 Optional global helper (`wrap()`)

---

## 📜 License

**MIT © Tetthys**