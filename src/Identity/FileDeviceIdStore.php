<?php

declare(strict_types=1);

namespace Ipase\Identity;

use Ipase\Support\Uuid;

/**
 * Keeps the device id in a file, the plain client's default. The first process to create the
 * file wins, so concurrent workers on one machine agree on one id.
 */
final class FileDeviceIdStore implements DeviceIdStore
{
    private ?string $id = null;

    public function __construct(private readonly string $path) {}

    public static function default(): self
    {
        return new self(sys_get_temp_dir().DIRECTORY_SEPARATOR.'ipase'.DIRECTORY_SEPARATOR.'device-id');
    }

    public function deviceId(): string
    {
        return $this->id ??= $this->read() ?? $this->mint();
    }

    private function read(): ?string
    {
        $value = is_file($this->path) ? trim((string) @file_get_contents($this->path)) : null;

        return Uuid::isValid($value) ? $value : null;
    }

    private function mint(): string
    {
        $id = Uuid::v4();
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $tmp = $this->path.'.'.bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $id) === false) {
            // Not writable: the id holds for this process only.
            return $id;
        }
        if (is_file($this->path)) {
            // A file without a valid id: replace it.
            @rename($tmp, $this->path);
        } elseif (! @link($tmp, $this->path) && ! is_file($this->path)) {
            // link() publishes the complete file atomically and fails when another process got
            // there first, in which case theirs is the id. rename() covers file systems without links.
            @rename($tmp, $this->path);
        }
        if (is_file($tmp)) {
            @unlink($tmp);
        }

        return $this->read() ?? $id;
    }
}
