<?php

declare(strict_types=1);

namespace Ipase\Delivery;

/**
 * The backoff counter and the wait in progress (protocol section 4). The plain client keeps
 * them in memory; under Laravel they live in the cache so every queue worker honours the same
 * Retry-After.
 */
interface RetryState
{
    /** Unix milliseconds before which nothing is sent, or null when no wait is in progress. */
    public function blockedUntil(): ?int;

    public function blockUntil(int $unixMs): void;

    /** Retry attempts since the last response that was not a retry case. */
    public function attempts(): int;

    public function setAttempts(int $attempts): void;
}
