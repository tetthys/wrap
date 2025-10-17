# Wrap

> Minimal Result-like wrapper with map/filter/reduce for iterable values (PHP 8.3+).

---

## Installation

```bash
composer require tetthys/wrap
````

---

## Basic Example

```php
use App\Utils\Wrap;

$result = Wrap::handle(fn() => riskyOperation())
    ->ok(fn($v) => Log::info("Success: {$v}"))
    ->fail(fn($e) => Log::error($e->getMessage()))
    ->rescue(fn() => 42)
    ->map(fn($v) => $v * 2)
    ->then(fn($v) => $v + 1)
    ->always(fn($ok, $err, $val) => Log::debug('Done'))
    ->getValueOr(0);

echo $result; // e.g. 85
```

---

## Method Usage

### `Wrap::handle(callable $callback)`

Safely execute a callable and wrap its result or exception.

```php
$wrap = Wrap::handle(fn() => 1 / 0);
```

---

### `ok(fn($value))`

Run only if successful (side effect only).

```php
->ok(fn($v) => Log::info("Value: {$v}"));
```

---

### `fail(fn($error))`

Run only on failure (for logging or alerts).

```php
->fail(fn($e) => Log::error($e->getMessage()));
```

---

### `rescue(fn($error))`

Provide a fallback value when an error occurs.

```php
->rescue(fn() => 'default value');
```

---

### `map(fn($value))`

Transform the stored value if successful.

```php
->map(fn($v) => $v * 2);
```

---

### `then(fn($value))`

Alias of `map()` for fluent, chain-style transformations.

```php
->then(fn($v) => $v + 1);
```

---

### `always(fn($ok, $error, $value))`

Always runs, like `finally`.

```php
->always(fn($ok, $err, $val) => Log::debug('Finished', compact('ok', 'err')));
```

---

### `isOk()`

Check whether the operation succeeded.

```php
if ($wrap->isOk()) echo "All good!";
```

---

### `getError()`

Retrieve the captured exception, or `null` if success.

```php
$error = $wrap->getError();
```

---

### `getValue()`

Get the current stored value (may be `null` if failed).

```php
$value = $wrap->getValue();
```

---

### `getValueOr($default)`

Return the stored value or a fallback default.

```php
$value = $wrap->getValueOr(0);
```

---

## Helper Function (optional)

If registered via Composer autoload (`src/helpers.php`):

```php
$value = wrap(fn() => riskyOperation())
    ->rescue(fn() => 42)
    ->getValueOr(0);
```

---

## License

MIT © Tetthys