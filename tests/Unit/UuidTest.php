<?php

declare(strict_types=1);

use Ipase\Support\Uuid;

it('mints lowercase UUIDv7 carrying the clock in its first 48 bits', function () {
    $ms = 1768468080250; // 2026-01-15T09:08:00.250Z
    $id = Uuid::v7($ms);

    expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and(hexdec(str_replace('-', '', substr($id, 0, 13))))->toBe($ms)
        ->and(Uuid::v7($ms))->not->toBe($id);
});

it('mints lowercase UUIDv4', function () {
    expect(Uuid::v4())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('accepts only lowercase UUIDs', function (mixed $value, bool $valid) {
    expect(Uuid::isValid($value))->toBe($valid);
})->with([
    ['0190c1a2-7b3c-7d4e-8f5a-6b7c8d9e0f1a', true],
    [Uuid::NIL, true],
    ['0190C1A2-7B3C-7D4E-8F5A-6B7C8D9E0F1A', false],
    ['0190c1a27b3c7d4e8f5a6b7c8d9e0f1a', false],
    ['{0190c1a2-7b3c-7d4e-8f5a-6b7c8d9e0f1a}', false],
    ['', false],
    [null, false],
    [42, false],
]);
