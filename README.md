# ipase/ipase-php

The Ipase server-side SDK for PHP and Laravel. It sends `track` and `identify` events from your backend to the Ipase ingest service, in batches, with the retry rules of the wire protocol. PHP 8.2 or later; Laravel 11 or later for the Laravel integration, which is optional.

It implements sdk-spec 1.0.0-draft.4 (`../sdk-spec/protocol.md`). The version is declared in composer.json under `extra.ipase.spec` and reported in `context.sdk.spec`.

## Laravel

```bash
composer require ipase/ipase-php
```

The service provider and the `Ipase` facade are auto-discovered. Set the server key and your app version in `.env`:

```dotenv
IPASE_SERVER_KEY=sk_live_...
IPASE_ENDPOINT=https://in.example.com
IPASE_APP_VERSION=2.4.0
```

```php
use Ipase\Laravel\Facades\Ipase;

Ipase::track('invoice_paid', ['amount' => 42.5, 'currency' => 'EUR'], userId: (string) $user->id);
Ipase::identify((string) $user->id, ['plan' => 'pro']);
Ipase::flush(); // optional: hand buffered events to the queue now
```

- Events are validated, then buffered in the process. The buffer goes onto the queue as one `Ipase\Laravel\SendBatch` job when it holds 100 events, when its oldest event has waited 5 seconds, on `Ipase::flush()`, and when the request, queued job or artisan command ends. Run a queue worker; the sync driver works but cannot sit out a retry wait.
- The job sends with the protocol's retry rules. After a 429, a 503, a 5xx or a network failure the events that are left go back on the queue as a new job, delayed until the wait ends. The wait and the backoff counter live in the cache, so every worker honours the same Retry-After. After a 401 or an unexpected status the job fails and the events wait in `failed_jobs`; fix the key and run `php artisan queue:retry`.
- A middleware on the global HTTP stack reads `X-Session-Id` and `X-Device-Id` (ADR 0003). Client SDKs expose `sessionId()` and `deviceId()` for your frontend to send them. Values that are not lowercase UUIDs are ignored. Without them `session_id` is the nil UUID and `device_id` is one id this backend mints once and keeps in the cache.
- Publish the config with `php artisan vendor:publish --tag=ipase-config`. Options: `key`, `endpoint`, `enabled`, `app_name`, `app_version`, `flush_at`, `flush_interval`, `queue.connection`, `queue.name`, `timeout`, `cache_store`, `log_channel`, `middleware`. Set `middleware` to false to add `ipase.forward` to the routes of your choice instead.
- Without a key, or with `IPASE_ENABLED=false`, the facade accepts calls and sends nothing.

## Plain PHP

```php
$ipase = new Ipase\Client('sk_live_...', endpoint: 'https://in.example.com', appName: 'Billing', appVersion: '2.4.0');

$ipase->forwardHeaders(getallheaders()); // optional, per request
$ipase->track('invoice_paid', ['amount' => 42.5], userId: 'u_123');
$ipase->identify('u_123', ['plan' => 'pro']);
```

Events are queued in memory and sent when 100 are queued, when the oldest has waited 5 seconds (checked on every call; long-running processes can call `tick()`), on `flush()`, and when the process shuts down. The shutdown flush sits out a retry wait for up to `shutdownTimeout` seconds (10 by default) and logs how many events it could not send. The device id is kept in a file under the system temp directory; pass `deviceIds:` to keep it elsewhere.

| Argument | Default | Meaning |
| --- | --- | --- |
| endpoint | placeholder | Ingest origin; `/v1/batch` is appended |
| appName, appVersion | unknown | context.app |
| locale, timezone | intl default locale, PHP default zone | context.locale and context.timezone |
| flushAt | 100 | Queued events that trigger a send |
| flushInterval | 5 | Seconds the oldest queued event may wait |
| maxQueue | 10000 | Capacity; the oldest event is dropped beyond it |
| timeout | 30 | Seconds before a request counts as a network failure |
| flushOnShutdown, shutdownTimeout | true, 10 | The shutdown flush |
| deviceIds | temp file | A `DeviceIdStore` |
| logger | error_log | Any PSR-3 logger |
| logHook | | Called with every `Refusal` |
| clock, sleeper, random | | Test hooks: a PSR-20 clock, a `Sleeper`, the backoff jitter |
| httpClient, transport | | A configured Guzzle client, or a `Transport` instead of Guzzle |

The default endpoint is `https://in.ipase.invalid`, which never resolves, until the product domain is registered (`Version::DEFAULT_ENDPOINT`). Pass the endpoint until then.

## What goes on the wire

- Every event has `seq` 0, `context.platform` server, no `app.bundle_id` and no `network`. `context.os` is `PHP_OS_FAMILY` and `php_uname('r')`.
- `track()` and `identify()` refuse invalid input before queueing (protocol section 15) and return false. Each refusal is logged as a warning and passed to the log hook.
- The server key is sent only in the Authorization header. Logs name it by its prefix (`sk_live_…`), `var_dump()` shows only the prefix, and the constructor arguments are marked `#[SensitiveParameter]` so stack traces redact them.

## Commands

- `composer install`
- `composer test`: Pest unit and Laravel tests (Testbench), on a test clock
- `composer conformance`: every fixture in ../sdk-spec/fixtures that lists php, against the mock server, judged by `POST /__verify`. Locally it starts `../sdk-spec/mock-server/dist/cli.js` with node (run `npm run build` there if its src changed). Set `MOCK_SERVER_URL` and `FIXTURES_DIR` to use a running server instead, as CI does with `ghcr.io/ipase-hq/sdk-spec/mock-server:<spec>`
- `composer lint` (Pint), `composer analyse` (PHPStan with Larastan at level max), `composer check` runs everything
