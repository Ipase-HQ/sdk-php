<?php

declare(strict_types=1);

namespace Ipase\Delivery;

final class InMemoryRetryState implements RetryState
{
    private ?int $blockedUntil = null;

    private int $attempts = 0;

    public function blockedUntil(): ?int
    {
        return $this->blockedUntil;
    }

    public function blockUntil(int $unixMs): void
    {
        $this->blockedUntil = $unixMs;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): void
    {
        $this->attempts = $attempts;
    }
}
