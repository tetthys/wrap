<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/*
|--------------------------------------------------------------------------
| Pest Bootstrap
|--------------------------------------------------------------------------
|
| This file is automatically loaded before any test files.
| It defines global setup, helpers, and ensures all tests in the
| 'tests/Unit' directory automatically extend PHPUnit TestCase.
|
| You don't need namespaces or manual TestCase inheritance inside
| individual Pest test files.
|
*/

uses(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Global Helper Functions (Optional)
|--------------------------------------------------------------------------
|
| You can define global functions here for convenience in tests.
| Example:
|
| function wrap_ok(mixed $value): \Tetthys\Wrap\Wrap {
|     return \Tetthys\Wrap\Wrap::handle(fn() => $value);
| }
|
*/
