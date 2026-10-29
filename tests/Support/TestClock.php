<?php

declare(strict_types=1);

namespace Ipase\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/** A clock that moves only when told to. */
final class TestClock implements ClockInterface
{
    private int $ms;

    public function __construct(string $start = '2026-01-15T09:08:00.000Z')
    {
        $this->ms = (int) (new DateTimeImmutable($start))->format('Uv');
    }

    public function now(): DateTimeImmutable
    {
        $now = DateTimeImmutable::createFromFormat('U.v', sprintf('%d.%03d', intdiv($this->ms, 1000), $this->ms % 1000));
        assert($now !== false);

        return $now->setTimezone(new DateTimeZone('UTC'));
    }

    public function ms(): int
    {
        return $this->ms;
    }

    public function advance(int $ms): void
    {
        $this->ms += $ms;
    }
}
