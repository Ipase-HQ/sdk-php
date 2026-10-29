<?php

declare(strict_types=1);

namespace Ipase\Tests\Laravel;

use Ipase\Laravel\Facades\Ipase;
use Ipase\Laravel\IpaseServiceProvider;
use Ipase\Tests\Support\MemoryLogger;
use Ipase\Tests\Support\TestClock;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const KEY = 'sk_test_0123456789abcdefSECRET';

    protected TestClock $clock;

    protected MemoryLogger $log;

    protected function getPackageProviders($app): array
    {
        return [IpaseServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Ipase' => Ipase::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ipase.key', self::KEY);
        $app['config']->set('ipase.app_name', 'Billing');
        $app['config']->set('ipase.app_version', '2.4.0');
        $app['config']->set('app.locale', 'en_GB');
        $app['config']->set('app.timezone', 'Europe/London');
        $app['config']->set('cache.default', 'array');

        $this->clock = new TestClock;
        $this->log = new MemoryLogger;
        $app->instance('ipase.clock', $this->clock);
        $app->instance('ipase.logger', $this->log);
    }
}
