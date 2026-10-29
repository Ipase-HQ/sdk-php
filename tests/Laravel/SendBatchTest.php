<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Ipase\Delivery\Sender;
use Ipase\Laravel\CacheRetryState;
use Ipase\Laravel\Facades\Ipase;
use Ipase\Laravel\SendBatch;
use Ipase\Tests\Laravel\TestCase;
use Ipase\Tests\Support\FakeTransport;
use Ipase\Transport\Response;

beforeEach(function () {
    $this->transport = new FakeTransport;
    $this->app->instance(Sender::class, new Sender(
        $this->transport,
        new CacheRetryState(cache()->store()),
        $this->clock,
        $this->log,
        Sender::keyLabel(TestCase::KEY),
        fn (): float => 0.5,
    ));
});

/** @return list<string> */
function events(int $count): array
{
    for ($i = 0; $i < $count; $i++) {
        Ipase::track("e{$i}");
    }
    $events = [];
    Bus::fake();
    Ipase::flush();
    Bus::dispatched(SendBatch::class)->each(function (SendBatch $job) use (&$events) {
        array_push($events, ...$job->events);
    });

    return $events;
}

function runJob(SendBatch $job): SendBatch
{
    $job->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

it('sends the events and finishes', function () {
    $events = events(3);
    Bus::fake();
    runJob(new SendBatch($events))->assertNotFailed();

    expect($this->transport->requests)->toHaveCount(1)
        ->and($this->transport->names(0))->toBe(['e0', 'e1', 'e2']);
    Bus::assertNothingDispatched();
});

it('requeues the events for the end of a Retry-After wait, shared through the cache', function () {
    $events = events(2);
    $this->transport->then(new Response(429, retryAfter: 30));
    Bus::fake();

    runJob((new SendBatch($events))->onConnection('redis')->onQueue('analytics'))->assertNotFailed();

    Bus::assertDispatched(SendBatch::class, fn (SendBatch $job) => $job->events === $events && $job->delay === 30 && $job->connection === 'redis' && $job->queue === 'analytics');
    expect(cache()->get(CacheRetryState::BLOCKED_UNTIL))->toBe($this->clock->ms() + 30_000);

    // Another job during the wait sends nothing and waits for what is left of it.
    $this->clock->advance(20_000);
    Bus::fake();
    runJob(new SendBatch($events));
    Bus::assertDispatched(SendBatch::class, fn (SendBatch $job) => $job->delay === 10);
    expect($this->transport->requests)->toHaveCount(1);

    $this->clock->advance(10_000);
    runJob(new SendBatch($events));
    expect($this->transport->requests)->toHaveCount(2)
        ->and($this->transport->eventIds(1))->toBe($this->transport->eventIds(0));
});

it('fails the job after 401 so the events wait for queue:retry, without logging the key', function () {
    $events = events(2);
    $this->transport->then(new Response(401, body: ['error' => 'invalid_key', 'message' => 'unknown key']));
    Bus::fake();

    runJob(new SendBatch($events))->assertFailed();
    runJob(new SendBatch($events))->assertFailed();

    expect($this->transport->requests)->toHaveCount(1)
        ->and($this->log->text())->toContain('sk_test_…')
        ->and($this->log->text())->not->toContain(TestCase::KEY);
    Bus::assertNothingDispatched();
});

it('sends inline on the sync queue and drops what a retry wait would need to delay', function () {
    config(['queue.default' => 'sync']);
    $this->transport->then(new Response(503, retryAfter: 5));

    Ipase::track('a');
    Ipase::flush();

    expect($this->transport->requests)->toHaveCount(1)
        ->and($this->log->at('warning'))->toBe(['ipase: dropped 1 events during a retry wait; the sync queue cannot delay them']);
});

it('sends inline on the sync queue', function () {
    config(['queue.default' => 'sync']);
    Ipase::track('a', ['n' => 1], 'u_1');
    Ipase::flush();

    expect($this->transport->requests)->toHaveCount(1)
        ->and($this->transport->requests[0]['batch'][0])->toMatchArray(['name' => 'a', 'user_id' => 'u_1', 'properties' => ['n' => 1]]);
});
