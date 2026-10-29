<?php

declare(strict_types=1);

namespace Ipase\Identity;

/**
 * Where the server SDK keeps the device id it minted for itself (ADR 0003): one backend is
 * one device. Used only when the request did not forward a valid X-Device-Id.
 */
interface DeviceIdStore
{
    /** The persisted id, minted as a UUIDv4 on first use. */
    public function deviceId(): string;
}
