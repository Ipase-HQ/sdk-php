<?php

declare(strict_types=1);

namespace Ipase\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Ipase\Laravel\Tracker;

/**
 * @method static bool track(string $name, array<string, string|int|float|bool|null> $properties = [], ?string $userId = null)
 * @method static bool identify(string $userId, array<string, string|int|float|bool|null> $traits = [])
 * @method static void flush()
 * @method static void onRefusal(\Closure(\Ipase\Refusal): void $hook)
 * @method static int pending()
 *
 * @see Tracker
 */
final class Ipase extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Tracker::class;
    }
}
