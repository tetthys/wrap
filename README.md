# Tetthys / Wrap

> Minimal Result-like wrapper with map/filter/reduce for iterable values (PHP 8.3+).

---

## Installation

```bash
composer require tetthys/wrap
````

---

## Basic Usage

`Wrap` safely executes a callback and lets you handle success, failure, and transformation fluently — without `try/catch`.

```php
use Tetthys\Wrap\Wrap;

$result = Wrap::handle(fn() => 1 / 0)
    ->fail(fn($e) => \Log::error($e->getMessage()))
    ->rescue(fn() => 42)
    ->getValueOr(0);

echo $result; // 42
```

---

## Functional Example

You can safely map, filter, and reduce iterable values.

```php
use Tetthys\Wrap\Wrap;

$sum = Wrap::handle(fn() => [1, 2, 3, 4, 5])
    ->filter(fn($v) => $v % 2 === 0)
    ->map(fn($v) => $v ** 2)
    ->reduce(fn($acc, $v) => $acc + $v, 0)
    ->getValueOr(0);

echo $sum; // 20
```

---

## Helper Function

This package also provides a global helper `wrap()` for quick use.

```php
$value = wrap(fn() => 1 / 0)
    ->rescue(fn() => 42)
    ->getValueOr(0); // 42
```

> You may need to run `composer dump-autoload` after installation.

---

## API Overview

| Method                               | Description                          |
| ------------------------------------ | ------------------------------------ |
| `Wrap::handle(callable)`             | Execute safely, store value or error |
| `ok(fn($value))`                     | Run only when success                |
| `fail(fn($error))`                   | Run only when failure                |
| `rescue(fn($error))`                 | Replace failed value with fallback   |
| `always(fn($ok, $error, $value))`    | Always run (like finally)            |
| `map(fn($v, $k))`                    | Transform iterable items             |
| `filter(fn($v, $k))`                 | Filter iterable items                |
| `reduce(fn($acc, $v, $k), $initial)` | Reduce iterable                      |
| `getValueOr($default)`               | Get value or default                 |

> **Note**
> `map`, `filter`, and `reduce` only work with iterable values.
> If the wrapped value is not iterable, the instance automatically transitions into a failed state with an `InvalidArgumentException`.

---

## Example Integration

```php
return Wrap::handle(fn() => $this->withdraw($amount))
    ->fail(fn($e) => Log::error('Withdrawal failed', ['msg' => $e->getMessage()]))
    ->rescue(fn() => $this->rollback());
```

---

## License

MIT © Tetthys