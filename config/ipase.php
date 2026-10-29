<?php

declare(strict_types=1);

use Ipase\Version;

return [

    /*
    | The project's secret server key (sk_live_...). It authenticates every batch and is never
    | logged. Without it the facade accepts calls and sends nothing.
    */
    'key' => env('IPASE_SERVER_KEY'),

    // Ingest origin; /v1/batch is appended.
    'endpoint' => env('IPASE_ENDPOINT', Version::DEFAULT_ENDPOINT),

    // false turns every facade call into a no-op, for local development and tests.
    'enabled' => (bool) env('IPASE_ENABLED', true),

    // context.app.name and context.app.version on every event.
    'app_name' => env('IPASE_APP_NAME', env('APP_NAME', 'Laravel')),
    'app_version' => env('IPASE_APP_VERSION', '0.0.0'),

    /*
    | Events are buffered in the process and handed to the queue as one job when 100 are
    | buffered, when the oldest has waited 5 seconds, on Ipase::flush(), and when the request,
    | queued job or command ends. The job sends them with the protocol's retry rules.
    */
    'flush_at' => 100,
    'flush_interval' => 5,

    'queue' => [
        'connection' => env('IPASE_QUEUE_CONNECTION'),
        'name' => env('IPASE_QUEUE'),
    ],

    // Seconds before a request counts as a network failure.
    'timeout' => 30,

    /*
    | The cache store that keeps this backend's device id and the retry wait shared by every
    | queue worker. null is the default store; use one all workers share.
    */
    'cache_store' => env('IPASE_CACHE_STORE'),

    // Log channel for refusals and delivery problems. null is the default channel.
    'log_channel' => env('IPASE_LOG_CHANNEL'),

    /*
    | Push the middleware that reads X-Session-Id and X-Device-Id onto the global HTTP stack.
    | Set false to add Ipase\Laravel\ForwardIdentityHeaders (alias ipase.forward) yourself.
    */
    'middleware' => true,

];
