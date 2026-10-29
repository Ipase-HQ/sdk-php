<?php

declare(strict_types=1);

namespace Ipase\Identity;

use Ipase\Support\Uuid;

/**
 * The client ids the current HTTP request forwarded in X-Session-Id and X-Device-Id
 * (protocol section 11.3). A value that is not a lowercase UUID is ignored.
 */
final class ForwardedIds
{
    public const SESSION_HEADER = 'X-Session-Id';

    public const DEVICE_HEADER = 'X-Device-Id';

    public readonly ?string $sessionId;

    public readonly ?string $deviceId;

    public function __construct(?string $sessionId = null, ?string $deviceId = null)
    {
        $this->sessionId = Uuid::isValid($sessionId) ? $sessionId : null;
        $this->deviceId = Uuid::isValid($deviceId) ? $deviceId : null;
    }

    /**
     * From a header map in any case, values as strings or lists of strings (PSR-7 and
     * Symfony both shape them that way).
     *
     * @param  array<string, string|list<string>|null>  $headers
     */
    public static function fromHeaders(array $headers): self
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $first = static function (mixed $value): ?string {
            $value = is_array($value) ? ($value[0] ?? null) : $value;

            return is_string($value) ? trim($value) : null;
        };

        return new self(
            $first($headers[strtolower(self::SESSION_HEADER)] ?? null),
            $first($headers[strtolower(self::DEVICE_HEADER)] ?? null),
        );
    }
}
