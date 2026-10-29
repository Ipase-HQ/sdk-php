<?php

declare(strict_types=1);

namespace Ipase\Tests\Support;

use Ipase\Clock\Sleeper;

/** Sleeps by moving a TestClock, and remembers every sleep. */
final class ClockSleeper implements Sleeper
{
    /** @var list<int> */
    public array $sleeps = [];

    public function __construct(private readonly TestClock $clock) {}

    public function sleep(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
        $this->clock->advance($milliseconds);
    }
}
