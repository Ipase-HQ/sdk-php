<?php

declare(strict_types=1);

namespace Ipase;

use Closure;
use GuzzleHttp\ClientInterface;
use Ipase\Clock\Sleeper;
use Ipase\Clock\SystemClock;
use Ipase\Clock\SystemSleeper;
use Ipase\Clock\Time;
use Ipase\Delivery\InMemoryRetryState;
use Ipase\Delivery\Sender;
use Ipase\Event\Context;
use Ipase\Event\EventFactory;
use Ipase\Identity\DeviceIdStore;
use Ipase\Identity\FileDeviceIdStore;
use Ipase\Identity\ForwardedIds;
use Ipase\Transport\GuzzleTransport;
use Ipase\Transport\Transport;
use Locale;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * The plain PHP client, no framework needed. Events are validated, queued in memory and sent
 * in batches: when 100 are queued, when the oldest has waited 5 seconds (checked on every
 * call, since PHP has no timers; long-running processes can call tick()), on flush(), and on
 * shutdown. Under Laravel use the Ipase facade instead, which queues through the Laravel queue.
 */
final class Client
{
    /** @var list<string> serialized events in queue order */
    private array $queue = [];

    /** Unix ms at which tick() sends: the flush interval, or the end of a retry wait. */
    private ?int $dueAt = null;

    private ForwardedIds $forwarded;

    private readonly EventFactory $events;

    private readonly Sender $sender;

    private readonly DeviceIdStore $deviceIds;

    private readonly ClockInterface $clock;

    private readonly Sleeper $sleeper;

    private readonly LoggerInterface $logger;

    /**
     * @param  string  $serverKey  the project's sk_live_ key; never logged
     * @param  string  $endpoint  ingest origin; /v1/batch is appended
     * @param  string  $appName  context.app.name
     * @param  string  $appVersion  context.app.version
     * @param  string|null  $locale  context.locale; defaults to the intl default locale
     * @param  string|null  $timezone  context.timezone; defaults to date_default_timezone_get()
     * @param  int  $flushAt  queued events that trigger a send
     * @param  float  $flushInterval  seconds the oldest queued event may wait
     * @param  int  $maxQueue  capacity; the oldest event is dropped beyond it
     * @param  float  $timeout  seconds before a request counts as a network failure
     * @param  bool  $flushOnShutdown  send what is queued when the process ends
     * @param  float  $shutdownTimeout  seconds the shutdown flush may sit out retry waits
     * @param  DeviceIdStore|null  $deviceIds  where the minted device id lives; a temp file by default
     * @param  LoggerInterface|null  $logger  PSR-3 logger; warnings go to error_log() by default
     * @param  (Closure(Refusal): void)|null  $logHook  called for every local refusal (protocol section 17)
     * @param  ClockInterface|null  $clock  test hook
     * @param  Sleeper|null  $sleeper  test hook, used only by shutdown()
     * @param  ClientInterface|null  $httpClient  a configured Guzzle client
     * @param  Transport|null  $transport  replaces Guzzle entirely
     * @param  (Closure(): float)|null  $random  test hook for the backoff jitter, uniform in [0, 1)
     */
    public function __construct(
        #[SensitiveParameter] string $serverKey,
        string $endpoint = Version::DEFAULT_ENDPOINT,
        string $appName = 'unknown',
        string $appVersion = 'unknown',
        ?string $locale = null,
        ?string $timezone = null,
        private readonly int $flushAt = 100,
        private readonly float $flushInterval = 5.0,
        private readonly int $maxQueue = 10_000,
        float $timeout = 30.0,
        bool $flushOnShutdown = true,
        private readonly float $shutdownTimeout = 10.0,
        ?DeviceIdStore $deviceIds = null,
        ?LoggerInterface $logger = null,
        private readonly ?Closure $logHook = null,
        ?ClockInterface $clock = null,
        ?Sleeper $sleeper = null,
        ?ClientInterface $httpClient = null,
        ?Transport $transport = null,
        ?Closure $random = null,
    ) {
        $this->clock = $clock ?? new SystemClock;
        $this->sleeper = $sleeper ?? new SystemSleeper;
        $this->logger = $logger ?? new Support\ErrorLogLogger;
        $this->deviceIds = $deviceIds ?? FileDeviceIdStore::default();
        $this->forwarded = new ForwardedIds;

        $context = Context::server(
            $appName,
            $appVersion,
            $locale ?? (class_exists(Locale::class) ? Locale::getDefault() : 'en'),
            $timezone ?? date_default_timezone_get(),
        );
        $this->events = new EventFactory($context, $this->clock, $this->deviceIds, fn (): ForwardedIds => $this->forwarded);
        $this->sender = new Sender(
            $transport ?? new GuzzleTransport($serverKey, $endpoint, $httpClient, $timeout),
            new InMemoryRetryState,
            $this->clock,
            $this->logger,
            Sender::keyLabel($serverKey),
            $random,
        );

        if ($flushOnShutdown) {
            register_shutdown_function($this->shutdown(...));
        }
    }

    /**
     * Queues a track event. Returns false when the event was refused locally (protocol
     * section 15); the refusal is logged and reported to the log hook.
     *
     * @param  array<string, string|int|float|bool|null>  $properties
     */
    public function track(string $name, array $properties = [], ?string $userId = null): bool
    {
        return $this->enqueue($this->events->track($name, $properties, $userId));
    }

    /**
     * Queues an identify event, which merges traits into the user's profile.
     *
     * @param  array<string, string|int|float|bool|null>  $traits
     */
    public function identify(string $userId, array $traits = []): bool
    {
        return $this->enqueue($this->events->identify($userId, $traits));
    }

    /**
     * Forwards the client's ids from the request being handled (ADR 0003). Values that are not
     * lowercase UUIDs are ignored. Call forward(null, null) when the request ends.
     */
    public function forward(?string $sessionId, ?string $deviceId): void
    {
        $this->forwarded = new ForwardedIds($sessionId, $deviceId);
    }

    /**
     * forward() from a request's header map, for example getallheaders() or a PSR-7 request's
     * getHeaders().
     *
     * @param  array<string, string|list<string>|null>  $headers
     */
    public function forwardHeaders(array $headers): void
    {
        $this->forwarded = ForwardedIds::fromHeaders($headers);
    }

    /** The device id this backend minted for itself, used when no X-Device-Id is forwarded. */
    public function deviceId(): string
    {
        return $this->deviceIds->deviceId();
    }

    /**
     * Sends everything queued, batch after batch, and returns when no request is in flight.
     * Sends nothing while a retry wait is in progress or after the server refused the key.
     */
    public function flush(): void
    {
        if ($this->queue === [] || ! $this->sender->canSend()) {
            return;
        }
        $this->queue = $this->sender->drain($this->queue);
        $wait = $this->sender->waitMs();
        $this->dueAt = $this->queue === [] ? null : Time::ms($this->clock) + ($wait > 0 ? $wait : $this->intervalMs());
    }

    /** Sends when the flush interval or a retry wait has run out. For long-running processes. */
    public function tick(): void
    {
        if ($this->dueAt !== null && Time::ms($this->clock) >= $this->dueAt) {
            $this->flush();
        }
    }

    /**
     * Flushes, sitting out retry waits for up to shutdownTimeout seconds. Registered as a
     * shutdown function unless flushOnShutdown is false; safe to call more than once.
     */
    public function shutdown(): void
    {
        $deadline = Time::ms($this->clock) + (int) ($this->shutdownTimeout * 1000);
        $this->flush();
        while ($this->queue !== [] && ! $this->sender->halted()) {
            $wait = $this->sender->waitMs();
            if (Time::ms($this->clock) + $wait > $deadline) {
                break;
            }
            $this->sleeper->sleep($wait);
            $this->flush();
        }
        if ($this->queue !== []) {
            $this->logger->warning('ipase: '.count($this->queue).' events were not sent before shutdown');
            $this->queue = [];
            $this->dueAt = null;
        }
    }

    /** The number of events waiting to be sent. */
    public function queued(): int
    {
        return count($this->queue);
    }

    private function enqueue(string|Refusal $event): bool
    {
        if ($event instanceof Refusal) {
            $this->logger->warning("ipase: refused event \"{$event->name}\": {$event->reason}");
            if ($this->logHook !== null) {
                ($this->logHook)($event);
            }

            return false;
        }

        if (count($this->queue) >= $this->maxQueue) {
            array_shift($this->queue);
            $this->logger->warning("ipase: the queue holds {$this->maxQueue} events; dropped the oldest");
        }
        $this->queue[] = $event;
        $this->dueAt ??= Time::ms($this->clock) + $this->intervalMs();

        if (count($this->queue) >= $this->flushAt) {
            $this->flush();
        } else {
            $this->tick();
        }

        return true;
    }

    private function intervalMs(): int
    {
        return (int) ($this->flushInterval * 1000);
    }
}
