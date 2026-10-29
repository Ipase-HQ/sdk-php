<?php

declare(strict_types=1);

namespace Ipase\Event;

use Ipase\Refusal;

/**
 * The local checks of protocol section 15. Each method answers the refusal reason, or null
 * when the input passes. Lengths count characters, as the schema does, not bytes.
 */
final class Validator
{
    public const MAX_NAME = 128;

    public const MAX_USER_ID = 128;

    public const MAX_KEYS = 100;

    public const MAX_KEY = 64;

    public const MAX_VALUE = 1024;

    public const MAX_EVENT_BYTES = 32 * 1024;

    public static function name(string $name): ?string
    {
        if ($name === '' || ! mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > self::MAX_NAME) {
            return Refusal::INVALID_NAME;
        }

        return str_starts_with($name, '$') ? Refusal::RESERVED_NAME : null;
    }

    public static function userId(?string $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        return $userId === '' || ! mb_check_encoding($userId, 'UTF-8') || mb_strlen($userId, 'UTF-8') > self::MAX_USER_ID
            ? Refusal::INVALID_USER_ID
            : null;
    }

    /** @param  array<mixed>  $properties */
    public static function properties(array $properties): ?string
    {
        if (count($properties) > self::MAX_KEYS) {
            return Refusal::PROPERTIES_TOO_MANY;
        }
        foreach ($properties as $key => $value) {
            // PHP turns numeric string keys into ints; on the wire they are strings again.
            $key = (string) $key;
            if ($key === '' || ! mb_check_encoding($key, 'UTF-8') || mb_strlen($key, 'UTF-8') > self::MAX_KEY) {
                return Refusal::PROPERTY_KEY_TOO_LONG;
            }
            if (str_starts_with($key, '$')) {
                return Refusal::RESERVED_PROPERTY_KEY;
            }
            if (is_string($value)) {
                if (! mb_check_encoding($value, 'UTF-8')) {
                    return Refusal::INVALID_PROPERTY;
                }
                if (mb_strlen($value, 'UTF-8') > self::MAX_VALUE) {
                    return Refusal::PROPERTY_VALUE_TOO_LONG;
                }
            } elseif (is_float($value)) {
                if (! is_finite($value)) {
                    return Refusal::INVALID_PROPERTY;
                }
            } elseif (! is_int($value) && ! is_bool($value) && $value !== null) {
                return Refusal::INVALID_PROPERTY;
            }
        }

        return null;
    }
}
