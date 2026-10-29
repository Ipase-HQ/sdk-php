<?php

declare(strict_types=1);

namespace Ipase\Delivery;

use Closure;
use Ipase\Clock\Time;
use Ipase\Transport\Response;
use Ipase\Transport\Transport;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends queued events in batches under the response and retry rules of protocol sections 3
 * and 4. One Sender is one SDK instance: sending is synchronous, so one request is in flight
 * at a time, and a halt after 401 or an unexpected status lasts until the next launch, which
 * for PHP is the next process.
 */
final class Sender
{
    public const MAX_EVENTS = 500;

    /** The uncompressed body stays under 1 MB, so the compressed one fits too (section 2.4). */
    public const MAX_BODY_BYTES = 1024 * 1024;

    public const MAX_BACKOFF_SECONDS = 300;

    /** {"sent_at":"2026-01-15T09:08:00.250Z","batch":[]} */
    private const ENVELOPE_BYTES = 50;

    private bool $halted = false;

    /** @var Closure(): float */
    private readonly Closure $random;

    /**
     * @param  string  $keyLabel  how logs name the key: its prefix, never the key
     * @param  (Closure(): float)|null  $random  uniform in [0, 1), for the backoff jitter
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly RetryState $state,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly string $keyLabel,
        ?Closure $random = null,
    ) {
        $this->random = $random ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /** The label logs use for a server key: its sk_live_ style prefix and nothing after it. */
    public static function keyLabel(string $key): string
    {
        return preg_match('/^[a-z]+_[a-z]+_/', $key, $m) === 1 ? $m[0].'…' : 'key';
    }

    public function halted(): bool
    {
        return $this->halted;
    }

    /** Milliseconds left in the wait in progress, 0 when there is none. */
    public function waitMs(): int
    {
        $until = $this->state->blockedUntil();

        return $until === null ? 0 : max(0, $until - Time::ms($this->clock));
    }

    public function canSend(): bool
    {
        return ! $this->halted && $this->waitMs() === 0;
    }

    /**
     * Sends batch after batch until the events are gone, a wait starts or sending halts.
     *
     * @param  list<string>  $events  serialized events in queue order
     * @return list<string> the events that stay queued, in order
     */
    public function drain(array $events): array
    {
        while ($events !== [] && $this->canSend()) {
            $size = self::batchSize($events);
            $kept = $this->sendBatch(array_slice($events, 0, $size));
            $events = [...$kept, ...array_slice($events, $size)];
            if ($kept !== []) {
                break;
            }
        }

        return $events;
    }

    /**
     * How many events from the head of the queue make one batch: at most 500, and the body
     * stays under 1 MB.
     *
     * @param  list<string>  $events
     */
    public static function batchSize(array $events): int
    {
        $bytes = self::ENVELOPE_BYTES;
        $count = 0;
        foreach ($events as $event) {
            $bytes += strlen($event) + ($count > 0 ? 1 : 0);
            if ($count === self::MAX_EVENTS || ($count > 0 && $bytes >= self::MAX_BODY_BYTES)) {
                break;
            }
            $count++;
        }

        return $count;
    }

    /**
     * @param  list<string>  $batch
     * @return list<string> the events that stay queued
     */
    private function sendBatch(array $batch): array
    {
        if (! $this->canSend()) {
            return $batch;
        }

        $body = '{"sent_at":'.json_encode(Time::rfc3339($this->clock)).',"batch":['.implode(',', $batch).']}';
        $response = $this->transport->send($body);
        $status = $response->status;

        if ($status === 429 || $status === 503) {
            $response->retryAfter === null ? $this->backoff($response) : $this->wait($response->retryAfter * 1000, $response);

            return $batch;
        }
        if ($status === 0 || $status >= 500) {
            $this->backoff($response);

            return $batch;
        }

        // Every other response ends the retry sequence.
        $this->state->setAttempts(0);

        if ($status === 202) {
            foreach ($this->rejections($response) as [$index, $reason]) {
                $this->logger->warning("ipase: the server rejected event {$index} of a batch of ".count($batch).": {$reason}");
            }

            return [];
        }
        if ($status === 400) {
            $this->logger->warning('ipase: dropped a batch of '.count($batch).' events the server called malformed: '.$this->describe($response));

            return [];
        }
        if ($status === 413) {
            if (count($batch) === 1) {
                $this->logger->warning('ipase: dropped an event the server called too large: '.$this->describe($response));

                return [];
            }
            $half = intdiv(count($batch) + 1, 2);
            $first = $this->sendBatch(array_slice($batch, 0, $half));
            if ($first !== []) {
                return [...$first, ...array_slice($batch, $half)];
            }

            return $this->sendBatch(array_slice($batch, $half));
        }

        $this->halted = true;
        $this->logger->error("ipase: the server answered {$status} for {$this->keyLabel}; sending stops until the next launch: ".$this->describe($response));

        return $batch;
    }

    /** Exponential backoff with jitter: attempt n waits min(300, 2^(n-1)) s times 0.5 to 1.5. */
    private function backoff(Response $response): void
    {
        $attempt = $this->state->attempts() + 1;
        $this->state->setAttempts($attempt);
        $seconds = min(self::MAX_BACKOFF_SECONDS, 2 ** min($attempt - 1, 30)) * (0.5 + ($this->random)());
        $this->wait((int) round($seconds * 1000), $response);
    }

    private function wait(int $ms, Response $response): void
    {
        $this->state->blockUntil(Time::ms($this->clock) + $ms);
        $this->logger->info("ipase: retrying in {$ms} ms after ".($response->status === 0 ? 'a network failure' : "status {$response->status}").': '.$this->describe($response));
    }

    /** @return list<array{int, string}> */
    private function rejections(Response $response): array
    {
        $out = [];
        foreach ((array) ($response->body['rejected'] ?? []) as $rejection) {
            if (is_array($rejection)) {
                $index = $rejection['index'] ?? null;
                $reason = $rejection['reason'] ?? null;
                $out[] = [is_int($index) ? $index : -1, is_string($reason) ? $reason : 'unknown'];
            }
        }

        return $out;
    }

    private function describe(Response $response): string
    {
        if ($response->error !== null) {
            return $response->error;
        }
        $error = $response->body['error'] ?? null;
        $message = $response->body['message'] ?? null;

        return trim((is_string($error) ? $error : 'no error code').' '.(is_string($message) ? $message : ''));
    }
}
