<?php

declare(strict_types=1);

use Ipase\Identity\FileDeviceIdStore;
use Ipase\Identity\InMemoryDeviceIdStore;
use Ipase\Support\Uuid;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/ipase-test-'.bin2hex(random_bytes(4));
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    is_dir($this->dir) && rmdir($this->dir);
});

it('mints a UUIDv4 once and persists it in a file', function () {
    $first = (new FileDeviceIdStore($this->dir.'/device-id'))->deviceId();
    $second = (new FileDeviceIdStore($this->dir.'/device-id'))->deviceId();

    expect($first)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($second)->toBe($first)
        ->and(trim((string) file_get_contents($this->dir.'/device-id')))->toBe($first)
        ->and(glob($this->dir.'/*'))->toHaveCount(1);
});

it('replaces a file that does not hold a valid id', function () {
    mkdir($this->dir);
    file_put_contents($this->dir.'/device-id', 'garbage');

    expect(Uuid::isValid((new FileDeviceIdStore($this->dir.'/device-id'))->deviceId()))->toBeTrue();
});

it('keeps a given id in memory, or mints one', function () {
    expect((new InMemoryDeviceIdStore('6f1c2a7e-0d4b-4f3a-9a5e-2b7c8d9e0f11'))->deviceId())->toBe('6f1c2a7e-0d4b-4f3a-9a5e-2b7c8d9e0f11')
        ->and(Uuid::isValid((new InMemoryDeviceIdStore)->deviceId()))->toBeTrue();
});
