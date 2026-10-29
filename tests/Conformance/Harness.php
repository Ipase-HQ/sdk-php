<?php

declare(strict_types=1);

namespace Ipase\Tests\Conformance;

use GuzzleHttp\Client as Http;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use Ipase\Client;
use Ipase\Identity\InMemoryDeviceIdStore;
use Ipase\Refusal;
use Ipase\Tests\Support\ClockSleeper;
use Ipase\Tests\Support\TestClock;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Drives one fixture's steps against the plain client (sdk-spec/fixtures/README.md, "The
 * harness contract"). It never evaluates expect: POST /__verify does.
 */
final class Harness
{
    public const SERVER_KEY = 'sk_test_conformance';

    /** Fixture config keys that have a php equivalent, and the Client argument each sets. */
    private const CONFIG = ['flush_at' => 'flushAt', 'flush_interval_seconds' => 'flushInterval', 'max_queue' => 'maxQueue'];

    /** Steps php cannot perform: it has no sessions, screens, taps or app lifecycle (protocol 9.3, 14). */
    private const NOT_PHP = ['screen', 'tap', 'type_text', 'reset', 'background', 'foreground', 'kill_process'];

    /** @var list<array{reason: string, name: string}> */
    public array $warnings = [];

    private readonly TestClock $clock;

    private readonly Http $control;

    private ?Client $client = null;

    private bool $online = true;

    /** @param  array<string, mixed>  $fixture */
    public function __construct(private readonly string $server, private readonly array $fixture)
    {
        // A virtual clock that starts at the real time, so timestamps look like any other.
        $this->clock = new TestClock(gmdate('Y-m-d\TH:i:s.000\Z'));
        $this->control = new Http(['base_uri' => $server, 'timeout' => 10]);
    }

    /** @param  array<string, mixed>  $step */
    public function run(array $step): void
    {
        $action = (string) $step['action'];
        $repeat = (int) ($step['repeat'] ?? 1);

        match (true) {
            $action === 'launch' => $this->launch(),
            $action === 'network' => $this->online = $step['state'] === 'online',
            $action === 'wait_ms' => $this->wait((int) $step['value']),
            $action === 'track' => $this->repeat($repeat, fn () => $this->client()->track((string) $step['name'], $this->properties($step))),
            $action === 'identify' => $this->client()->identify((string) $step['user_id'], (array) ($step['traits'] ?? [])),
            $action === 'flush' => $this->client()->flush(),
            $action === 'assert_requests' => $this->assertRequests((int) $step['count']),
            in_array($action, self::NOT_PHP, true) => throw new RuntimeException("step {$action} has no php equivalent; the fixture should not list php"),
            default => throw new RuntimeException("unknown step action {$action}"),
        };
    }

    /** @return array<string, mixed> */
    public function verify(): array
    {
        return $this->post('/__verify', ['fixture' => $this->fixture['id'], 'local' => ['warnings' => $this->warnings]]);
    }

    /** @return array<string, mixed> */
    public function load(): array
    {
        return $this->post('/__scenario', $this->fixture);
    }

    private function launch(): void
    {
        $options = [];
        foreach ((array) ($this->fixture['config'] ?? []) as $key => $value) {
            $options[self::CONFIG[$key] ?? throw new RuntimeException("fixture config {$key} has no php equivalent")] = $value;
        }

        // The network step makes the transport fail without reaching the server.
        $stack = HandlerStack::create();
        $stack->push(fn (callable $next) => fn (RequestInterface $request, array $opts) => $this->online
            ? $next($request, $opts)
            : Create::rejectionFor(new ConnectException('offline (conformance network step)', $request)));

        $this->client = new Client(...[
            'serverKey' => self::SERVER_KEY,
            'endpoint' => $this->server,
            'appName' => 'Conformance',
            'appVersion' => '1.0.0',
            'locale' => 'en-GB',
            'timezone' => 'UTC',
            'flushOnShutdown' => false,
            // A fresh install: no persisted device id.
            'deviceIds' => new InMemoryDeviceIdStore,
            'logger' => new NullLogger,
            'logHook' => function (Refusal $refusal): void {
                $this->warnings[] = $refusal->toArray();
            },
            'clock' => $this->clock,
            'sleeper' => new ClockSleeper($this->clock),
            'httpClient' => new Http(['handler' => $stack]),
            ...$options,
        ]);
    }

    /** Advances the virtual clock and fires what fell due; PHP's only timer is tick(). */
    private function wait(int $ms): void
    {
        $this->clock->advance($ms);
        $this->client?->tick();
    }

    private function assertRequests(int $count): void
    {
        $recorded = json_decode((string) $this->control->get('/__recorded')->getBody(), true);
        $requests = is_array($recorded) ? (array) ($recorded['requests'] ?? []) : [];
        if (count($requests) !== $count) {
            $summary = array_map(fn ($r) => ($r['response']['status'] ?? '?').':'.count((array) ($r['events'] ?? [])).' events', $requests);
            throw new RuntimeException("assert_requests: expected {$count} requests, the mock server recorded ".count($requests).' ['.implode(', ', $summary).']');
        }
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function properties(array $step): array
    {
        if (isset($step['property_count'])) {
            $count = (int) $step['property_count'];

            return $count === 0 ? [] : array_combine(array_map(fn (int $i) => "k{$i}", range(0, $count - 1)), range(0, $count - 1));
        }

        return (array) ($step['properties'] ?? []);
    }

    private function repeat(int $times, callable $call): void
    {
        for ($i = 0; $i < $times; $i++) {
            $call();
        }
    }

    private function client(): Client
    {
        return $this->client ?? throw new RuntimeException('step needs a launched SDK');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        $response = $this->control->post($path, ['json' => $body]);
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
