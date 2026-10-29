<?php

declare(strict_types=1);

namespace Ipase\Clock;

use DateTimeZone;
use Psr\Clock\ClockInterface;

/** @internal */
final class Time
{
    public static function ms(ClockInterface $clock): int
    {
        return (int) $clock->now()->format('Uv');
    }

    /** RFC 3339 in UTC with millisecond precision, the format of timestamp and sent_at. */
    public static function rfc3339(ClockInterface $clock): string
    {
        return $clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
