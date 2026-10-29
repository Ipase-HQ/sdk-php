<?php

declare(strict_types=1);

namespace Ipase\Laravel;

use Illuminate\Contracts\Cache\Repository;
use Ipase\Delivery\RetryState;

/** The retry wait and backoff counter in the cache, shared by every queue worker. */
final class CacheRetryState implements RetryState
{
    public const BLOCKED_UNTIL = 'ipase:blocked_until';

    public const ATTEMPTS = 'ipase:attempts';

    public function __construct(private readonly Repository $cache) {}

    public function blockedUntil(): ?int
    {
        $value = $this->cache->get(self::BLOCKED_UNTIL);

        return is_numeric($value) ? (int) $value : null;
    }

    public function blockUntil(int $unixMs): void
    {
        $this->cache->forever(self::BLOCKED_UNTIL, $unixMs);
    }

    public function attempts(): int
    {
        $value = $this->cache->get(self::ATTEMPTS);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function setAttempts(int $attempts): void
    {
        $this->cache->forever(self::ATTEMPTS, $attempts);
    }
}
