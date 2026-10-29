<?php

declare(strict_types=1);

// Conformance runner: every sdk-spec fixture that lists php, driven against the plain client on a
// virtual clock with real HTTP to the mock server, then judged by POST /__verify. Locally the
// fixtures come from the sibling sdk-spec checkout; CI copies them out of the mock server image
// (FIXTURES_DIR) so they always match the server's verdicts.

use Ipase\Tests\Conformance\Harness;
use Ipase\Tests\Conformance\MockServer;

/** @return array<string, array{array<string, mixed>}> */
function phpFixtures(): array
{
    $dir = getenv('FIXTURES_DIR') ?: dirname(__DIR__, 3).'/sdk-spec/fixtures';
    $out = [];
    foreach (glob(rtrim($dir, '/').'/*.json') ?: [] as $file) {
        if (basename($file) === 'scenario.schema.json') {
            continue;
        }
        $fixture = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if (in_array('php', $fixture['platforms'], true)) {
            $out[$fixture['id']] = [$fixture];
        }
    }
    ksort($out);

    return $out;
}

it('found fixtures that list php', function () {
    expect(phpFixtures())->not->toBeEmpty();
});

it('passes', function (array $fixture) {
    $harness = new Harness(MockServer::url(), $fixture);
    $harness->load();
    foreach ($fixture['steps'] as $i => $step) {
        try {
            $harness->run($step);
        } catch (Throwable $e) {
            throw new RuntimeException("step {$i} ({$step['action']}): {$e->getMessage()}", previous: $e);
        }
    }

    $verdict = $harness->verify();
    if (($verdict['pass'] ?? false) !== true) {
        fwrite(STDERR, "{$fixture['id']} failures:\n".json_encode($verdict['failures'] ?? $verdict, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
    expect($verdict['failures'] ?? null)->toBe([])
        ->and($verdict['pass'] ?? null)->toBeTrue();
})->with(phpFixtures());
