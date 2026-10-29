<?php

declare(strict_types=1);

namespace Ipase\Laravel;

use Illuminate\Contracts\Cache\Repository;
use Ipase\Identity\DeviceIdStore;
use Ipase\Support\Uuid;

/**
 * The backend's own device id in the cache store (ADR 0003). add() only writes when the key
 * is missing, so concurrent workers settle on the first id written.
 */
final class CacheDeviceIdStore implements DeviceIdStore
{
    public const KEY = 'ipase:device_id';

    private ?string $id = null;

    public function __construct(private readonly Repository $cache) {}

    public function deviceId(): string
    {
        if ($this->id !== null) {
            return $this->id;
        }
        $minted = Uuid::v4();
        $this->cache->add(self::KEY, $minted);
        $stored = $this->cache->get(self::KEY);

        return $this->id = is_string($stored) && Uuid::isValid($stored) ? $stored : $minted;
    }
}
