<?php

declare(strict_types=1);

namespace Ipase;

/**
 * An event refused before queueing (protocol section 15). Reported once through the logger
 * and through the log hook the conformance harness reads.
 */
final class Refusal
{
    public const INVALID_NAME = 'invalid_name';

    public const RESERVED_NAME = 'reserved_name';

    public const RESERVED_PROPERTY_KEY = 'reserved_property_key';

    public const INVALID_USER_ID = 'invalid_user_id';

    public const PROPERTIES_TOO_MANY = 'properties_too_many';

    public const PROPERTY_KEY_TOO_LONG = 'property_key_too_long';

    public const PROPERTY_VALUE_TOO_LONG = 'property_value_too_long';

    public const INVALID_PROPERTY = 'invalid_property';

    public const EVENT_TOO_LARGE = 'event_too_large';

    public function __construct(
        public readonly string $reason,
        public readonly string $name,
    ) {}

    /** @return array{reason: string, name: string} */
    public function toArray(): array
    {
        return ['reason' => $this->reason, 'name' => $this->name];
    }
}
