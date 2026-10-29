<?php

declare(strict_types=1);

use Ipase\Event\Context;
use Ipase\Event\EventFactory;
use Ipase\Identity\ForwardedIds;
use Ipase\Identity\InMemoryDeviceIdStore;
use Ipase\Refusal;
use Ipase\Support\Uuid;
use Ipase\Tests\Support\TestClock;
use Ipase\Version;

const DEVICE = '6f1c2a7e-0d4b-4f3a-9a5e-2b7c8d9e0f11';
const CLIENT_SESSION = '0190c1a2-7b3c-7d4e-8f5a-6b7c8d9e0f1a';
const CLIENT_DEVICE = '3d2c1b0a-9f8e-4d7c-b6a5-0f1e2d3c4b5a';

function factory(?ForwardedIds &$forwarded = null, ?TestClock $clock = null): EventFactory
{
    $forwarded = new ForwardedIds;

    return new EventFactory(
        Context::server('Billing', '2.4.0', 'en_GB.UTF-8', 'Europe/London'),
        $clock ?? new TestClock('2026-01-15T09:08:00.250Z'),
        new InMemoryDeviceIdStore(DEVICE),
        function () use (&$forwarded): ForwardedIds {
            return $forwarded;
        },
    );
}

it('builds a server-side track event (ADR 0003)', function () {
    $event = json_decode(factory()->track('invoice_paid', ['amount' => 42.0, 'currency' => 'EUR'], 'u_123'), true);

    expect($event)->toMatchArray([
        'type' => 'track',
        'name' => 'invoice_paid',
        'device_id' => DEVICE,
        'user_id' => 'u_123',
        'session_id' => Uuid::NIL,
        'seq' => 0,
        'timestamp' => '2026-01-15T09:08:00.250Z',
        'properties' => ['amount' => 42.0, 'currency' => 'EUR'],
    ])
        ->and($event['event_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7/')
        ->and(str_replace('-', '', substr($event['event_id'], 0, 13)))->toBe(sprintf('%012x', (new TestClock('2026-01-15T09:08:00.250Z'))->ms()));
});

it('describes the server application in context (protocol section 8)', function () {
    $context = json_decode(factory()->track('x'), true)['context'];

    expect($context)->toBe([
        'platform' => 'server',
        'app' => ['name' => 'Billing', 'version' => '2.4.0'],
        'os' => ['name' => PHP_OS_FAMILY, 'version' => mb_substr(php_uname('r'), 0, 32)],
        'locale' => 'en-GB',
        'timezone' => 'Europe/London',
        'sdk' => ['name' => 'php', 'version' => Version::SDK_VERSION, 'spec' => '1.0.0-draft.4'],
    ])->and($context)->not->toHaveKey('network');
});

it('omits user_id, properties and traits when there are none', function () {
    $track = json_decode(factory()->track('x'), true);
    $identify = json_decode(factory()->identify('u_1'), true);

    expect($track)->not->toHaveKeys(['user_id', 'properties', 'traits'])
        ->and($identify)->toMatchArray(['type' => 'identify', 'user_id' => 'u_1'])
        ->and($identify)->not->toHaveKeys(['name', 'properties', 'traits']);
});

it('sends properties as a JSON object even with list-like keys', function () {
    $json = factory()->track('x', [0 => 'a', 1 => 'b']);

    expect($json)->toContain('"properties":{"0":"a","1":"b"}');
});

it('carries traits on identify', function () {
    $event = json_decode(factory()->identify('u_1', ['plan' => 'pro', 'seats' => 5]), true);

    expect($event['traits'])->toBe(['plan' => 'pro', 'seats' => 5]);
});

it('forwards valid client ids and ignores invalid ones', function () {
    $events = factory($forwarded);

    $forwarded = new ForwardedIds(CLIENT_SESSION, CLIENT_DEVICE);
    $both = json_decode($events->track('x'), true);
    $forwarded = new ForwardedIds(strtoupper(CLIENT_SESSION), 'not-a-uuid');
    $neither = json_decode($events->track('x'), true);
    $forwarded = new ForwardedIds(CLIENT_SESSION);
    $sessionOnly = json_decode($events->track('x'), true);

    expect([$both['session_id'], $both['device_id'], $both['seq']])->toBe([CLIENT_SESSION, CLIENT_DEVICE, 0])
        ->and([$neither['session_id'], $neither['device_id']])->toBe([Uuid::NIL, DEVICE])
        ->and([$sessionOnly['session_id'], $sessionOnly['device_id']])->toBe([CLIENT_SESSION, DEVICE]);
});

it('refuses invalid input with the reason and event name', function (Closure $call, string $reason, string $name) {
    $refusal = $call(factory());

    expect($refusal)->toBeInstanceOf(Refusal::class)
        ->and($refusal->toArray())->toBe(['reason' => $reason, 'name' => $name]);
})->with([
    'reserved name' => [fn (EventFactory $f) => $f->track('$checkout'), Refusal::RESERVED_NAME, '$checkout'],
    'empty name' => [fn (EventFactory $f) => $f->track(''), Refusal::INVALID_NAME, ''],
    'bad user id on track' => [fn (EventFactory $f) => $f->track('x', [], ''), Refusal::INVALID_USER_ID, 'x'],
    'reserved key' => [fn (EventFactory $f) => $f->track('upgrade', ['$plan' => 'pro']), Refusal::RESERVED_PROPERTY_KEY, 'upgrade'],
    'bad user id on identify' => [fn (EventFactory $f) => $f->identify(str_repeat('u', 129)), Refusal::INVALID_USER_ID, 'identify'],
    'reserved trait key' => [fn (EventFactory $f) => $f->identify('u_1', ['$email' => 'a@b.c']), Refusal::RESERVED_PROPERTY_KEY, 'identify'],
]);

it('refuses an event over 32 KB serialized, context included', function () {
    $properties = array_fill_keys(array_map(fn ($i) => "k{$i}", range(0, 99)), str_repeat('x', 330));

    $refusal = factory()->track('big', $properties);

    expect($refusal)->toBeInstanceOf(Refusal::class)
        ->and($refusal->reason)->toBe(Refusal::EVENT_TOO_LARGE);
});

it('normalises locales to BCP 47', function (string $in, string $out) {
    expect(Context::bcp47($in))->toBe($out);
})->with([['en_GB.UTF-8', 'en-GB'], ['de_DE@euro', 'de-DE'], ['fr', 'fr'], ['pt-BR', 'pt-BR']]);
