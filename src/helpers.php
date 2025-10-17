<?php

declare(strict_types=1);

use Tetthys\Wrap\Wrap;
use Throwable;

if (!function_exists('wrap')) {
    /**
     * Wrap a callback execution with Tetthys\Wrap\Wrap.
     *
     * @template TResult
     * @param callable(): TResult $callback
     * @return Wrap<TResult, Throwable>
     */
    function wrap(callable $callback): Wrap
    {
        /** @var Wrap<TResult, Throwable> */
        return Wrap::handle($callback);
    }
}
