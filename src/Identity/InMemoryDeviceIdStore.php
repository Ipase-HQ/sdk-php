<?php

declare(strict_types=1);

namespace Ipase\Identity;

use Ipase\Support\Uuid;

/** Lives as long as the object. For tests and for processes that persist the id themselves. */
final class InMemoryDeviceIdStore implements DeviceIdStore
{
    private string $id;

    public function __construct(?string $id = null)
    {
        $this->id = Uuid::isValid($id) ? (string) $id : Uuid::v4();
    }

    public function deviceId(): string
    {
        return $this->id;
    }
}
