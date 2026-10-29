<?php

declare(strict_types=1);

namespace Ipase\Support;

final class Uuid
{
    public const NIL = '00000000-0000-0000-0000-000000000000';

    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * RFC 9562 UUIDv7: 48 bits of Unix milliseconds, version nibble 7, 12 random bits, the
     * variant, 62 random bits. Lowercase.
     */
    public static function v7(int $unixMs): string
    {
        $bytes = pack('J', $unixMs);
        $bytes = substr($bytes, 2, 6).random_bytes(10);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return self::format($bytes);
    }

    /** RFC 9562 UUIDv4, lowercase. */
    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return self::format($bytes);
    }

    /** A lowercase UUID of any version, the nil UUID included. Anything else is not forwarded. */
    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    private static function format(string $bytes): string
    {
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
