<?php

declare(strict_types=1);

namespace Ipase\Tests;

use Ipase\Client;
use Ipase\Identity\InMemoryDeviceIdStore;
use Ipase\Refusal;
use Ipase\Tests\Support\ClockSleeper;
use Ipase\Tests\Support\FakeTransport;
use Ipase\Tests\Support\MemoryLogger;
use Ipase\Tests\Support\TestClock;

/** A plain client on a test clock with a scripted transport. */
final class Rig
{
    public readonly TestClock $clock;

    public readonly ClockSleeper $sleeper;

    public readonly FakeTransport $transport;

    public readonly MemoryLogger $log;

    /** @var list<Refusal> */
    public array $refusals = [];

    public readonly Client $client;

    /** @param  array<string, mixed>  $options  extra Client constructor arguments */
    public function __construct(array $options = [], float $jitter = 0.5)
    {
        $this->clock = new TestClock;
        $this->sleeper = new ClockSleeper($this->clock);
        $this->transport = new FakeTransport;
        $this->log = new MemoryLogger;
        $this->client = new Client(...[
            'serverKey' => 'sk_live_0123456789abcdefSECRET',
            'appName' => 'Billing',
            'appVersion' => '2.4.0',
            'locale' => 'en_GB',
            'timezone' => 'Europe/London',
            'flushOnShutdown' => false,
            'deviceIds' => new InMemoryDeviceIdStore('6f1c2a7e-0d4b-4f3a-9a5e-2b7c8d9e0f11'),
            'logger' => $this->log,
            'logHook' => function (Refusal $r): void {
                $this->refusals[] = $r;
            },
            'clock' => $this->clock,
            'sleeper' => $this->sleeper,
            'transport' => $this->transport,
            'random' => fn (): float => $jitter,
            ...$options,
        ]);
    }
}
