<?php

declare(strict_types=1);

namespace Ipase\Tests\Support;

use Ipase\Transport\Response;
use Ipase\Transport\Transport;

/** Answers from a script, then 202; records every body it was given. */
final class FakeTransport implements Transport
{
    /** @var list<array{sent_at: string, batch: list<array<string, mixed>>}> */
    public array $requests = [];

    /** @param  list<Response>  $script */
    public function __construct(private array $script = []) {}

    public function then(Response ...$responses): self
    {
        array_push($this->script, ...$responses);

        return $this;
    }

    public function send(string $json): Response
    {
        $this->requests[] = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return array_shift($this->script) ?? new Response(202, body: ['accepted' => 0, 'rejected' => []]);
    }

    /** @return list<string> event names (or types) per request */
    public function names(int $request): array
    {
        return array_map(fn (array $e): string => $e['name'] ?? $e['type'], $this->requests[$request]['batch']);
    }

    /** @return list<string> */
    public function eventIds(int $request): array
    {
        return array_column($this->requests[$request]['batch'], 'event_id');
    }
}
