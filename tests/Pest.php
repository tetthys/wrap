<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/*
|--------------------------------------------------------------------------
| Pest Bootstrap
|--------------------------------------------------------------------------
|
| This file is automatically loaded before any test files.
| You can define global helpers, shared setup, datasets, etc.
|
| All tests under `tests/Unit` will automatically use PHPUnit's TestCase.
|
*/

uses(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Optional Global Helpers (if needed)
|--------------------------------------------------------------------------
|
| You can define simple global helper functions for assertions or mocks.
| For example:
|
| function fakeWrapValue($value): \Tetthys\Wrap\Wrap {
|     return \Tetthys\Wrap\Wrap::handle(fn() => $value);
| }
|
*/
