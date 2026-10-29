<?php

declare(strict_types=1);

namespace Ipase\Tests\Conformance;

use GuzzleHttp\Client;
use Ipase\Version;
use RuntimeException;
use Throwable;

/**
 * The mock ingest server for conformance. With MOCK_SERVER_URL set (CI, where the image runs as
 * a container) it only checks that server; otherwise it starts ../sdk-spec/mock-server on a free
 * port. Either way the server must report the spec version this SDK declares.
 */
final class MockServer
{
    private static ?string $url = null;

    /** @var resource|null */
    private static $process = null;

    public static function url(): string
    {
        if (self::$url !== null) {
            return self::$url;
        }

        $url = rtrim((string) getenv('MOCK_SERVER_URL'), '/');
        if ($url === '') {
            $url = self::start();
        }
        $health = self::health($url);
        if (($health['spec_version'] ?? null) !== Version::SPEC_VERSION) {
            throw new RuntimeException("mock server at {$url} speaks spec ".json_encode($health['spec_version'] ?? null).', this SDK declares '.Version::SPEC_VERSION);
        }

        return self::$url = $url;
    }

    private static function start(): string
    {
        $dir = dirname(__DIR__, 3).'/sdk-spec/mock-server';
        $cli = "{$dir}/dist/cli.js";
        if (! is_file($cli)) {
            throw new RuntimeException("build the mock server first (cd {$dir} && npm install && npm run build), or set MOCK_SERVER_URL");
        }
        $newest = fn (string $glob): int => max(array_map('filemtime', glob($glob) ?: [$cli]) ?: [0]);
        if ($newest("{$dir}/src/*.ts") > $newest("{$dir}/dist/*.js")) {
            throw new RuntimeException("{$dir}/dist is older than its src: run npm run build there");
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('no free port for the mock server');
        }
        $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':') ?: ':0', 1);
        fclose($socket);

        $node = getenv('NODE_BINARY') ?: 'node';
        $env = getenv() + ['PORT' => (string) $port, 'HOST' => '127.0.0.1'];
        $env['PORT'] = (string) $port;
        $env['HOST'] = '127.0.0.1';
        $process = proc_open([$node, $cli], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => STDERR], $pipes, $dir, $env);
        if (! is_resource($process)) {
            throw new RuntimeException("could not start {$node} {$cli}");
        }
        self::$process = $process;
        register_shutdown_function(static function (): void {
            if (is_resource(self::$process)) {
                proc_terminate(self::$process);
                proc_close(self::$process);
            }
        });

        return "http://127.0.0.1:{$port}";
    }

    /** @return array<string, mixed> */
    private static function health(string $url): array
    {
        $http = new Client(['timeout' => 2]);
        $last = null;
        for ($i = 0; $i < 80; $i++) {
            try {
                $body = json_decode((string) $http->get("{$url}/__health")->getBody(), true);
                if (is_array($body)) {
                    return $body;
                }
            } catch (Throwable $e) {
                $last = $e;
            }
            usleep(250_000);
        }
        throw new RuntimeException("mock server at {$url} is not healthy: ".($last?->getMessage() ?? 'no answer'));
    }
}
