<?php

declare(strict_types=1);

namespace Ipase\Support;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * The plain client's default logger: warnings and worse go to error_log(), so local refusals
 * and halts are visible without configuration. Pass any PSR-3 logger to replace it.
 */
final class ErrorLogLogger extends AbstractLogger
{
    private const LOUD = [LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR, LogLevel::WARNING];

    /** @param  array<mixed>  $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (in_array($level, self::LOUD, true)) {
            error_log((string) $message);
        }
    }
}
