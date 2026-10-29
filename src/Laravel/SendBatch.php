<?php

declare(strict_types=1);

namespace Ipase\Laravel;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Ipase\Delivery\Sender;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Sends buffered events to ingest. The queue is the persistence (protocol section 12): the
 * events stay in this job until the server takes them. After a retryable failure the events
 * that are left go back on the queue as a new job delayed until the wait ends; after a 401 or
 * an unexpected status the job fails, so the events wait in failed_jobs for queue:retry once
 * the key is fixed.
 */
final class SendBatch implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /** Retries follow the protocol, not the worker's attempt counter. */
    public int $tries = 1;

    /** @param  list<string>  $events  serialized events */
    public function __construct(public readonly array $events) {}

    public function handle(Sender $sender, Dispatcher $bus, Container $container): void
    {
        $kept = $sender->drain($this->events);
        if ($kept === []) {
            return;
        }
        if ($sender->halted()) {
            $this->fail(new RuntimeException('ipase: the server refused the key or answered an unexpected status; '.count($kept).' events kept for queue:retry'));

            return;
        }
        if ($this->job instanceof SyncJob) {
            // The sync driver cannot delay a job, so it cannot honour a retry wait.
            /** @var LoggerInterface $logger */
            $logger = $container->make('ipase.logger');
            $logger->warning('ipase: dropped '.count($kept).' events during a retry wait; the sync queue cannot delay them');

            return;
        }

        $bus->dispatch((new self($kept))
            ->onConnection($this->connection)
            ->onQueue($this->queue)
            ->delay((int) ceil($sender->waitMs() / 1000)));
    }
}
