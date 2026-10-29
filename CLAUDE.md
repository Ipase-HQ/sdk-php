# sdk-php

PHP and Laravel server-side SDK of the User Monitoring Platform. Built in playbook Session 1.5. The contract is ../sdk-spec; the workspace CLAUDE.md one level up holds the settled decisions.

## Non-negotiables

- Composer package (PHP 8.2+) with a Laravel service provider, config file and facade; usable without Laravel through a plain client class.
- track() and identify() enqueue; a batch job sends every 5 s or 100 events through Guzzle with the retry rules from protocol.md.
- No sessions of its own: device id and session id come from the X-Device-Id and X-Session-Id request headers when present (a middleware reads them), otherwise events are server-only with user_id.
- context.sdk.name is php; context.os and app describe the server application.
- Secret server key (sk_live_...) from config, never logged.

## Commands

- composer install; composer test (Pest, Unit and Laravel suites); composer lint (Pint); composer analyse (PHPStan + Larastan, level max)
- composer conformance: every ../sdk-spec/fixtures file listing php, driven against the plain client on a test clock. Locally it starts ../sdk-spec/mock-server/dist/cli.js with node; CI runs ghcr.io/ipase-hq/sdk-spec/mock-server at composer.json extra.ipase.spec and reads the fixtures out of that image (MOCK_SERVER_URL, FIXTURES_DIR).
- composer check runs everything.

## Layout

- src/Event: EventFactory builds and validates events (protocol sections 11.3 and 15) and returns them serialized; Context; Validator.
- src/Delivery/Sender.php: batching (500 events, 1 MB) and every response and retry rule of protocol sections 3 and 4. Shared by both front ends.
- src/Client.php: the plain client, an in-memory queue that flushes at 100 events, after 5 s (checked on each call and on tick()), on flush() and on shutdown.
- src/Laravel: Tracker (facade root) buffers per unit of work and dispatches SendBatch jobs; SendBatch re-dispatches what is left after a retry wait and fails after 401; CacheRetryState shares the wait across workers; CacheDeviceIdStore; ForwardIdentityHeaders middleware.
- src/Version.php: SDK_VERSION, SPEC_VERSION (kept equal to composer.json extra.ipase.spec by a test) and DEFAULT_ENDPOINT.
- When the spec version changes: update composer.json extra.ipase.spec and Version::SPEC_VERSION together, then run conformance.

## Conformance harness notes

- The harness drives the plain client, which shares EventFactory and Sender with the Laravel path. wait_ms advances the test clock and calls tick(), PHP's only timer.
- Steps php cannot perform (screen, tap, type_text, reset, background, foreground, kill_process) and fixture config without a php equivalent throw, so a fixture that wrongly lists php fails loudly.
