<?php

declare(strict_types=1);

namespace Ipase\Laravel;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Ipase\Clock\SystemClock;
use Ipase\Delivery\Sender;
use Ipase\Event\Context;
use Ipase\Event\EventFactory;
use Ipase\Identity\ForwardedIds;
use Ipase\Transport\GuzzleTransport;
use Ipase\Version;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Registers the Ipase facade (Tracker), the queued sender and the header middleware. Container
 * keys ipase.clock, ipase.logger and ipase.cache can be rebound in tests.
 */
final class IpaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/ipase.php', 'ipase');

        $this->app->singleton('ipase.clock', fn (): ClockInterface => new SystemClock);
        $this->app->singleton('ipase.logger', function (Application $app): LoggerInterface {
            /** @var LogManager $log */
            $log = $app->make('log');

            return $log->channel($this->string('log_channel'));
        });
        $this->app->singleton('ipase.cache', function (Application $app): Cache {
            /** @var CacheFactory $cache */
            $cache = $app->make('cache');

            return $cache->store($this->string('cache_store'));
        });

        // The ids the current request forwarded; ForwardIdentityHeaders replaces the instance.
        $this->app->scoped(ForwardedIds::class, fn (): ForwardedIds => new ForwardedIds);

        $this->app->singleton(EventFactory::class, function (Application $app): EventFactory {
            /** @var Repository $config */
            $config = $app->make('config');

            return new EventFactory(
                Context::server(
                    $this->string('app_name') ?? (string) $config->string('app.name', 'Laravel'),
                    $this->string('app_version') ?? '0.0.0',
                    (string) $config->string('app.locale', 'en'),
                    (string) $config->string('app.timezone', date_default_timezone_get()),
                ),
                $this->clock($app),
                new CacheDeviceIdStore($this->cache($app)),
                // Read through the current container: under Octane that is the request's sandbox.
                static fn (): ForwardedIds => \Illuminate\Container\Container::getInstance()->make(ForwardedIds::class),
            );
        });

        $this->app->singleton(Sender::class, function (Application $app): Sender {
            $key = $this->string('key');
            if ($key === null) {
                throw new RuntimeException('ipase: set IPASE_SERVER_KEY (config ipase.key) to send events');
            }

            return new Sender(
                new GuzzleTransport($key, $this->string('endpoint') ?? Version::DEFAULT_ENDPOINT, null, $this->number('timeout', 30)),
                new CacheRetryState($this->cache($app)),
                $this->clock($app),
                $this->logger($app),
                Sender::keyLabel($key),
            );
        });

        $this->app->singleton(Tracker::class, function (Application $app): Tracker {
            $enabled = (bool) $this->config('enabled', true) && $this->string('key') !== null;

            return new Tracker(
                $enabled ? $app->make(EventFactory::class) : null,
                static fn (): Dispatcher => \Illuminate\Container\Container::getInstance()->make(Dispatcher::class),
                $this->clock($app),
                $this->logger($app),
                (int) $this->number('flush_at', 100),
                $this->number('flush_interval', 5),
                $this->string('queue.connection'),
                $this->string('queue.name'),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../../config/ipase.php' => $this->app->configPath('ipase.php')], 'ipase-config');

        $this->app->afterResolving('router', function (Router $router): void {
            $router->aliasMiddleware('ipase.forward', ForwardIdentityHeaders::class);
        });
        if ($this->config('middleware', true) && $this->app->bound(HttpKernelContract::class)) {
            /** @var HttpKernelContract $kernel */
            $kernel = $this->app->make(HttpKernelContract::class);
            if (method_exists($kernel, 'pushMiddleware')) {
                $kernel->pushMiddleware(ForwardIdentityHeaders::class);
            }
        }

        // Hand buffered events to the queue when a unit of work ends. The container passed in
        // is the one ending, which under Octane is the request's sandbox.
        $flush = static function (Container $app): void {
            if ($app->resolved(Tracker::class)) {
                $app->make(Tracker::class)->flush();
            }
        };
        $this->app->terminating(fn (Application $app) => $flush($app));
        /** @var Events $events */
        $events = $this->app->make('events');
        $events->listen([JobProcessed::class, JobExceptionOccurred::class], fn () => $flush(\Illuminate\Container\Container::getInstance()));
    }

    private function config(string $key, mixed $default = null): mixed
    {
        /** @var Repository $config */
        $config = $this->app->make('config');

        return $config->get("ipase.{$key}", $default);
    }

    private function string(string $key): ?string
    {
        $value = $this->config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function number(string $key, float $default): float
    {
        $value = $this->config($key);

        return is_numeric($value) ? (float) $value : $default;
    }

    private function clock(Application $app): ClockInterface
    {
        /** @var ClockInterface */
        return $app->make('ipase.clock');
    }

    private function cache(Application $app): Cache
    {
        /** @var Cache */
        return $app->make('ipase.cache');
    }

    private function logger(Application $app): LoggerInterface
    {
        /** @var LoggerInterface */
        return $app->make('ipase.logger');
    }
}
