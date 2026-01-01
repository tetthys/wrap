<?php
// tests/Unit/WrapUsageTest.php

declare(strict_types=1);

use Tetthys\Wrap\Wrap;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

uses()->group('unit');

/**
 * Usage-oriented tests:
 * - Focus on how Wrap is intended to be used
 * - Avoid tight coupling to internal implementation details
 * - Readable as documentation-by-example
 */

describe('Wrap usage', function () {
    it('basic: successful execution stores value and marks success', function () {
        $result = Wrap::handle(fn() => 42);

        expect($result->isOk())->toBeTrue();
        expect($result->getValue())->toBe(42);
        expect($result->getError())->toBeNull();
    });

    it('basic: exception during execution results in failure state', function () {
        $result = Wrap::handle(function () {
            throw new RuntimeException('boom');
        });

        expect($result->isOk())->toBeFalse();
        expect($result->getValue())->toBeNull();
        expect($result->getError())->toBeInstanceOf(RuntimeException::class);
        expect($result->getError()?->getMessage())->toBe('boom');
    });

    it('basic: null success value falls back via getValueOr()', function () {
        $result = Wrap::handle(fn() => null);

        expect($result->isOk())->toBeTrue();
        expect($result->getValue())->toBeNull();
        expect($result->getValueOr('fallback'))->toBe('fallback');
    });

    it('basic: failed result falls back via getValueOr()', function () {
        $value = Wrap::handle(function () {
            throw new RuntimeException('x');
        })->getValueOr('fallback');

        expect($value)->toBe('fallback');
    });

    it('lazy fallback: default is computed only when needed', function () {
        $calls = 0;

        $lazy = function () use (&$calls) {
            $calls++;
            return 'L';
        };

        // success + non-null -> no evaluation
        $v1 = Wrap::handle(fn() => 'X')->getValueOrCall($lazy);

        // success + null -> evaluated
        $v2 = Wrap::handle(fn() => null)->getValueOrCall($lazy);

        // failure -> evaluated
        $v3 = Wrap::handle(function () {
            throw new RuntimeException('e');
        })->getValueOrCall($lazy);

        expect($v1)->toBe('X');
        expect($v2)->toBe('L');
        expect($v3)->toBe('L');
        expect($calls)->toBe(2);
    });

    it('then(): transforms value step-by-step on success', function () {
        $value = Wrap::handle(fn() => 10)
            ->then(fn(int $v) => $v + 5)          // 15
            ->then(fn(int $v) => (string)($v * 2)) // "30"
            ->getValueOr('nope');

        expect($value)->toBe('30');
    });

    it('then(): does not execute when already failed', function () {
        $value = Wrap::handle(function () {
            throw new RuntimeException('bad');
        })
            ->then(fn() => 'should-not-run')
            ->getValueOr('fallback');

        expect($value)->toBe('fallback');
    });

    it('andThen(): flattens nested Wrap results and continues chaining', function () {
        $value = Wrap::handle(fn() => 10)
            ->andThen(fn(int $v) => Wrap::handle(fn() => $v + 5)) // Wrap(15)
            ->then(fn(int $v) => $v * 2)                          // 30
            ->getValueOr(-1);

        expect($value)->toBe(30);
    });

    it('andThen(): fails when callback does not return a Wrap', function () {
        $result = Wrap::handle(fn() => 1)->andThen(fn() => 123);

        expect($result->isOk())->toBeFalse();
        expect($result->getError())->toBeInstanceOf(InvalidArgumentException::class);
    });

    it('andThen(): propagates failure from returned Wrap', function () {
        $result = Wrap::handle(fn() => 1)->andThen(function () {
            return Wrap::handle(function () {
                throw new RuntimeException('nested-fail');
            });
        });

        expect($result->isOk())->toBeFalse();
        expect($result->getError())->toBeInstanceOf(RuntimeException::class);
        expect($result->getError()?->getMessage())->toBe('nested-fail');
    });

    it('rescue(): recovers from failure using zero-argument fallback', function () {
        $result = Wrap::handle(function () {
            throw new RuntimeException('oops');
        })->rescue(fn() => 123);

        expect($result->isOk())->toBeTrue();
        expect($result->getError())->toBeNull();
        expect($result->getValue())->toBe(123);
    });

    it('rescue(): fallback can inspect the thrown error', function () {
        $result = Wrap::handle(function () {
            throw new RuntimeException('x');
        })->rescue(function (Throwable $e) {
            return $e->getMessage() === 'x' ? 555 : 0;
        });

        expect($result->isOk())->toBeTrue();
        expect($result->getValue())->toBe(555);
        expect($result->getError())->toBeNull();
    });

    it('mapError(): transforms the captured error while keeping failure state', function () {
        $result = Wrap::handle(function () {
            throw new RuntimeException('orig');
        })->mapError(fn(Throwable $e) => new InvalidArgumentException('mapped', 0, $e));

        expect($result->isOk())->toBeFalse();
        expect($result->getError())->toBeInstanceOf(InvalidArgumentException::class);
        expect($result->getError()?->getPrevious())->toBeInstanceOf(RuntimeException::class);
        expect($result->getError()?->getMessage())->toBe('mapped');
    });

    it('collections: map/filter/reduce create a fluent data pipeline', function () {
        $sum = Wrap::fromValue(['x' => 1, 'y' => 2, 'z' => 3, 'w' => 4])
            ->map(fn($v) => $v * 10)                  // [10, 20, 30, 40]
            ->filter(fn($v) => $v % 20 === 0)          // [20, 40]
            ->reduce(fn(int $acc, int $v) => $acc + $v, 0)
            ->getValueOr(-1);

        expect($sum)->toBe(60);
    });

    it('collections: operations fail when value is not iterable', function () {
        $a = Wrap::fromValue(123)->map(fn($v) => $v);
        $b = Wrap::fromValue('nope')->filter(fn() => true);
        $c = Wrap::fromValue(7)->reduce(fn($acc, $v) => $acc, 0);

        expect($a->isOk())->toBeFalse();
        expect($b->isOk())->toBeFalse();
        expect($c->isOk())->toBeFalse();

        expect($a->getError())->toBeInstanceOf(InvalidArgumentException::class);
        expect($b->getError())->toBeInstanceOf(InvalidArgumentException::class);
        expect($c->getError())->toBeInstanceOf(InvalidArgumentException::class);
    });

    it('getOrThrow(): returns value on success and throws on failure', function () {
        expect(Wrap::fromValue(55)->getOrThrow())->toBe(55);

        $failed = Wrap::handle(function () {
            throw new RuntimeException('boom');
        });

        expect(fn() => $failed->getOrThrow())
            ->toThrow(RuntimeException::class, 'boom');
    });

    it('getOrThrow(): failure can be mapped to a different exception', function () {
        $failed = Wrap::handle(function () {
            throw new RuntimeException('boom');
        });

        expect(function () use ($failed) {
            $failed->getOrThrow(
                fn(?Throwable $e) => new InvalidArgumentException('wrapped', 0, $e),
            );
        })->toThrow(InvalidArgumentException::class, 'wrapped');
    });
});
