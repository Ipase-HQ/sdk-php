<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Ipase\Laravel\CacheDeviceIdStore;
use Ipase\Laravel\Facades\Ipase;
use Ipase\Laravel\ForwardIdentityHeaders;
use Ipase\Laravel\SendBatch;
use Ipase\Laravel\Tracker;
use Ipase\Refusal;
use Ipase\Version;

/** @return list<array<string, mixed>> the events of every dispatched SendBatch, in order */
function dispatchedEvents(): array
{
    return Bus::dispatched(SendBatch::class)->flatMap(fn (SendBatch $job) => array_map(fn (string $e) => json_decode($e, true), $job->events))->all();
}

beforeEach(fn () => Bus::fake());

it('registers the facade, the config and the middleware', function () {
    expect(Ipase::getFacadeRoot())->toBeInstanceOf(Tracker::class)
        ->and(config('ipase.flush_at'))->toBe(100)
        ->and(app('router')->getMiddleware())->toHaveKey('ipase.forward')
        ->and(app(Kernel::class)->hasMiddleware(ForwardIdentityHeaders::class))->toBeTrue();
});

it('buffers events until flush() hands them to the queue as one job', function () {
    config(['ipase.queue.connection' => 'redis', 'ipase.queue.name' => 'analytics']);
    app()->forgetInstance(Tracker::class);
    Ipase::clearResolvedInstances();

    expect(Ipase::track('invoice_paid', ['amount' => 42], 'u_1'))->toBeTrue()
        ->and(Ipase::identify('u_1', ['plan' => 'pro']))->toBeTrue()
        ->and(Ipase::pending())->toBe(2);
    Bus::assertNothingDispatched();

    Ipase::flush();

    Bus::assertDispatchedTimes(SendBatch::class, 1);
    Bus::assertDispatched(SendBatch::class, fn (SendBatch $job) => $job->connection === 'redis' && $job->queue === 'analytics' && count($job->events) === 2);
    expect(array_column(dispatchedEvents(), 'type'))->toBe(['track', 'identify'])
        ->and(Ipase::pending())->toBe(0);
});

it('dispatches every 100 events', function () {
    for ($i = 0; $i < 250; $i++) {
        Ipase::track('e', ['i' => $i]);
    }

    expect(Bus::dispatched(SendBatch::class)->map(fn (SendBatch $j) => count($j->events))->all())->toBe([100, 100])
        ->and(Ipase::pending())->toBe(50);
});

it('dispatches once the oldest buffered event has waited 5 seconds', function () {
    Ipase::track('first');
    $this->clock->advance(4999);
    Ipase::track('second');
    Bus::assertNothingDispatched();

    $this->clock->advance(1);
    Ipase::track('third');

    expect(array_column(dispatchedEvents(), 'name'))->toBe(['first', 'second', 'third']);
});

it('flushes when the application terminates', function () {
    Ipase::track('during_request');
    app()->terminate();

    expect(array_column(dispatchedEvents(), 'name'))->toBe(['during_request']);
});

it('flushes when a queued job finishes', function () {
    Ipase::track('during_job');
    event(new JobProcessed('sync', new SyncJob(app(), '{}', 'sync', 'default')));

    expect(array_column(dispatchedEvents(), 'name'))->toBe(['during_job']);
});

it('builds server-side events with the application context', function () {
    Ipase::track('x', [], 'u_1');
    Ipase::flush();

    $event = dispatchedEvents()[0];
    expect($event)->toMatchArray(['seq' => 0, 'session_id' => '00000000-0000-0000-0000-000000000000', 'user_id' => 'u_1', 'timestamp' => '2026-01-15T09:08:00.000Z'])
        ->and($event['device_id'])->toBe(cache()->get(CacheDeviceIdStore::KEY))
        ->and($event['context'])->toMatchArray([
            'platform' => 'server',
            'app' => ['name' => 'Billing', 'version' => '2.4.0'],
            'locale' => 'en-GB',
            'timezone' => 'Europe/London',
        ])
        ->and($event['context']['sdk'])->toBe(['name' => 'php', 'version' => Version::SDK_VERSION, 'spec' => Version::SPEC_VERSION]);
});

it('keeps one device id for the backend across instances', function () {
    Ipase::track('a');
    Ipase::flush();
    app()->forgetInstance(Tracker::class);
    app()->forgetInstance(Ipase\Event\EventFactory::class);
    Ipase::clearResolvedInstances();
    Ipase::track('b');
    Ipase::flush();

    [$a, $b] = Bus::dispatched(SendBatch::class)->flatMap(fn ($j) => $j->events)->map(fn ($e) => json_decode($e, true)['device_id'])->all();
    expect($a)->toBe($b)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4/');
});

it('forwards X-Session-Id and X-Device-Id through the middleware', function () {
    Route::get('/orders', function () {
        Ipase::track('order_viewed');

        return 'ok';
    });

    $this->get('/orders', ['X-Session-Id' => '0190c1a2-7b3c-7d4e-8f5a-6b7c8d9e0f1a', 'X-Device-Id' => '3d2c1b0a-9f8e-4d7c-b6a5-0f1e2d3c4b5a'])->assertOk();
    $this->get('/orders', ['X-Session-Id' => '0190C1A2-7B3C-7D4E-8F5A-6B7C8D9E0F1A', 'X-Device-Id' => 'nope'])->assertOk();
    Ipase::flush();

    [$forwarded, $ignored] = dispatchedEvents();
    expect([$forwarded['session_id'], $forwarded['device_id']])->toBe(['0190c1a2-7b3c-7d4e-8f5a-6b7c8d9e0f1a', '3d2c1b0a-9f8e-4d7c-b6a5-0f1e2d3c4b5a'])
        ->and([$ignored['session_id'], $ignored['device_id']])->toBe(['00000000-0000-0000-0000-000000000000', cache()->get(CacheDeviceIdStore::KEY)]);
});

it('refuses invalid events with a logged warning and the refusal hook', function () {
    $seen = [];
    Ipase::onRefusal(function (Refusal $r) use (&$seen) {
        $seen[] = $r->toArray();
    });

    expect(Ipase::track('$checkout'))->toBeFalse()
        ->and(Ipase::identify('u_1', ['$email' => 'a@b.c']))->toBeFalse()
        ->and($seen)->toBe([['reason' => 'reserved_name', 'name' => '$checkout'], ['reason' => 'reserved_property_key', 'name' => 'identify']])
        ->and($this->log->at('warning'))->toBe(['ipase: refused event "$checkout": reserved_name', 'ipase: refused event "identify": reserved_property_key'])
        ->and(Ipase::pending())->toBe(0);
});

it('does nothing when disabled or without a key', function (string $key, mixed $value) {
    config(["ipase.{$key}" => $value]);
    app()->forgetInstance(Tracker::class);
    Ipase::clearResolvedInstances();

    expect(Ipase::track('x'))->toBeFalse();
    Ipase::flush();
    Bus::assertNothingDispatched();
})->with([['enabled', false], ['key', null], ['key', '']]);
