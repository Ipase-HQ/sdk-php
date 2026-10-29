<?php

declare(strict_types=1);

namespace Ipase\Event;

use Ipase\Version;

/**
 * context for server-side events (protocol section 8): platform server, no bundle_id, os from
 * the PHP runtime, no network. Collected once and attached to every event unchanged.
 */
final class Context
{
    /** @return array<string, mixed> */
    public static function server(string $appName, string $appVersion, string $locale, string $timezone): array
    {
        return [
            'platform' => 'server',
            'app' => [
                'name' => self::fit($appName, 128),
                'version' => self::fit($appVersion, 32),
            ],
            'os' => [
                'name' => self::fit(PHP_OS_FAMILY, 32),
                'version' => self::fit(php_uname('r'), 32),
            ],
            'locale' => self::fit(self::bcp47($locale), 16),
            'timezone' => self::fit($timezone, 64),
            'sdk' => [
                'name' => Version::SDK_NAME,
                'version' => Version::SDK_VERSION,
                'spec' => Version::SPEC_VERSION,
            ],
        ];
    }

    /** en_GB.UTF-8 and en_GB@euro become en-GB. */
    public static function bcp47(string $locale): string
    {
        $locale = preg_replace('/[.@].*$/', '', $locale) ?? $locale;

        return str_replace('_', '-', $locale);
    }

    private static function fit(string $value, int $max): string
    {
        $value = trim($value);

        return $value === '' ? 'unknown' : mb_substr($value, 0, $max, 'UTF-8');
    }
}
