<?php

declare(strict_types=1);

use Ipase\Refusal;
use Ipase\Tests\Rig;
use Ipase\Transport\Response;

/** Asserts that the wait in progress is exactly $ms: nothing at $ms - 1, a request at $ms. */
function expectWait(Rig $rig, int $ms): void
{
    $before = count($rig->transport->requests);
    $rig->clock->advance($ms - 1);
    $rig->client->tick();
    $rig->client->flush();
    expect($rig->transport->requests)->toHaveCount($before, "sent before the {$ms} ms wait ended");
    $rig->clock->advance(1);
    $rig->client->tick();
    expect($rig->transport->requests)->toHaveCount($before + 1, "nothing sent when the {$ms} ms wait ended");
}

function trackMany(Rig $rig, int $count, string $name = 'e'): void
{
    for ($i = 0; $i < $count; $i++) {
        $rig->client->track($name, ['i' => $i]);
    }
}

describe('batching', function () {
    it('sends when 100 events are queued', function () {
        $rig = new Rig;
        trackMany($rig, 99);
        expect($rig->transport->requests)->toBe([]);

        trackMany($rig, 1);

        expect($rig->transport->requests)->toHaveCount(1)
            ->and($rig->transport->requests[0]['batch'])->toHaveCount(100)
            ->and($rig->client->queued())->toBe(0);
    });

    it('sends when the oldest event has waited 5 seconds', function () {
        $rig = new Rig;
        $rig->client->track('first');
        $rig->clock->advance(4999);
        $rig->client->tick();
        expect($rig->transport->requests)->toBe([]);

        $rig->clock->advance(1);
        $rig->client->track('second');

        expect($rig->transport->names(0))->toBe(['first', 'second']);
    });

    it('lets a long-running process send on tick()', function () {
        $rig = new Rig;
        $rig->client->track('only');
        $rig->clock->advance(5000);
        $rig->client->tick();

        expect($rig->transport->requests)->toHaveCount(1);
    });

    it('drains the queue in batches of at most 500 on flush()', function () {
        $rig = new Rig(['flushAt' => 5000]);
        trackMany($rig, 1200);
        $rig->client->flush();

        expect(array_map(fn ($r) => count($r['batch']), $rig->transport->requests))->toBe([500, 500, 200]);
    });

    it('keeps every body under 1 MB uncompressed', function () {
        $rig = new Rig(['flushAt' => 5000]);
        $big = array_fill_keys(array_map(fn ($i) => "k{$i}", range(0, 29)), str_repeat('x', 1000));
        for ($i = 0; $i < 100; $i++) {
            $rig->client->track('big', $big);
        }
        $rig->client->flush();

        $sizes = array_map(fn ($r) => strlen(json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $rig->transport->requests);
        expect(count($rig->transport->requests))->toBeGreaterThan(2)
            ->and(max($sizes))->toBeLessThan(1024 * 1024)
            ->and(array_sum(array_map(fn ($r) => count($r['batch']), $rig->transport->requests)))->toBe(100);
    });

    it('reads sent_at from the clock when the body is built', function () {
        $rig = new Rig;
        $rig->client->track('x');
        $rig->clock->advance(1250);
        $rig->client->flush();

        expect($rig->transport->requests[0]['sent_at'])->toBe('2026-01-15T09:08:01.250Z')
            ->and($rig->transport->requests[0]['batch'][0]['timestamp'])->toBe('2026-01-15T09:08:00.000Z');
    });

    it('drops the oldest event beyond maxQueue', function () {
        $rig = new Rig(['flushAt' => 5000, 'maxQueue' => 3]);
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $rig->client->track($name);
        }
        $rig->client->flush();

        expect($rig->transport->names(0))->toBe(['c', 'd', 'e'])
            ->and($rig->log->at('warning'))->toHaveCount(2);
    });
});

describe('local validation', function () {
    it('refuses before queueing, logs a warning and calls the log hook', function () {
        $rig = new Rig;

        expect($rig->client->track('too_many', array_fill_keys(array_map(fn ($i) => "k{$i}", range(0, 100)), 1)))->toBeFalse()
            ->and($rig->client->track('$checkout'))->toBeFalse()
            ->and($rig->client->track('upgrade', ['$plan' => 'pro']))->toBeFalse()
            ->and($rig->client->identify(''))->toBeFalse()
            ->and($rig->client->track('fine'))->toBeTrue();

        expect(array_map(fn (Refusal $r) => $r->toArray(), $rig->refusals))->toBe([
            ['reason' => 'properties_too_many', 'name' => 'too_many'],
            ['reason' => 'reserved_name', 'name' => '$checkout'],
            ['reason' => 'reserved_property_key', 'name' => 'upgrade'],
            ['reason' => 'invalid_user_id', 'name' => 'identify'],
        ])
            ->and($rig->log->at('warning'))->toHaveCount(4)
            ->and($rig->client->queued())->toBe(1);
    });
});

describe('responses (protocol section 3)', function () {
    it('removes a 202 batch, rejected events included, and logs each rejection once', function () {
        $rig = new Rig;
        $rig->transport->then(new Response(202, body: ['accepted' => 1, 'rejected' => [['index' => 1, 'reason' => 'duplicate_event_id']]]));
        trackMany($rig, 2);
        $rig->client->flush();
        $rig->client->flush();

        expect($rig->transport->requests)->toHaveCount(1)
            ->and($rig->client->queued())->toBe(0)
            ->and($rig->log->at('warning'))->toBe(['ipase: the server rejected event 1 of a batch of 2: duplicate_event_id']);
    });

    it('drops a batch on 400 and logs once', function () {
        $rig = new Rig;
        $rig->transport->then(new Response(400, body: ['error' => 'schema_invalid', 'message' => 'bad envelope']));
        trackMany($rig, 2);
        $rig->client->flush();

        expect($rig->client->queued())->toBe(0)
            ->and($rig->log->at('warning'))->toBe(['ipase: dropped a batch of 2 events the server called malformed: schema_invalid bad envelope']);
    });

    it('keeps the queue and stops sending after 401, without logging the key', function () {
        $rig = new Rig;
        $rig->transport->then(new Response(401, body: ['error' => 'revoked_key', 'message' => 'revoked']));
        trackMany($rig, 2);
        $rig->client->flush();
        $rig->clock->advance(600_000);
        trackMany($rig, 1);
        $rig->client->tick();
        $rig->client->flush();

        expect($rig->transport->requests)->toHaveCount(1)
            ->and($rig->client->queued())->toBe(3)
            ->and($rig->log->at('error'))->toHaveCount(1)
            ->and($rig->log->at('error')[0])->toContain('sk_live_…')
            ->and($rig->log->text())->not->toContain('0123456789abcdefSECRET');
    });

    it('stops sending after any other unexpected status, 200 included', function (int $status) {
        $rig = new Rig;
        $rig->transport->then(new Response($status));
        trackMany($rig, 1);
        $rig->client->flush();
        $rig->client->flush();

        expect($rig->transport->requests)->toHaveCount(1)
            ->and($rig->client->queued())->toBe(1);
    })->with([200, 302, 404, 422]);

    it('halves a batch on 413 and sends the halves one after the other', function () {
        $rig = new Rig;
        $rig->transport->then(new Response(413));
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $rig->client->track($name);
        }
        $rig->client->flush();

        expect(array_map(fn ($i) => $rig->transport->names($i), range(0, 2)))->toBe([['a', 'b', 'c', 'd', 'e'], ['a', 'b', 'c'], ['d', 'e']])
            ->and($rig->client->queued())->toBe(0);
    });

    it('drops a single event that gets 413', function () {
        $rig = new Rig;
        $rig->transport->then(new Response(413), new Response(413));
        $rig->client->track('a');
        $rig->client->track('b');
        $rig->client->flush();

        expect($rig->transport->requests)->toHaveCount(3)
            ->and($rig->transport->names(2))->toBe(['b'])
            ->and($rig->client->queued())->toBe(0)
            ->and($rig->log->at('warning'))->toHaveCount(1);
    });

    it('keeps both halves queued when the first half meets a retry wait', function () {
        $rig = new Rig;
        $rig->transport->then(new Response(413), new Response(429, retryAfter: 10));
        foreach (['a', 'b', 'c', 'd'] as $name) {
            $rig->client->track($name);
        }
        $rig->client->flush();
        expectWait($rig, 10_000);

        expect($rig->transport->names(2))->toBe(['a', 'b', 'c', 'd']);
    });
});

describe('retry rules (protocol section 4)', function () {
    it('honours Retry-After even on explicit flush, then resends the same events', function () {
        $rig = new Rig;
        $rig->transport->then(new Response(429, retryAfter: 30));
        trackMany($rig, 3, 'button_tap');
        $rig->client->flush();
        $rig->clock->advance(15_000);
        $rig->client->flush();
        expect($rig->transport->requests)->toHaveCount(1);

        $rig->clock->advance(15_000);
        $rig->client->flush();

        expect($rig->transport->requests)->toHaveCount(2)
            ->and($rig->transport->eventIds(1))->toBe($rig->transport->eventIds(0))
            ->and($rig->client->queued())->toBe(0);
    });

    it('treats 503 like 429 and keeps queueing during the wait', function () {
        $rig = new Rig;
        $rig->transport->then(new Response(503, retryAfter: 2));
        $rig->client->track('a');
        $rig->client->flush();
        $rig->client->track('b');
        expectWait($rig, 2000);

        expect($rig->transport->names(1))->toBe(['a', 'b']);
    });

    it('waits min(300, 2^(n-1)) seconds times the jitter factor', function (float $jitter, array $waits) {
        $rig = new Rig(jitter: $jitter);
        $rig->transport->then(...array_fill(0, count($waits), new Response(500)));
        $rig->client->track('a');
        $rig->client->flush();
        foreach ($waits as $ms) {
            expectWait($rig, $ms);
        }
    })->with([
        'factor 1.0' => [0.5, [1000, 2000, 4000, 8000, 16_000, 32_000, 64_000, 128_000, 256_000, 300_000, 300_000]],
        'factor 0.5' => [0.0, [500, 1000, 2000]],
        'factor 1.25' => [0.75, [1250, 2500, 5000]],
    ]);

    it('backs off after a network failure', function () {
        $rig = new Rig;
        $rig->transport->then(Response::networkFailure('ConnectException: refused'), Response::networkFailure('timeout'));
        $rig->client->track('a');
        $rig->client->flush();
        expectWait($rig, 1000);
        expectWait($rig, 2000);

        expect($rig->client->queued())->toBe(0);
    });

    it('uses the computed wait for a 429 without Retry-After', function () {
        $rig = new Rig;
        $rig->transport->then(new Response(429), new Response(429));
        $rig->client->track('a');
        $rig->client->flush();
        expectWait($rig, 1000);
        expectWait($rig, 2000);
    });

    it('does not advance the counter for Retry-After', function () {
        $rig = new Rig;
        $rig->transport->then(new Response(500), new Response(429, retryAfter: 7), new Response(500));
        $rig->client->track('a');
        $rig->client->flush();
        expectWait($rig, 1000);
        expectWait($rig, 7000);
        expectWait($rig, 2000);
    });

    it('resets the counter on every response that is not a retry case', function () {
        $rig = new Rig;
        $rig->transport->then(new Response(500), new Response(500), new Response(202), new Response(500));
        $rig->client->track('a');
        $rig->client->flush();
        expectWait($rig, 1000);
        expectWait($rig, 2000);
        $rig->client->track('b');
        $rig->client->flush();
        expectWait($rig, 1000);
    });
});

describe('shutdown', function () {
    it('sits out a retry wait within shutdownTimeout and sends', function () {
        $rig = new Rig;
        $rig->transport->then(new Response(429, retryAfter: 3));
        $rig->client->track('a');
        $rig->client->shutdown();

        expect($rig->sleeper->sleeps)->toBe([3000])
            ->and($rig->transport->requests)->toHaveCount(2)
            ->and($rig->client->queued())->toBe(0);
    });

    it('gives up on a wait longer than shutdownTimeout and says how many events were lost', function () {
        $rig = new Rig(['shutdownTimeout' => 10.0]);
        $rig->transport->then(new Response(429, retryAfter: 60));
        trackMany($rig, 2);
        $rig->client->shutdown();

        expect($rig->sleeper->sleeps)->toBe([])
            ->and($rig->log->at('warning'))->toBe(['ipase: 2 events were not sent before shutdown']);
    });
});

describe('identity (ADR 0003)', function () {
    it('forwards the request headers', function () {
        $rig = new Rig;
        $rig->client->forwardHeaders(['x-session-id' => ['0190c1a2-7b3c-7d4e-8f5a-6b7c8d9e0f1a'], 'X-Device-Id' => '3d2c1b0a-9f8e-4d7c-b6a5-0f1e2d3c4b5a']);
        $rig->client->track('with');
        $rig->client->forward(null, null);
        $rig->client->track('without');
        $rig->client->flush();

        [$with, $without] = $rig->transport->requests[0]['batch'];
        expect([$with['session_id'], $with['device_id']])->toBe(['0190c1a2-7b3c-7d4e-8f5a-6b7c8d9e0f1a', '3d2c1b0a-9f8e-4d7c-b6a5-0f1e2d3c4b5a'])
            ->and([$without['session_id'], $without['device_id']])->toBe(['00000000-0000-0000-0000-000000000000', $rig->client->deviceId()]);
    });
});
