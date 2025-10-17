<?php
// tests/Unit/WrapTest.php

declare(strict_types=1);

use Tetthys\Wrap\Wrap;
use InvalidArgumentException;
use RuntimeException;

/**
 * Pest setup
 */
uses()->group('unit');

/**
 * handle(): success path
 */
describe('Wrap::handle success', function () {
    it('sets ok=true and stores value', function () {
        $wrap = Wrap::handle(fn() => 42);

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getError())->toBeNull()
            ->and($wrap->getValue())->toBe(42);
    });

    it('supports null return; getValueOr returns default when value is null', function () {
        $wrap = Wrap::handle(fn() => null);

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getValue())->toBeNull()
            ->and($wrap->getValueOr('fallback'))->toBe('fallback');
    });
});

/**
 * handle(): failure path
 */
describe('Wrap::handle failure', function () {
    it('captures thrown exception and sets ok=false', function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException('boom');
        });

        expect($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(RuntimeException::class)
            ->and($wrap->getValue())->toBeNull();
    });
});

/**
 * ok() / fail() side effects
 */
describe('ok() and fail() callbacks', function () {
    it('ok() runs only on success', function () {
        $called = false;

        Wrap::handle(fn() => 10)
            ->ok(function ($v) use (&$called) {
                // should be invoked with value 10
                $called = $v === 10;
            })
            ->fail(function () {
                // should not be called
                throw new RuntimeException('fail() should not run on success');
            });

        expect($called)->toBeTrue();
    });

    it('fail() runs only on failure', function () {
        $called = false;

        Wrap::handle(function () {
            throw new RuntimeException('x');
        })
            ->fail(function ($e) use (&$called) {
                $called = $e instanceof RuntimeException;
            })
            ->ok(function () {
                // should not be called
                throw new RuntimeException('ok() should not run on failure');
            });

        expect($called)->toBeTrue();
    });

    it('calling fail()->ok()->fail() re-evaluates state each time', function () {
        $okCount = 0;
        $failCount = 0;

        // Case 1: success -> ok called once; fail never
        Wrap::handle(fn() => 1)
            ->fail(function () use (&$failCount) {
                $failCount++;
            })
            ->ok(function () use (&$okCount) {
                $okCount++;
            })
            ->fail(function () use (&$failCount) {
                $failCount++;
            });

        expect($okCount)->toBe(1)
            ->and($failCount)->toBe(0);

        // Reset counters
        $okCount = 0;
        $failCount = 0;

        // Case 2: failure -> fail called twice; ok never
        Wrap::handle(function () {
            throw new RuntimeException('nope');
        })
            ->fail(function () use (&$failCount) {
                $failCount++;
            })
            ->ok(function () use (&$okCount) {
                $okCount++;
            })
            ->fail(function () use (&$failCount) {
                $failCount++;
            });

        expect($okCount)->toBe(0)
            ->and($failCount)->toBe(2);
    });
});

/**
 * rescue(): flips to ok and clears error
 */
describe('rescue()', function () {
    it('provides fallback value, flips to ok, and clears previous error', function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException('oops');
        })
            ->rescue(fn() => 123);

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getError())->toBeNull()
            ->and($wrap->getValue())->toBe(123);
    });

    it('allows subsequent chaining (then/map/filter/reduce) after rescue', function () {
        $sum = Wrap::handle(function () {
            throw new RuntimeException('err');
        })
            ->rescue(fn() => [1, 2, 3, 4])
            ->filter(fn($v) => $v % 2 === 0)       // [2, 4]
            ->map(fn($v) => $v * 10)               // [20, 40]
            ->reduce(fn($acc, $v) => $acc + $v, 0) // 60
            ->getValueOr(-1);

        expect($sum)->toBe(60);
    });
});

/**
 * then(): scalar (or any) transformation
 */
describe('then()', function () {
    it('transforms a non-iterable value on success', function () {
        $v = Wrap::handle(fn() => 10)
            ->then(fn(int $x) => $x + 5)
            ->then(fn(int $x) => (string) ($x * 2)) // "30"
            ->getValueOr('nope');

        expect($v)->toBe('30');
    });

    it('does not run on failure', function () {
        $v = Wrap::handle(function () {
            throw new RuntimeException('bad');
        })
            ->then(fn($x) => $x + 1)
            ->getValueOr('fallback');

        expect($v)->toBe('fallback');
    });
});

/**
 * map(): iterable-only transformation
 */
describe('map()', function () {
    it('maps over arrays', function () {
        $out = Wrap::handle(fn() => [1, 2, 3])
            ->map(fn($v) => $v * $v)
            ->getValueOr([]);

        expect($out)->toBe([1, 4, 9]);
    });

    it('preserves keys while mapping', function () {
        $out = Wrap::handle(fn() => ['a' => 1, 'b' => 2])
            ->map(fn($v, $k) => $k . $v)
            ->getValueOr([]);

        expect($out)->toBe(['a' => 'a1', 'b' => 'b2']);
    });

    it('accepts Traversable (e.g., ArrayIterator)', function () {
        $iter = new ArrayIterator([1, 2, 3]);
        $out = Wrap::handle(fn() => $iter)
            ->map(fn($v) => $v + 1)
            ->getValueOr([]);

        // ArrayIterator will be re-materialized into array by map()
        expect($out)->toBe([2, 3, 4]);
    });

    it('fails with InvalidArgumentException when value is not iterable', function () {
        $wrap = Wrap::handle(fn() => 100)
            ->map(fn($v) => $v); // should invalidate

        expect($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getValueOr('x'))->toBe('x');
    });
});

/**
 * filter(): iterable-only predicate
 */
describe('filter()', function () {
    it('filters arrays by predicate and preserves keys', function () {
        $out = Wrap::handle(fn() => ['x' => 1, 'y' => 2, 'z' => 3, 'w' => 4])
            ->filter(fn($v) => $v % 2 === 0)
            ->getValueOr([]);

        expect($out)->toBe(['y' => 2, 'w' => 4]);
    });

    it('fails with InvalidArgumentException when value is not iterable', function () {
        $wrap = Wrap::handle(fn() => 'not-iterable')
            ->filter(fn() => true);

        expect($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(InvalidArgumentException::class);
    });
});

/**
 * reduce(): iterable -> accumulator
 */
describe('reduce()', function () {
    it('reduces numeric arrays to a sum', function () {
        $sum = Wrap::handle(fn() => [1, 2, 3, 4, 5])
            ->reduce(fn(int $acc, int $v) => $acc + $v, 0)
            ->getValueOr(-1);

        expect($sum)->toBe(15);
    });

    it('reduces to a different type (e.g., string concat)', function () {
        $concat = Wrap::handle(fn() => [1, 2, 3])
            ->reduce(fn(string $acc, int $v) => $acc . (string) $v, '')
            ->getValueOr('nope');

        expect($concat)->toBe('123');
    });

    it('fails with InvalidArgumentException when value is not iterable', function () {
        $wrap = Wrap::handle(fn() => 7)
            ->reduce(fn($acc, $v) => $acc, 0);

        expect($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(InvalidArgumentException::class);
    });
});

/**
 * always(): finally-like callback
 */
describe('always()', function () {
    it('runs after success with expected parameters', function () {
        $seen = null;

        $wrap = Wrap::handle(fn() => 10);
        $wrap->always(function (bool $ok, ?Throwable $err, mixed $val) use (&$seen) {
            // Expect ok=true, err=null, val=10
            $seen = [$ok, $err, $val];
        });

        expect($seen)->toBe([true, null, 10]);
    });

    it('runs after failure with expected parameters', function () {
        $seen = null;

        $wrap = Wrap::handle(function () {
            throw new RuntimeException('x');
        });
        $wrap->always(function (bool $ok, ?Throwable $err, mixed $val) use (&$seen) {
            // Expect ok=false, err is RuntimeException, val=null
            $seen = [$ok, $err instanceof RuntimeException, $val];
        });

        expect($seen)->toBe([false, true, null]);
    });
});

/**
 * Accessors and getValueOr()
 */
describe('accessors & getValueOr', function () {
    it('getValueOr returns default when failed', function () {
        $val = Wrap::handle(function () {
            throw new RuntimeException('xx');
        })->getValueOr('fallback');

        expect($val)->toBe('fallback');
    });

    it('getValue returns the raw value without default logic', function () {
        $wrap = Wrap::handle(fn() => 0);
        expect($wrap->getValue())->toBe(0)
            ->and($wrap->getValueOr(999))->toBe(0);
    });
});
