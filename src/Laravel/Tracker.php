<?php

declare(strict_types=1);

namespace Ipase\Laravel;

use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Ipase\Clock\Time;
use Ipase\Event\EventFactory;
use Ipase\Refusal;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The object behind the Ipase facade. track() and identify() validate and buffer; the buffer
 * becomes a SendBatch job when it holds flush_at events, when its oldest event has waited
 * flush_interval seconds (checked on every call), on flush(), and when the request, queued
 * job or command ends.
 */
final class Tracker
{
    /** @var list<string> */
    private array $buffer = [];

    private ?int $oldestAt = null;

    /** @var list<Closure(Refusal): void> */
    private array $refusalHooks = [];

    /**
     * @param  EventFactory|null  $events  null when Ipase is disabled or has no key
     * @param  Closure(): Dispatcher  $bus  resolved per dispatch, so Bus::fake() is honoured
     */
    public function __construct(
        private readonly ?EventFactory $events,
        private readonly Closure $bus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly int $flushAt = 100,
        private readonly float $flushInterval = 5.0,
        private readonly ?string $connection = null,
        private readonly ?string $queue = null,
    ) {}

    /**
     * Buffers a track event for user_id $userId, or for no user. Returns false when the event
     * was refused locally or Ipase is disabled.
     *
     * @param  array<string, string|int|float|bool|null>  $properties
     */
    public function track(string $name, array $properties = [], ?string $userId = null): bool
    {
        return $this->events !== null && $this->buffer($this->events->track($name, $properties, $userId));
    }

    /**
     * Buffers an identify event, which merges traits into the user's profile.
     *
     * @param  array<string, string|int|float|bool|null>  $traits
     */
    public function identify(string $userId, array $traits = []): bool
    {
        return $this->events !== null && $this->buffer($this->events->identify($userId, $traits));
    }

    /** Hands everything buffered to the queue now. */
    public function flush(): void
    {
        // Empty the buffer first: on the sync queue the job runs inside dispatch(), and its
        // JobProcessed event flushes again.
        $events = $this->buffer;
        $this->buffer = [];
        $this->oldestAt = null;
        foreach (array_chunk($events, max(1, $this->flushAt)) as $chunk) {
            ($this->bus)()->dispatch((new SendBatch($chunk))->onConnection($this->connection)->onQueue($this->queue));
        }
    }

    /**
     * Calls $hook for every local refusal (protocol section 17), in addition to the warning
     * logged for it.
     *
     * @param  Closure(Refusal): void  $hook
     */
    public function onRefusal(Closure $hook): void
    {
        $this->refusalHooks[] = $hook;
    }

    /** The number of events buffered and not yet handed to the queue. */
    public function pending(): int
    {
        return count($this->buffer);
    }

    private function buffer(string|Refusal $event): bool
    {
        if ($event instanceof Refusal) {
            $this->logger->warning("ipase: refused event \"{$event->name}\": {$event->reason}");
            foreach ($this->refusalHooks as $hook) {
                $hook($event);
            }

            return false;
        }

        $now = Time::ms($this->clock);
        $this->buffer[] = $event;
        $this->oldestAt ??= $now;
        if (count($this->buffer) >= $this->flushAt || $now - $this->oldestAt >= $this->flushInterval * 1000) {
            $this->flush();
        }

        return true;
    }
}
