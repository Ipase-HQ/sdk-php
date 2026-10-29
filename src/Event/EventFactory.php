<?php

declare(strict_types=1);

namespace Ipase\Event;

use Closure;
use Ipase\Clock\Time;
use Ipase\Identity\DeviceIdStore;
use Ipase\Identity\ForwardedIds;
use Ipase\Refusal;
use Ipase\Support\Uuid;
use JsonException;
use Psr\Clock\ClockInterface;

/**
 * Builds server-side events (protocol section 11.3, ADR 0003) and validates them locally
 * (section 15). An event leaves here as its serialized JSON, which is what gets queued: the
 * size check needs the encoding anyway, and a batch body is the events joined by commas.
 */
final class EventFactory
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * @param  array<string, mixed>  $context
     * @param  Closure(): ForwardedIds  $forwarded  the ids of the current request, read per event
     */
    public function __construct(
        private readonly array $context,
        private readonly ClockInterface $clock,
        private readonly DeviceIdStore $devices,
        private readonly Closure $forwarded,
    ) {}

    /** @param  array<mixed>  $properties */
    public function track(string $name, array $properties = [], ?string $userId = null): string|Refusal
    {
        $reason = Validator::name($name) ?? Validator::userId($userId) ?? Validator::properties($properties);
        if ($reason !== null) {
            return new Refusal($reason, $name);
        }

        return $this->encode($name, $this->envelope('track', $userId, ['name' => $name]) + ($properties === [] ? [] : ['properties' => (object) $properties]));
    }

    /** @param  array<mixed>  $traits */
    public function identify(string $userId, array $traits = []): string|Refusal
    {
        $reason = Validator::userId($userId) ?? Validator::properties($traits);
        if ($reason !== null) {
            return new Refusal($reason, 'identify');
        }

        return $this->encode('identify', $this->envelope('identify', $userId) + ($traits === [] ? [] : ['traits' => (object) $traits]));
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function envelope(string $type, ?string $userId, array $fields = []): array
    {
        $forwarded = ($this->forwarded)();

        return ['event_id' => Uuid::v7(Time::ms($this->clock)), 'type' => $type]
            + $fields
            + ['device_id' => $forwarded->deviceId ?? $this->devices->deviceId()]
            + ($userId === null ? [] : ['user_id' => $userId])
            + [
                'session_id' => $forwarded->sessionId ?? Uuid::NIL,
                'seq' => 0,
                'timestamp' => Time::rfc3339($this->clock),
            ];
    }

    /** @param  array<string, mixed>  $event */
    private function encode(string $name, array $event): string|Refusal
    {
        try {
            $json = json_encode($event + ['context' => $this->context], self::JSON_FLAGS);
        } catch (JsonException) {
            return new Refusal(Refusal::INVALID_PROPERTY, $name);
        }

        return strlen($json) > Validator::MAX_EVENT_BYTES ? new Refusal(Refusal::EVENT_TOO_LARGE, $name) : $json;
    }
}
