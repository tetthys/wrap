<?php

use Tetthys\Wrap\Wrap;

it('maps values', function () {
    $w = Wrap::handle(fn() => [1, 2, 3])
        ->map(fn($v) => $v * 2);

    expect($w->isOk())->toBeTrue()
        ->and($w->getValue())->toBe([2, 4, 6]);
});

it('filters values', function () {
    $w = Wrap::handle(fn() => [1, 2, 3, 4, 5])
        ->filter(fn($v) => $v % 2 === 0);

    expect($w->isOk())->toBeTrue()
        ->and($w->getValue())->toBe([1 => 2, 3 => 4]);
});

it('reduces values', function () {
    $w = Wrap::handle(fn() => [1, 2, 3, 4])
        ->reduce(fn($acc, $v) => $acc + $v, 0);

    expect($w->getValueOr(-1))->toBe(10);
});

it('handles exceptions', function () {
    $w = Wrap::handle(fn() => throw new RuntimeException('fail'))
        ->rescue(fn() => [1, 2, 3])
        ->map(fn($v) => $v * 2);

    expect($w->isOk())->toBeTrue()
        ->and($w->getValue())->toBe([2, 4, 6]);
});
