<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Ipase\Transport\GuzzleTransport;
use Ipase\Version;

const SECRET_KEY = 'sk_live_0123456789abcdefSECRET';

/** @param  list<mixed>  $queue  responses or exceptions */
function transport(array $queue, array &$history, string $endpoint = 'https://in.example.test/'): GuzzleTransport
{
    $stack = HandlerStack::create(new MockHandler($queue));
    $stack->push(Middleware::history($history));

    return new GuzzleTransport(SECRET_KEY, $endpoint, new Client(['handler' => $stack]));
}

it('posts to /v1/batch with the protocol headers', function () {
    $history = [];
    $response = transport([new Psr7Response(202, [], '{"accepted":1,"rejected":[]}')], $history)->send('{"sent_at":"x","batch":[]}');

    $request = $history[0]['request'];
    expect($response->status)->toBe(202)
        ->and($response->body)->toBe(['accepted' => 1, 'rejected' => []])
        ->and($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://in.example.test/v1/batch')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer '.SECRET_KEY)
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($request->getHeaderLine('X-SDK'))->toBe('php/'.Version::SDK_VERSION)
        ->and($request->hasHeader('Content-Encoding'))->toBeFalse()
        ->and((string) $request->getBody())->toBe('{"sent_at":"x","batch":[]}')
        ->and($history[0]['options']['timeout'])->toBe(30.0)
        ->and($history[0]['options']['allow_redirects'])->toBeFalse();
});

it('gzips a body over 1 KB', function () {
    $history = [];
    $json = '{"sent_at":"x","batch":['.str_repeat(' ', 2000).']}';
    transport([new Psr7Response(202)], $history)->send($json);

    $request = $history[0]['request'];
    expect($request->getHeaderLine('Content-Encoding'))->toBe('gzip')
        ->and(gzdecode((string) $request->getBody()))->toBe($json);
});

it('does not follow redirects', function () {
    $history = [];
    $response = transport([new Psr7Response(301, ['Location' => 'https://elsewhere.test/']), new Psr7Response(202)], $history)->send('{}');

    expect($response->status)->toBe(301)
        ->and($history)->toHaveCount(1);
});

it('reads Retry-After in seconds', function (string $header, ?int $seconds) {
    $history = [];
    $response = transport([new Psr7Response(429, ['Retry-After' => $header])], $history)->send('{}');

    expect($response->retryAfter)->toBe($seconds);
})->with([['30', 30], [' 7 ', 7], ['0', 0], ['Wed, 21 Oct 2026 07:28:00 GMT', null], ['-1', null], ['', null]]);

it('turns a failed connection into status 0 without the key in the error', function () {
    $history = [];
    $response = transport([new ConnectException('cURL error 7: Failed to connect', new Request('POST', 'https://in.example.test/v1/batch'))], $history)->send('{}');

    expect($response->status)->toBe(0)
        ->and($response->error)->toContain('ConnectException')
        ->and($response->error)->not->toContain(SECRET_KEY);
});

it('keeps the key out of var_dump and stack traces', function () {
    $history = [];
    $dump = print_r(transport([], $history), true);

    expect((new ReflectionMethod(GuzzleTransport::class, '__construct'))->getParameters()[0]->getAttributes(SensitiveParameter::class))->toHaveCount(1)
        ->and((new ReflectionMethod(Ipase\Client::class, '__construct'))->getParameters()[0]->getAttributes(SensitiveParameter::class))->toHaveCount(1)
        ->and($dump)->not->toContain(SECRET_KEY);
});
