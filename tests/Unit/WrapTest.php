<?php
// tests/Unit/WrapTest.php

declare(strict_types=1);

use Tetthys\Wrap\Wrap;
use InvalidArgumentException;
use RuntimeException;

/**
 * Concrete exception used only for Wrap tests.
 */
final class ConcreteTestException extends RuntimeException {}
final class AnotherTestException extends RuntimeException {}

/**
 * Pest setup
 */
uses()->group("unit");

/**
 * --------------------------------------------------------------------------
 * Construction: handle(), fromValue(), fromError()
 * --------------------------------------------------------------------------
 */
describe("construction", function () {
    it("Wrap::handle success stores value and sets ok=true", function () {
        $wrap = Wrap::handle(fn() => 42);

        expect($wrap->isOk())
            ->toBeTrue()
            ->and($wrap->getError())
            ->toBeNull()
            ->and($wrap->getValue())
            ->toBe(42);
    });

    it(
        "Wrap::handle supports null; getValueOr returns default when null",
        function () {
            $wrap = Wrap::handle(fn() => null);

            expect($wrap->isOk())
                ->toBeTrue()
                ->and($wrap->getValue())
                ->toBeNull()
                ->and($wrap->getValueOr("fallback"))
                ->toBe("fallback");
        },
    );

    it("Wrap::handle failure captures exception and sets ok=false", function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException("boom");
        });

        expect($wrap->isOk())
            ->toBeFalse()
            ->and($wrap->getError())
            ->toBeInstanceOf(RuntimeException::class)
            ->and($wrap->getValue())
            ->toBeNull();
    });

    it("Wrap::fromValue creates success state with provided value", function () {
        $wrap = Wrap::fromValue(["a" => 1]);

        expect($wrap->isOk())
            ->toBeTrue()
            ->and($wrap->getError())
            ->toBeNull()
            ->and($wrap->getValue())
            ->toBe(["a" => 1]);
    });

    it("Wrap::fromError creates failed state with provided error", function () {
        $err = new RuntimeException("x");
        $wrap = Wrap::fromError($err);

        expect($wrap->isOk())
            ->toBeFalse()
            ->and($wrap->getError())
            ->toBe($err)
            ->and($wrap->getValue())
            ->toBeNull();
    });
});

/**
 * --------------------------------------------------------------------------
 * ok() / fail() side effects
 * --------------------------------------------------------------------------
 */
describe("ok() and fail()", function () {
    it("ok() runs only on success", function () {
        $called = false;

        Wrap::handle(fn() => 10)
            ->ok(function ($v) use (&$called) {
                $called = $v === 10;
            })
            ->fail(function () {
                throw new RuntimeException("should not run on success");
            });

        expect($called)->toBeTrue();
    });

    it("fail() runs only on failure", function () {
        $called = false;

        Wrap::handle(function () {
            throw new RuntimeException("x");
        })
            ->fail(function ($e) use (&$called) {
                $called = $e instanceof RuntimeException;
            })
            ->ok(function () {
                throw new RuntimeException("should not run on failure");
            });

        expect($called)->toBeTrue();
    });

    it("calling fail()->ok()->fail() re-evaluates state each time", function () {
        $okCount = 0;
        $failCount = 0;

        // Case 1: success -> ok once; fail never
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

        expect($okCount)->toBe(1)->and($failCount)->toBe(0);

        // Case 2: failure -> fail twice; ok never
        $okCount = 0;
        $failCount = 0;

        Wrap::handle(function () {
            throw new RuntimeException("nope");
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

        expect($okCount)->toBe(0)->and($failCount)->toBe(2);
    });
});

/**
 * --------------------------------------------------------------------------
 * rescue()
 * --------------------------------------------------------------------------
 */
describe("rescue()", function () {
    it(
        "flips failure to success with fallback value and clears error",
        function () {
            $wrap = Wrap::handle(function () {
                throw new RuntimeException("oops");
            })->rescue(fn() => 123);

            expect($wrap->isOk())
                ->toBeTrue()
                ->and($wrap->getError())
                ->toBeNull()
                ->and($wrap->getValue())
                ->toBe(123);
        },
    );

    it(
        "allows subsequent chaining (then/map/filter/reduce) after rescue",
        function () {
            $sum = Wrap::handle(function () {
                throw new RuntimeException("err");
            })
                ->rescue(fn() => [1, 2, 3, 4])
                ->filter(fn($v) => $v % 2 === 0) // [2, 4]
                ->map(fn($v) => $v * 10) // [20, 40]
                ->reduce(fn($acc, $v) => $acc + $v, 0) // 60
                ->getValueOr(-1);

            expect($sum)->toBe(60);
        },
    );
});

/**
 * --------------------------------------------------------------------------
 * always() and finally()
 * --------------------------------------------------------------------------
 */
describe("always() & finally()", function () {
    it("always() runs after success with expected parameters", function () {
        $seen = null;
        $wrap = Wrap::handle(fn() => 10);

        $wrap->always(function (bool $ok, ?Throwable $err, mixed $val) use (
            &$seen,
        ) {
            $seen = [$ok, $err, $val];
        });

        expect($seen)->toBe([true, null, 10]);
    });

    it("always() runs after failure with expected parameters", function () {
        $seen = null;
        $wrap = Wrap::handle(function () {
            throw new RuntimeException("x");
        });

        $wrap->always(function (bool $ok, ?Throwable $err, mixed $val) use (
            &$seen,
        ) {
            $seen = [$ok, $err instanceof RuntimeException, $val];
        });

        expect($seen)->toBe([false, true, null]);
    });

    it(
        "finally() runs and keeps chaining; invalidates if callback throws",
        function () {
            // normal path
            $wrap = Wrap::handle(fn() => 5)
                ->finally(function (bool $ok, ?Throwable $err, mixed $val) {
                    /* no-op */
                })
                ->then(fn(int $v) => $v + 1);

            expect($wrap->isOk())->toBeTrue()->and($wrap->getValue())->toBe(6);

            // throwing path -> invalidated
            $wrap2 = Wrap::handle(fn() => 1)->finally(function () {
                throw new RuntimeException("inside-finally");
            });

            expect($wrap2->isOk())
                ->toBeFalse()
                ->and($wrap2->getError())
                ->toBeInstanceOf(InvalidArgumentException::class);
        },
    );
});

/**
 * --------------------------------------------------------------------------
 * Accessors and extraction helpers
 * --------------------------------------------------------------------------
 */
describe("accessors & extraction", function () {
    it("getValueOr returns default when failed", function () {
        $val = Wrap::handle(function () {
            throw new RuntimeException("xx");
        })->getValueOr("fallback");

        expect($val)->toBe("fallback");
    });

    it("getValue returns the raw value without default logic", function () {
        $wrap = Wrap::handle(fn() => 0);

        expect($wrap->getValue())->toBe(0)->and($wrap->getValueOr(999))->toBe(0);
    });

    it("getValueOrCall computes lazy default only when needed", function () {
        $calls = 0;
        $lazy = function () use (&$calls) {
            $calls++;
            return "L";
        };

        // success + non-null -> no call
        $v1 = Wrap::handle(fn() => "X")->getValueOrCall($lazy);
        // success + null -> call
        $v2 = Wrap::handle(fn() => null)->getValueOrCall($lazy);
        // failure -> call
        $v3 = Wrap::handle(function () {
            throw new RuntimeException("e");
        })->getValueOrCall($lazy);

        expect($v1)
            ->toBe("X")
            ->and($v2)
            ->toBe("L")
            ->and($v3)
            ->toBe("L")
            ->and($calls)
            ->toBe(2);
    });

    it(
        "getValueOrNull returns value on success (even if null) else null",
        function () {
            $v1 = Wrap::handle(fn() => null)->getValueOrNull();
            $v2 = Wrap::handle(fn() => 7)->getValueOrNull();
            $v3 = Wrap::handle(function () {
                throw new RuntimeException("e");
            })->getValueOrNull();

            expect($v1)->toBeNull()->and($v2)->toBe(7)->and($v3)->toBeNull();
        },
    );

    it("getOrThrow returns value on success", function () {
        $val = Wrap::handle(fn() => 55)->getOrThrow();
        expect($val)->toBe(55);
    });

    it("getOrThrow throws captured error by default on failure", function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException("boom");
        });

        try {
            $wrap->getOrThrow();
            throw new RuntimeException("should not reach");
        } catch (Throwable $e) {
            expect($e)
                ->toBeInstanceOf(RuntimeException::class)
                ->and($e->getMessage())
                ->toBe("boom");
        }
    });

    it("getOrThrow can map the error via factory", function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException("boom");
        });

        try {
            $wrap->getOrThrow(
                fn($err) => new InvalidArgumentException("wrapped", 0, $err),
            );
            throw new RuntimeException("should not reach");
        } catch (Throwable $e) {
            expect($e)
                ->toBeInstanceOf(InvalidArgumentException::class)
                ->and($e->getPrevious())
                ->toBeInstanceOf(RuntimeException::class)
                ->and($e->getMessage())
                ->toBe("wrapped");
        }
    });
});

/**
 * --------------------------------------------------------------------------
 * then() / safeThen()
 * --------------------------------------------------------------------------
 */
describe("then() and safeThen()", function () {
    it("then() transforms a non-iterable value on success", function () {
        $v = Wrap::handle(fn() => 10)
            ->then(fn(int $x) => $x + 5)
            ->then(fn(int $x) => (string) ($x * 2)) // "30"
            ->getValueOr("nope");

        expect($v)->toBe("30");
    });

    it("then() does not run on failure", function () {
        $v = Wrap::handle(function () {
            throw new RuntimeException("bad");
        })
            ->then(fn($x) => $x + 1)
            ->getValueOr("fallback");

        expect($v)->toBe("fallback");
    });

    it(
        "safeThen() catches exceptions and invalidates instead of throwing",
        function () {
            $wrap = Wrap::handle(fn() => 1)->safeThen(function () {
                throw new RuntimeException("explode");
            });

            expect($wrap->isOk())
                ->toBeFalse()
                ->and($wrap->getError())
                ->toBeInstanceOf(InvalidArgumentException::class);
        },
    );
});

/**
 * --------------------------------------------------------------------------
 * map()/filter()/reduce() and safe variants
 * --------------------------------------------------------------------------
 */
describe("map/filter/reduce and safe variants", function () {
    it("map() maps over arrays and preserves keys", function () {
        $out = Wrap::handle(fn() => ["a" => 1, "b" => 2])
            ->map(fn($v, $k) => $k . $v)
            ->getValueOr([]);

        expect($out)->toBe(["a" => "a1", "b" => "b2"]);
    });

    it("map() accepts Traversable and re-materializes into array", function () {
        $iter = new ArrayIterator([1, 2, 3]);
        $out = Wrap::handle(fn() => $iter)->map(fn($v) => $v + 1)->getValueOr([]);

        expect($out)->toBe([2, 3, 4]);
    });

    it("map() invalidates when value is not iterable", function () {
        $wrap = Wrap::handle(fn() => 100)->map(fn($v) => $v);

        expect($wrap->isOk())
            ->toBeFalse()
            ->and($wrap->getError())
            ->toBeInstanceOf(InvalidArgumentException::class);
    });

    it(
        "safeMap() invalidates on mapper exception and preserves previous in error",
        function () {
            $wrap = Wrap::handle(fn() => [1, 2, 3])->safeMap(function () {
                throw new RuntimeException("mapper-error");
            });

            expect($wrap->isOk())
                ->toBeFalse()
                ->and($wrap->getError())
                ->toBeInstanceOf(InvalidArgumentException::class)
                ->and($wrap->getError()?->getPrevious())
                ->toBeInstanceOf(RuntimeException::class);
        },
    );

    it("filter() filters arrays by predicate and preserves keys", function () {
        $out = Wrap::handle(fn() => ["x" => 1, "y" => 2, "z" => 3, "w" => 4])
            ->filter(fn($v) => $v % 2 === 0)
            ->getValueOr([]);

        expect($out)->toBe(["y" => 2, "w" => 4]);
    });

    it("filter() invalidates when value is not iterable", function () {
        $wrap = Wrap::handle(fn() => "not-iterable")->filter(fn() => true);

        expect($wrap->isOk())
            ->toBeFalse()
            ->and($wrap->getError())
            ->toBeInstanceOf(InvalidArgumentException::class);
    });

    it("safeFilter() invalidates on predicate exception", function () {
        $wrap = Wrap::handle(fn() => [1, 2, 3])->safeFilter(function () {
            throw new RuntimeException("pred-error");
        });

        expect($wrap->isOk())
            ->toBeFalse()
            ->and($wrap->getError())
            ->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())
            ->toBeInstanceOf(RuntimeException::class);
    });

    it("reduce() reduces numeric arrays to a sum", function () {
        $sum = Wrap::handle(fn() => [1, 2, 3, 4, 5])
            ->reduce(fn(int $acc, int $v) => $acc + $v, 0)
            ->getValueOr(-1);

        expect($sum)->toBe(15);
    });

    it("reduce() reduces to different type (e.g. string concat)", function () {
        $concat = Wrap::handle(fn() => [1, 2, 3])
            ->reduce(fn(string $acc, int $v) => $acc . (string) $v, "")
            ->getValueOr("nope");

        expect($concat)->toBe("123");
    });

    it("reduce() invalidates when value is not iterable", function () {
        $wrap = Wrap::handle(fn() => 7)->reduce(fn($acc, $v) => $acc, 0);

        expect($wrap->isOk())
            ->toBeFalse()
            ->and($wrap->getError())
            ->toBeInstanceOf(InvalidArgumentException::class);
    });

    it("safeReduce() invalidates on reducer exception", function () {
        $wrap = Wrap::handle(fn() => [1, 2, 3])->safeReduce(function () {
            throw new RuntimeException("reducer-error");
        }, 0);

        expect($wrap->isOk())
            ->toBeFalse()
            ->and($wrap->getError())
            ->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())
            ->toBeInstanceOf(RuntimeException::class);
    });
});

/**
 * --------------------------------------------------------------------------
 * Conditional helpers: when/unless, whenTrue/whenFalse, branch
 * --------------------------------------------------------------------------
 */
describe("conditional helpers", function () {
    it("when() runs only when predicate is true (success state)", function () {
        $log = [];

        Wrap::handle(fn() => 10)
            ->when(fn(int $v) => $v > 5, function ($v) use (&$log) {
                $log[] = "when:$v"; // should run
            })
            ->unless(fn(int $v) => $v > 5, function ($v) use (&$log) {
                $log[] = "unless:$v"; // should NOT run
            });

        expect($log)->toBe(["when:10"]);
    });

    it("unless() runs only when predicate is false (success state)", function () {
        $log = [];

        Wrap::handle(fn() => 3)
            ->when(fn(int $v) => $v > 5, function ($v) use (&$log) {
                $log[] = "when:$v"; // should NOT run
            })
            ->unless(fn(int $v) => $v > 5, function ($v) use (&$log) {
                $log[] = "unless:$v"; // should run
            });

        expect($log)->toBe(["unless:3"]);
    });

    it("when()/unless() do nothing on failure", function () {
        $called = false;

        Wrap::handle(function () {
            throw new RuntimeException("boom");
        })
            ->when(fn() => true, function () use (&$called) {
                $called = true;
            })
            ->unless(fn() => false, function () use (&$called) {
                $called = true;
            });

        expect($called)->toBeFalse();
    });

    it(
        "when() invalidates the chain if callback throws (previous preserved)",
        function () {
            $wrap = Wrap::handle(fn() => 10)->when(fn() => true, function () {
                throw new RuntimeException("inside-when");
            });

            expect($wrap->isOk())
                ->toBeFalse()
                ->and($wrap->getError())
                ->toBeInstanceOf(InvalidArgumentException::class)
                ->and($wrap->getError()?->getPrevious())
                ->toBeInstanceOf(RuntimeException::class);
        },
    );

    it(
        "unless() invalidates the chain if callback throws (previous preserved)",
        function () {
            $wrap = Wrap::handle(fn() => 0)->unless(
                fn(int $v) => $v > 0,
                function () {
                    throw new RuntimeException("inside-unless");
                },
            );

            expect($wrap->isOk())
                ->toBeFalse()
                ->and($wrap->getError())
                ->toBeInstanceOf(InvalidArgumentException::class)
                ->and($wrap->getError()?->getPrevious())
                ->toBeInstanceOf(RuntimeException::class);
        },
    );

    it("whenTrue() runs only when value is strictly truthy by cast", function () {
        $log = [];

        Wrap::handle(fn() => 123)
            ->whenTrue(function () use (&$log) {
                $log[] = "T";
            })
            ->whenFalse(function () use (&$log) {
                $log[] = "F";
            });

        Wrap::handle(fn() => 0)
            ->whenTrue(function () use (&$log) {
                $log[] = "T";
            })
            ->whenFalse(function () use (&$log) {
                $log[] = "F";
            });

        expect($log)->toBe(["T", "F"]);
    });

    it(
        "whenFalse()/whenTrue() do not alter value and allow further then()",
        function () {
            $out = Wrap::handle(fn() => true)
                ->whenTrue(fn() => null) // side-effect only
                ->whenFalse(fn() => null) // not executed
                ->then(fn(bool $b) => $b ? "yes" : "no")
                ->getValueOr("err");

            expect($out)->toBe("yes");
        },
    );

    it("branch() runs onTrue when value casts to true", function () {
        $log = [];

        Wrap::handle(fn() => 1) // truthy
            ->branch(
                function () use (&$log) {
                    $log[] = "T";
                },
                function () use (&$log) {
                    $log[] = "F";
                },
            );

        expect($log)->toBe(["T"]);
    });

    it("branch() runs onFalse when value casts to false", function () {
        $log = [];

        Wrap::handle(fn() => 0) // falsy
            ->branch(
                function () use (&$log) {
                    $log[] = "T";
                },
                function () use (&$log) {
                    $log[] = "F";
                },
            );

        expect($log)->toBe(["F"]);
    });

    it("branch() does nothing on failure state", function () {
        $log = [];

        Wrap::handle(function () {
            throw new RuntimeException("x");
        })->branch(
            function () use (&$log) {
                $log[] = "T";
            },
            function () use (&$log) {
                $log[] = "F";
            },
        );

        expect($log)->toBe([]);
    });

    it(
        "branch() invalidates when a branch callback throws (previous preserved)",
        function () {
            $wrap = Wrap::handle(fn() => true)->branch(
                function () {
                    throw new RuntimeException("boom");
                },
                function () {},
            );

            expect($wrap->isOk())
                ->toBeFalse()
                ->and($wrap->getError())
                ->toBeInstanceOf(InvalidArgumentException::class)
                ->and($wrap->getError()?->getPrevious())
                ->toBeInstanceOf(RuntimeException::class);
        },
    );

    it("branch() keeps value untouched and allows further chaining", function () {
        $out = Wrap::handle(fn() => true)
            ->branch(fn() => null, fn() => null) // side effects only
            ->then(fn(bool $b) => $b ? "yes" : "no")
            ->getValueOr("err");

        expect($out)->toBe("yes");
    });
});

/**
 * --------------------------------------------------------------------------
 * New helpers: andThen()
 * --------------------------------------------------------------------------
 */
describe("andThen()", function () {
    it("andThen() flattens nested Wrap and continues chaining", function () {
        $out = Wrap::handle(fn() => 10)
            ->andThen(fn(int $v) => Wrap::handle(fn() => $v + 5)) // 15
            ->then(fn(int $v) => $v * 2) // 30
            ->getValueOr(-1);

        expect($out)->toBe(30);
    });

    it("andThen() does not run when already failed", function () {
        $called = false;

        $wrap = Wrap::handle(function () {
            throw new RuntimeException("x");
        })->andThen(function () use (&$called) {
            $called = true;
            return Wrap::handle(fn() => 1);
        });

        expect($called)->toBeFalse()->and($wrap->isOk())->toBeFalse();
    });

    it("andThen() fails when callback does not return Wrap", function () {
        $wrap = Wrap::handle(fn() => 1)->andThen(fn() => 123);

        expect($wrap->isOk())
            ->toBeFalse()
            ->and($wrap->getError())
            ->toBeInstanceOf(InvalidArgumentException::class);
    });

    it("andThen() propagates failure from returned Wrap", function () {
        $wrap = Wrap::handle(fn() => 1)->andThen(function () {
            return Wrap::handle(function () {
                throw new RuntimeException("nested-fail");
            });
        });

        expect($wrap->isOk())
            ->toBeFalse()
            ->and($wrap->getError())
            ->toBeInstanceOf(RuntimeException::class)
            ->and($wrap->getError()?->getMessage())
            ->toBe("nested-fail");
    });

    it("andThen() invalidates when callback throws (previous preserved)", function () {
        $wrap = Wrap::handle(fn() => 1)->andThen(function () {
            throw new RuntimeException("explode");
        });

        expect($wrap->isOk())
            ->toBeFalse()
            ->and($wrap->getError())
            ->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())
            ->toBeInstanceOf(RuntimeException::class);
    });
});

/**
 * --------------------------------------------------------------------------
 * New helpers: ensure()
 * --------------------------------------------------------------------------
 */
describe("ensure()", function () {
    it("ensure() keeps success when predicate is true", function () {
        $wrap = Wrap::handle(fn() => 10)->ensure(fn(int $v) => $v > 5);

        expect($wrap->isOk())->toBeTrue()->and($wrap->getValue())->toBe(10);
    });

    it("ensure() invalidates when predicate is false", function () {
        $wrap = Wrap::handle(fn() => 3)->ensure(fn(int $v) => $v > 5, "too-small");

        expect($wrap->isOk())
            ->toBeFalse()
            ->and($wrap->getError())
            ->toBeInstanceOf(InvalidArgumentException::class);
    });

    it("ensure() supports message as callable", function () {
        $wrap = Wrap::handle(fn() => 3)->ensure(
            fn(int $v) => $v > 5,
            fn(int $v) => "bad:$v",
        );

        expect($wrap->isOk())
            ->toBeFalse()
            ->and($wrap->getError())
            ->toBeInstanceOf(InvalidArgumentException::class);
    });

    it("ensure() invalidates when predicate throws (previous preserved)", function () {
        $wrap = Wrap::handle(fn() => 1)->ensure(function () {
            throw new RuntimeException("pred-boom");
        });

        expect($wrap->isOk())
            ->toBeFalse()
            ->and($wrap->getError())
            ->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())
            ->toBeInstanceOf(RuntimeException::class);
    });

    it("ensure() does nothing when already failed", function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException("x");
        })->ensure(fn() => true);

        expect($wrap->isOk())->toBeFalse()->and($wrap->getError())->toBeInstanceOf(RuntimeException::class);
    });
});

/**
 * --------------------------------------------------------------------------
 * New helpers: mapError()
 * --------------------------------------------------------------------------
 */
describe("mapError()", function () {
    it("mapError() transforms captured error and keeps failure state", function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException("orig");
        })->mapError(fn(Throwable $e) => new InvalidArgumentException("mapped", 0, $e));

        expect($wrap->isOk())
            ->toBeFalse()
            ->and($wrap->getError())
            ->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())
            ->toBeInstanceOf(RuntimeException::class)
            ->and($wrap->getError()?->getMessage())
            ->toBe("mapped");
    });

    it("mapError() does nothing on success", function () {
        $wrap = Wrap::handle(fn() => 1)->mapError(function () {
            throw new RuntimeException("should not run");
        });

        expect($wrap->isOk())->toBeTrue()->and($wrap->getValue())->toBe(1);
    });

    it("mapError() invalidates when mapper throws (previous preserved)", function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException("orig");
        })->mapError(function () {
            throw new RuntimeException("mapper-boom");
        });

        expect($wrap->isOk())
            ->toBeFalse()
            ->and($wrap->getError())
            ->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())
            ->toBeInstanceOf(RuntimeException::class);
    });
});

/**
 * --------------------------------------------------------------------------
 * New helpers: recoverWhen()
 * --------------------------------------------------------------------------
 */
describe("recoverWhen()", function () {
    it("recoverWhen() recovers when error matches class-string", function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException("x");
        })->recoverWhen(RuntimeException::class, fn() => 99);

        expect($wrap->isOk())->toBeTrue()->and($wrap->getError())->toBeNull()->and($wrap->getValue())->toBe(99);
    });

    it("recoverWhen() does not recover when error does not match", function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException("x");
        })->recoverWhen(InvalidArgumentException::class, fn() => 99);

        expect($wrap->isOk())->toBeFalse()->and($wrap->getError())->toBeInstanceOf(RuntimeException::class);
    });

    it("recoverWhen() recovers when predicate returns true", function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException("x");
        })->recoverWhen(fn(Throwable $e) => $e->getMessage() === "x", fn() => "ok");

        expect($wrap->isOk())->toBeTrue()->and($wrap->getValue())->toBe("ok");
    });

    it("recoverWhen() does nothing on success", function () {
        $wrap = Wrap::handle(fn() => 1)->recoverWhen(RuntimeException::class, fn() => 9);

        expect($wrap->isOk())->toBeTrue()->and($wrap->getValue())->toBe(1);
    });
});

/**
 * --------------------------------------------------------------------------
 * New helpers: tap() / tryTap()
 * --------------------------------------------------------------------------
 */
describe("tap() and tryTap()", function () {
    it("tap() runs on success and does not modify value", function () {
        $seen = null;

        $wrap = Wrap::handle(fn() => 5)->tap(function (int $v) use (&$seen) {
            $seen = $v;
        });

        expect($wrap->isOk())->toBeTrue()
            ->and($seen)->toBe(5)
            ->and($wrap->getValue())->toBe(5);
    });

    it("tap() invalidates when callback throws (previous preserved)", function () {
        $wrap = Wrap::handle(fn() => 1)->tap(function () {
            throw new RuntimeException("tap-boom");
        });

        expect($wrap->isOk())
            ->toBeFalse()
            ->and($wrap->getError())
            ->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())
            ->toBeInstanceOf(RuntimeException::class);
    });

    it("tryTap() swallows exceptions and keeps chain ok", function () {
        $wrap = Wrap::handle(fn() => 1)->tryTap(function () {
            throw new RuntimeException("ignored");
        })->then(fn(int $v) => $v + 1);

        expect($wrap->isOk())->toBeTrue()->and($wrap->getValue())->toBe(2);
    });

    it("tryTap() does not run on failure", function () {
        $called = false;

        $wrap = Wrap::handle(function () {
            throw new RuntimeException("x");
        })->tryTap(function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeFalse()->and($wrap->isOk())->toBeFalse();
    });
});

/**
 * --------------------------------------------------------------------------
 * New helpers: failWhen()
 * --------------------------------------------------------------------------
 */
describe("failWhen()", function () {
    it("failWhen() runs only when failed and error matches class-string", function () {
        $called = false;

        Wrap::handle(function () {
            throw new RuntimeException("x");
        })->failWhen(RuntimeException::class, function (RuntimeException $e) use (&$called) {
            $called = $e->getMessage() === "x";
        });

        expect($called)->toBeTrue();
    });

    it("failWhen() does not run when error does not match", function () {
        $called = false;

        Wrap::handle(function () {
            throw new RuntimeException("x");
        })->failWhen(InvalidArgumentException::class, function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeFalse();
    });

    it("failWhen() runs when predicate returns true", function () {
        $called = false;

        Wrap::handle(function () {
            throw new RuntimeException("x");
        })->failWhen(fn(Throwable $e) => $e->getMessage() === "x", function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeTrue();
    });

    it("failWhen() invalidates if callback throws (previous preserved)", function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException("x");
        })->failWhen(RuntimeException::class, function () {
            throw new RuntimeException("failWhen-boom");
        });

        expect($wrap->isOk())
            ->toBeFalse()
            ->and($wrap->getError())
            ->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())
            ->toBeInstanceOf(RuntimeException::class);
    });

    it("failWhen() does nothing on success", function () {
        $called = false;

        $wrap = Wrap::handle(fn() => 1)->failWhen(RuntimeException::class, function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeFalse()->and($wrap->isOk())->toBeTrue();
    });
});

/**
 * --------------------------------------------------------------------------
 * New helpers: rethrowWhen()
 * --------------------------------------------------------------------------
 */
describe("rethrowWhen()", function () {
    it("rethrowWhen() rethrows when error matches class-string", function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException("x");
        });

        try {
            $wrap->rethrowWhen(RuntimeException::class);
            throw new RuntimeException("should not reach");
        } catch (Throwable $e) {
            expect($e)->toBeInstanceOf(RuntimeException::class)
                ->and($e->getMessage())->toBe("x");
        }
    });

    it("rethrowWhen() does not throw when error does not match", function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException("x");
        })->rethrowWhen(InvalidArgumentException::class);

        expect($wrap->isOk())->toBeFalse()->and($wrap->getError())->toBeInstanceOf(RuntimeException::class);
    });

    it("rethrowWhen() does nothing on success", function () {
        $wrap = Wrap::handle(fn() => 1)->rethrowWhen(RuntimeException::class);

        expect($wrap->isOk())->toBeTrue()->and($wrap->getValue())->toBe(1);
    });
});

/**
 * --------------------------------------------------------------------------
 * New helpers: keep() / safeKeep()
 * --------------------------------------------------------------------------
 */
describe("keep() and safeKeep()", function () {
    it("keep() keeps value when predicate is true", function () {
        $wrap = Wrap::handle(fn() => 10)->keep(fn(int $v) => $v > 5);

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getValue())->toBe(10);
    });

    it("keep() turns value into null when predicate is false (still ok)", function () {
        $wrap = Wrap::handle(fn() => 3)->keep(fn(int $v) => $v > 5);

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getValue())->toBeNull();
    });

    it("keep() does nothing when already failed", function () {
        $called = false;

        $wrap = Wrap::handle(function () {
            throw new RuntimeException("x");
        })->keep(function () use (&$called) {
            $called = true;
            return true;
        });

        expect($called)->toBeFalse()
            ->and($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(RuntimeException::class);
    });

    it("safeKeep() invalidates when predicate throws (previous preserved)", function () {
        $wrap = Wrap::handle(fn() => 1)->safeKeep(function () {
            throw new RuntimeException("pred-boom");
        });

        expect($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())->toBeInstanceOf(RuntimeException::class);
    });
});


/**
 * --------------------------------------------------------------------------
 * New helpers: whenValue() / whenValueThen()
 * --------------------------------------------------------------------------
 */
describe("whenValue() and whenValueThen()", function () {
    it("whenValue() runs only when value is not null", function () {
        $seen = null;

        Wrap::handle(fn() => 5)->whenValue(function (int $v) use (&$seen) {
            $seen = $v;
        });

        Wrap::handle(fn() => null)->whenValue(function () use (&$seen) {
            $seen = "should-not-run";
        });

        expect($seen)->toBe(5);
    });

    it("whenValue() invalidates when callback throws (previous preserved)", function () {
        $wrap = Wrap::handle(fn() => 1)->whenValue(function () {
            throw new RuntimeException("boom");
        });

        expect($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())->toBeInstanceOf(RuntimeException::class);
    });

    it("whenValueThen() transforms when value is not null", function () {
        $out = Wrap::handle(fn() => 10)
            ->whenValueThen(fn(int $v) => $v + 5)
            ->getValueOr(-1);

        expect($out)->toBe(15);
    });

    it("whenValueThen() keeps null when value is null", function () {
        $wrap = Wrap::handle(fn() => null)
            ->whenValueThen(function () {
                throw new RuntimeException("should-not-run");
            });

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getValue())->toBeNull();
    });

    it("whenValueThen() invalidates when callback throws (previous preserved)", function () {
        $wrap = Wrap::handle(fn() => 1)->whenValueThen(function () {
            throw new RuntimeException("boom");
        });

        expect($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())->toBeInstanceOf(RuntimeException::class);
    });
});


/**
 * --------------------------------------------------------------------------
 * New helpers: tapError() / tryTapError()
 * --------------------------------------------------------------------------
 */
describe("tapError() and tryTapError()", function () {
    it("tapError() runs only on failure and does not change the error", function () {
        $seen = null;

        $wrap = Wrap::handle(function () {
            throw new RuntimeException("x");
        })->tapError(function (RuntimeException $e) use (&$seen) {
            $seen = $e->getMessage();
        });

        expect($seen)->toBe("x")
            ->and($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(RuntimeException::class)
            ->and($wrap->getError()?->getMessage())->toBe("x");
    });

    it("tapError() does nothing on success", function () {
        $called = false;

        $wrap = Wrap::handle(fn() => 1)->tapError(function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeFalse()
            ->and($wrap->isOk())->toBeTrue()
            ->and($wrap->getValue())->toBe(1);
    });

    it("tapError() invalidates if callback throws (previous preserved)", function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException("orig");
        })->tapError(function () {
            throw new RuntimeException("tapError-boom");
        });

        expect($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())->toBeInstanceOf(RuntimeException::class);
    });

    it("tryTapError() swallows callback exceptions and keeps original error", function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException("orig");
        })->tryTapError(function () {
            throw new RuntimeException("ignored");
        });

        expect($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(RuntimeException::class)
            ->and($wrap->getError()?->getMessage())->toBe("orig");
    });

    it("tryTapError() does nothing on success", function () {
        $called = false;

        $wrap = Wrap::handle(fn() => 1)->tryTapError(function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeFalse()
            ->and($wrap->isOk())->toBeTrue()
            ->and($wrap->getValue())->toBe(1);
    });
});

describe('rescueExcept() with concrete exceptions', function () {
    it('rescues when error is not in except list', function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException('generic');
        })->rescueExcept(ConcreteTestException::class, fn() => 123);

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getValue())->toBe(123)
            ->and($wrap->getError())->toBeNull();
    });

    it('rethrows when error matches except (single)', function () {
        expect(function () {
            Wrap::handle(function () {
                throw new ConcreteTestException('boom');
            })->rescueExcept(ConcreteTestException::class, fn() => 123);
        })->toThrow(ConcreteTestException::class);
    });

    it('rethrows when error matches except (array)', function () {
        expect(function () {
            Wrap::handle(function () {
                throw new ConcreteTestException('boom');
            })->rescueExcept(
                [ConcreteTestException::class, AnotherTestException::class],
                fn() => 123,
            );
        })->toThrow(ConcreteTestException::class);
    });

    it('rethrows when error matches except (varargs)', function () {
        expect(function () {
            Wrap::handle(function () {
                throw new ConcreteTestException('boom');
            })->rescueExcept(
                ConcreteTestException::class,
                AnotherTestException::class,
                fn() => 123,
            );
        })->toThrow(ConcreteTestException::class);
    });

    it('detects wrapped previous error (invalidated case)', function () {
        expect(function () {
            Wrap::handle(function () {
                throw new ConcreteTestException('root');
            })
                // any operation that causes invalidation and wraps previous
                ->safeThen(function () {
                    throw new RuntimeException('later');
                })
                ->rescueExcept(ConcreteTestException::class, fn() => 123);
        })->toThrow(ConcreteTestException::class);
    });
});

describe('rescueWhen() with concrete exceptions', function () {
    it('rescues when predicate returns true', function () {
        $wrap = Wrap::handle(function () {
            throw new ConcreteTestException('boom');
        })->rescueWhen(
            fn(Throwable $e) => $e instanceof ConcreteTestException,
            fn() => 777,
        );

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getValue())->toBe(777)
            ->and($wrap->getError())->toBeNull();
    });

    it('rethrows when predicate returns false', function () {
        expect(function () {
            Wrap::handle(function () {
                throw new ConcreteTestException('boom');
            })->rescueWhen(
                fn(Throwable $e) => !($e instanceof ConcreteTestException),
                fn() => 777,
            );
        })->toThrow(ConcreteTestException::class);
    });

    it('uses previous error when invalidated wraps original', function () {
        expect(function () {
            Wrap::handle(function () {
                throw new ConcreteTestException('root');
            })
                ->safeThen(function () {
                    throw new RuntimeException('later');
                })
                ->rescueWhen(
                    fn(Throwable $e) => !($e instanceof ConcreteTestException),
                    fn() => 777,
                );
        })->toThrow(ConcreteTestException::class);
    });

    it('does nothing on success', function () {
        $wrap = Wrap::handle(fn() => 10)
            ->rescueWhen(fn() => true, fn() => 999);

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getValue())->toBe(10);
    });
});

/**
 * --------------------------------------------------------------------------
 * Added helpers: rescue() fallback arity (callFallback)
 * --------------------------------------------------------------------------
 */
describe('rescue() fallback arity', function () {
    it('rescue() accepts zero-arg fallback (callFallback) and recovers', function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException('x');
        })->rescue(fn() => 123);

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getError())->toBeNull()
            ->and($wrap->getValue())->toBe(123);
    });

    it('rescue() accepts one-arg fallback(Throwable) (callFallback) and recovers', function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException('x');
        })->rescue(function (Throwable $e) {
            return $e->getMessage() === 'x' ? 555 : 0;
        });

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getError())->toBeNull()
            ->and($wrap->getValue())->toBe(555);
    });

    it('rescue() invalidates when fallback throws (previous preserved)', function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException('orig');
        })->rescue(function () {
            throw new RuntimeException('fallback-boom');
        });

        expect($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())->toBeInstanceOf(RuntimeException::class)
            ->and($wrap->getError()?->getPrevious()?->getMessage())->toBe('fallback-boom');
    });

    it('rescue() supports array-callable fallback with zero args (ReflectionMethod path)', function () {
        $obj = new class() {
            public function fallback(): int
            {
                return 900;
            }
        };

        $wrap = Wrap::handle(function () {
            throw new RuntimeException('x');
        })->rescue([$obj, 'fallback']);

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getValue())->toBe(900)
            ->and($wrap->getError())->toBeNull();
    });
});


/**
 * --------------------------------------------------------------------------
 * Added helpers: safeOk / tryOk / safeFail / tryFail
 * --------------------------------------------------------------------------
 */
describe('safeOk/tryOk/safeFail/tryFail', function () {
    it('safeOk() runs on success; invalidates if callback throws (previous preserved)', function () {
        $wrap = Wrap::handle(fn() => 1)->safeOk(function () {
            throw new RuntimeException('boom');
        });

        expect($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())->toBeInstanceOf(RuntimeException::class)
            ->and($wrap->getError()?->getPrevious()?->getMessage())->toBe('boom');
    });

    it('safeOk() does nothing on failure', function () {
        $called = false;

        $wrap = Wrap::handle(function () {
            throw new RuntimeException('x');
        })->safeOk(function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeFalse()
            ->and($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(RuntimeException::class);
    });

    it('tryOk() swallows callback exception and keeps chain ok', function () {
        $wrap = Wrap::handle(fn() => 1)
            ->tryOk(function () {
                throw new RuntimeException('ignored');
            })
            ->then(fn(int $v) => $v + 1);

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getValue())->toBe(2);
    });

    it('safeFail() runs on failure; invalidates if callback throws (previous preserved)', function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException('orig');
        })->safeFail(function () {
            throw new RuntimeException('fail-boom');
        });

        expect($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())->toBeInstanceOf(RuntimeException::class)
            ->and($wrap->getError()?->getPrevious()?->getMessage())->toBe('fail-boom');
    });

    it('tryFail() swallows callback exception and preserves original error', function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException('orig');
        })->tryFail(function () {
            throw new RuntimeException('ignored');
        });

        expect($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(RuntimeException::class)
            ->and($wrap->getError()?->getMessage())->toBe('orig');
    });

    it('safeFail()/tryFail() do nothing on success', function () {
        $calledSafe = false;
        $calledTry = false;

        $wrap = Wrap::handle(fn() => 10)
            ->safeFail(function () use (&$calledSafe) {
                $calledSafe = true;
            })
            ->tryFail(function () use (&$calledTry) {
                $calledTry = true;
            });

        expect($wrap->isOk())->toBeTrue()
            ->and($calledSafe)->toBeFalse()
            ->and($calledTry)->toBeFalse();
    });
});


/**
 * --------------------------------------------------------------------------
 * Added helpers: throwIfFailed / rethrow / rethrowRoot
 * --------------------------------------------------------------------------
 */
describe('throwIfFailed/rethrow/rethrowRoot', function () {
    it('throwIfFailed() does nothing on success', function () {
        $wrap = Wrap::handle(fn() => 1)->throwIfFailed();

        expect($wrap->isOk())->toBeTrue()->and($wrap->getValue())->toBe(1);
    });

    it('throwIfFailed() throws captured error by default on failure', function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException('boom');
        });

        expect(fn() => $wrap->throwIfFailed())
            ->toThrow(RuntimeException::class, 'boom');
    });

    it('throwIfFailed() can map error via factory', function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException('boom');
        });

        expect(function () use ($wrap) {
            $wrap->throwIfFailed(
                fn($err) => new InvalidArgumentException('mapped', 0, $err),
            );
        })->toThrow(InvalidArgumentException::class, 'mapped');
    });

    it('rethrow() throws wrapper error as-is (invalidated InvalidArgumentException)', function () {
        $wrap = Wrap::handle(fn() => 1)->safeThen(function () {
            throw new RuntimeException('root');
        });

        // safeThen invalidates with InvalidArgumentException(previous=root)
        expect(fn() => $wrap->rethrow())
            ->toThrow(InvalidArgumentException::class);
    });

    it('rethrowRoot() throws previous/root error when invalidated wraps previous', function () {
        $wrap = Wrap::handle(fn() => 1)->safeThen(function () {
            throw new RuntimeException('root');
        });

        expect(fn() => $wrap->rethrowRoot())
            ->toThrow(RuntimeException::class, 'root');
    });

    it('rethrowRoot() falls back to wrapper when there is no previous', function () {
        $wrap = Wrap::fromError(new InvalidArgumentException('plain'));

        expect(fn() => $wrap->rethrowRoot())
            ->toThrow(InvalidArgumentException::class, 'plain');
    });
});

/**
 * --------------------------------------------------------------------------
 * New helpers: tee() / tryTee()
 * --------------------------------------------------------------------------
 */
describe('tee() and tryTee()', function () {
    it('tee() runs on success and keeps value', function () {
        $seen = null;

        $wrap = Wrap::handle(fn() => 5)->tee(function (int $v) use (&$seen) {
            $seen = $v;
        });

        expect($wrap->isOk())->toBeTrue()
            ->and($seen)->toBe(5)
            ->and($wrap->getValue())->toBe(5);
    });

    it('tee() invalidates when callback throws', function () {
        $wrap = Wrap::handle(fn() => 1)->tee(function () {
            throw new RuntimeException('boom');
        });

        expect($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())->toBeInstanceOf(RuntimeException::class);
    });

    it('tryTee() swallows exceptions and keeps chain ok', function () {
        $wrap = Wrap::handle(fn() => 1)
            ->tryTee(function () {
                throw new RuntimeException('ignored');
            })
            ->then(fn(int $v) => $v + 1);

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getValue())->toBe(2);
    });
});

/**
 * --------------------------------------------------------------------------
 * New helpers: pipe() / safePipe() / tryPipe()
 * --------------------------------------------------------------------------
 */
describe('pipe() / safePipe() / tryPipe()', function () {
    it('pipe() transforms value on success', function () {
        $out = Wrap::handle(fn() => 10)
            ->pipe(fn(int $v) => $v + 2)
            ->getValueOr(-1);

        expect($out)->toBe(12);
    });

    it('pipe() throws if callback throws (escapes)', function () {
        expect(function () {
            Wrap::handle(fn() => 1)->pipe(function () {
                throw new RuntimeException('explode');
            });
        })->toThrow(RuntimeException::class);
    });

    it('safePipe() invalidates if callback throws', function () {
        $wrap = Wrap::handle(fn() => 1)->safePipe(function () {
            throw new RuntimeException('explode');
        });

        expect($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())->toBeInstanceOf(RuntimeException::class);
    });

    it('tryPipe() swallows exceptions and keeps original value', function () {
        $wrap = Wrap::handle(fn() => 7)->tryPipe(function () {
            throw new RuntimeException('ignored');
        });

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getValue())->toBe(7);
    });
});

/**
 * --------------------------------------------------------------------------
 * New helpers: tapThen() / tryTapThen()
 * --------------------------------------------------------------------------
 */
describe('tapThen() and tryTapThen()', function () {
    it('tapThen() transforms value and can do side effects', function () {
        $seen = null;

        $wrap = Wrap::handle(fn() => 5)->tapThen(function (int $v) use (&$seen) {
            $seen = $v;      // side effect
            return $v + 10;  // transform
        });

        expect($wrap->isOk())->toBeTrue()
            ->and($seen)->toBe(5)
            ->and($wrap->getValue())->toBe(15);
    });

    it('tapThen() invalidates if callback throws', function () {
        $wrap = Wrap::handle(fn() => 1)->tapThen(function () {
            throw new RuntimeException('boom');
        });

        expect($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())->toBeInstanceOf(RuntimeException::class);
    });

    it('tryTapThen() swallows exceptions and keeps original value', function () {
        $wrap = Wrap::handle(fn() => 9)->tryTapThen(function () {
            throw new RuntimeException('ignored');
        });

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getValue())->toBe(9);
    });
});

/**
 * --------------------------------------------------------------------------
 * New helpers: fold() / foldWrap()
 * --------------------------------------------------------------------------
 */
describe('fold() and foldWrap()', function () {
    it('fold() returns mapped value on success', function () {
        $out = Wrap::handle(fn() => 10)->fold(
            fn(int $v) => $v + 1,
            fn() => 0,
        );

        expect($out)->toBe(11);
    });

    it('fold() returns mapped value on failure', function () {
        $out = Wrap::handle(function () {
            throw new RuntimeException('x');
        })->fold(
            fn() => 'ok',
            fn(?Throwable $e) => 'fail:' . ($e?->getMessage() ?? 'none'),
        );

        expect($out)->toBe('fail:x');
    });

    it('foldWrap() replaces chain with Wrap returned by handler', function () {
        $wrap = Wrap::handle(fn() => 3)->foldWrap(
            fn(int $v) => Wrap::fromValue($v * 2),
            fn() => Wrap::fromValue(0),
        );

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getValue())->toBe(6);
    });

    it('foldWrap() invalidates if handler returns non-Wrap', function () {
        $wrap = Wrap::handle(fn() => 3)->foldWrap(
            fn() => 123,
            fn() => Wrap::fromValue(0),
        );

        expect($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(InvalidArgumentException::class);
    });

    it('foldWrap() invalidates if handler throws (previous preserved)', function () {
        $wrap = Wrap::handle(fn() => 3)->foldWrap(
            function () {
                throw new RuntimeException('boom');
            },
            fn() => Wrap::fromValue(0),
        );

        expect($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())->toBeInstanceOf(RuntimeException::class);
    });
});

/**
 * --------------------------------------------------------------------------
 * New helpers: matchError()
 * --------------------------------------------------------------------------
 */
describe('matchError()', function () {
    it('matchError() rescues with value when matched', function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException('x');
        })->matchError([
            [RuntimeException::class, fn(RuntimeException $e) => 123],
        ]);

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getError())->toBeNull()
            ->and($wrap->getValue())->toBe(123);
    });

    it('matchError() replaces error when handler returns Throwable', function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException('orig');
        })->matchError([
            [RuntimeException::class, fn(Throwable $e) => new InvalidArgumentException('mapped', 0, $e)],
        ]);

        expect($wrap->isOk())->toBeFalse()
            ->and($wrap->getError())->toBeInstanceOf(InvalidArgumentException::class)
            ->and($wrap->getError()?->getPrevious())->toBeInstanceOf(RuntimeException::class)
            ->and($wrap->getError()?->getMessage())->toBe('mapped');
    });

    it('matchError() replaces whole chain when handler returns Wrap', function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException('x');
        })->matchError([
            [RuntimeException::class, fn() => Wrap::fromValue('recovered')],
        ]);

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getError())->toBeNull()
            ->and($wrap->getValue())->toBe('recovered');
    });

    it('matchError() supports predicate matcher', function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException('x');
        })->matchError([
            [fn(Throwable $e) => $e->getMessage() === 'x', fn() => 'ok'],
        ]);

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getValue())->toBe('ok');
    });

    it('matchError() uses default when no cases match', function () {
        $wrap = Wrap::handle(function () {
            throw new RuntimeException('x');
        })->matchError(
            [
                [InvalidArgumentException::class, fn() => 1],
            ],
            fn(Throwable $e) => 999,
        );

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getValue())->toBe(999);
    });

    it('matchError() can rethrow by throwing inside handler', function () {
        expect(function () {
            Wrap::handle(function () {
                throw new ConcreteTestException('infra');
            })->matchError([
                [ConcreteTestException::class, function (Throwable $e) {
                    throw $e;
                }],
            ]);
        })->toThrow(ConcreteTestException::class);
    });

    it('matchError() does nothing on success', function () {
        $wrap = Wrap::handle(fn() => 10)->matchError([
            [RuntimeException::class, fn() => 1],
        ]);

        expect($wrap->isOk())->toBeTrue()
            ->and($wrap->getValue())->toBe(10);
    });
});
