<?php

declare(strict_types=1);

namespace Ipase\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    /** @param  array<mixed>  $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message.($context === [] ? '' : ' '.json_encode($context))];
    }

    public function text(): string
    {
        return implode("\n", array_column($this->records, 'message'));
    }

    /** @return list<string> */
    public function at(string $level): array
    {
        return array_values(array_column(array_filter($this->records, fn (array $r): bool => $r['level'] === $level), 'message'));
    }
}
