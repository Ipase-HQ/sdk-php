<?php

declare(strict_types=1);

namespace Ipase\Clock;

/**
 * Blocks the process. Only Client::shutdown() sleeps, to sit out a retry wait before its
 * last attempt; tests inject one that advances their clock instead.
 */
interface Sleeper
{
    public function sleep(int $milliseconds): void;
}
