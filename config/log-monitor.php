<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Off by default: installing or deploying the package does nothing until
    | LOG_MONITOR_ENABLED=true. While off, no listener, middleware or scheduled
    | task is registered, so the package costs nothing at all.
    |
    | For an emergency stop without touching .env or the config cache, run
    | `php artisan log-monitor:off`; `php artisan log-monitor:on` undoes it.
    |
    */
    'enabled' => (bool) env('LOG_MONITOR_ENABLED', false),

    'kill_switch_path' => storage_path('app/log-monitor/disabled'),

    /*
    |--------------------------------------------------------------------------
    | Monitoring server
    |--------------------------------------------------------------------------
    |
    | endpoint must be https. allow_http accepts plain http only when APP_ENV is
    | local, development, dev or testing; elsewhere it is ignored. The key is
    | sent as "Authorization: Bearer <key>" and never appears in a URL.
    |
    */
    'endpoint' => env('LOG_MONITOR_ENDPOINT'),
    'api_key' => env('LOG_MONITOR_API_KEY'),
    'allow_http' => (bool) env('LOG_MONITOR_ALLOW_HTTP', false),
    'timeout' => 10,

    // Informational labels sent with every batch. The server must derive the
    // client from the API key and never trust these for authorisation.
    'client' => env('LOG_MONITOR_CLIENT'),
    'app' => env('LOG_MONITOR_APP', env('APP_NAME', 'Laravel')),

    /*
    |--------------------------------------------------------------------------
    | Requests
    |--------------------------------------------------------------------------
    |
    | One record per web request: route, status, timings, sizes, user and the
    | totals of everything that happened inside it. Bodies are captured only
    | when a condition below matches, redacted and truncated.
    |
    */
    'requests' => [
        'enabled' => (bool) env('LOG_MONITOR_REQUESTS', true),

        // Paths never recorded ($request->is() patterns).
        'ignore_paths' => ['telescope*', 'horizon*', '_debugbar*', 'up'],

        // Headers and redacted bodies of a request are kept when one of these
        // matches; query parameters are kept for every request.
        'bodies' => [
            // status >= this; null to disable
            'on_status' => env('LOG_MONITOR_REQUEST_BODIES_ON_STATUS', 500),
            // slower than this many ms; null to disable
            'slow_ms' => env('LOG_MONITOR_REQUEST_BODIES_SLOW_MS'),
            // route names or URI patterns always kept, e.g. webhooks:
            // LOG_MONITOR_REQUEST_BODIES_ROUTES="integrations/*,webhooks/*"
            'routes' => array_values(array_filter(array_map('trim', explode(',', (string) env('LOG_MONITOR_REQUEST_BODIES_ROUTES', ''))))),
            'max_bytes' => 8192,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database queries
    |--------------------------------------------------------------------------
    |
    | mode:
    |   summary  count and total time on the request/job record only
    |   sampled  every query for sample_rate of executions, plus any execution
    |            that errored; the rest get the summary
    |   all      every query of every execution
    |
    | In every mode, slow queries and repeated queries (the same statement run
    | repeated_threshold times in one execution) get their own records with
    | the file and line that ran them. SQL keeps its ? placeholders; binding
    | values are never recorded and literals in raw SQL are masked.
    |
    */
    'queries' => [
        'enabled' => true,
        'mode' => 'sampled',          // summary | sampled | all
        'sample_rate' => 0.10,
        'max_per_request' => 2000,    // per request or job; extra queries are only counted
        'capture_location' => 'slow', // slow | all
        'slow_ms' => 100,
        'repeated_threshold' => 10,
        'max_slow_per_request' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Outgoing HTTP (Laravel's Http:: client, Laravel 8+)
    |--------------------------------------------------------------------------
    */
    'outgoing' => [
        'enabled' => true,
        // Redacted request and response bodies: errors (status >= 400 or the
        // call failed), always, or never. "always" stores successful bank
        // and payment responses too; switch it on while debugging.
        'bodies' => env('LOG_MONITOR_OUTGOING_BODIES', 'errors'),
        // Request and response headers of every call; the names listed in
        // redact.headers (Authorization, Cookie, ...) are masked.
        'headers' => (bool) env('LOG_MONITOR_OUTGOING_HEADERS', true),
        'max_bytes' => 8192,          // kept per body after redaction; 4x this is read at most
        'max_per_request' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logs and exceptions
    |--------------------------------------------------------------------------
    |
    | Captured from Laravel's log events, whatever channel you log to. This
    | level is independent of LOG_LEVEL: your log file can stay at warning
    | while the monitor receives debug.
    |
    */
    'logs' => [
        'enabled' => true,
        'level' => env('LOG_MONITOR_LOG_LEVEL', 'warning'),
        'max_per_request' => 200,
    ],

    'exceptions' => [
        'enabled' => true,
        'max_frames' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queued jobs
    |--------------------------------------------------------------------------
    |
    | Each job run is recorded like a request and linked to the request or job
    | that queued it. Long jobs write partial records every flush_records
    | records or flush_seconds, so a job killed by its timeout keeps most of
    | its data. overrides replace any setting above for one job class, e.g.
    |
    |   \App\Jobs\RunSanctions::class => [
    |       'queries.mode' => 'all',
    |       'queries.max_per_request' => 20000,
    |   ],
    |
    */
    'jobs' => [
        'enabled' => true,
        'flush_records' => 500,
        'flush_seconds' => 30,
        'overrides' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Spool
    |--------------------------------------------------------------------------
    |
    | Records are appended to current.ndjson after the response is sent. Every
    | minute, or earlier once it reaches rotate_bytes, it is renamed to a
    | batch file, shipped, and deleted once the server confirms.
    |
    */
    'spool' => [
        'path' => storage_path('app/log-monitor/spool'),
        'max_record_bytes' => 32 * 1024,
        'rotate_bytes' => 5 * 1024 * 1024,
        'max_total_bytes' => 200 * 1024 * 1024,
        'max_age_days' => 7,
        'min_free_disk' => 500 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Shipping
    |--------------------------------------------------------------------------
    |
    | log-monitor:ship runs every minute (withoutOverlapping). Batches are
    | gzipped and sent oldest first. A network error or 5xx stops the run and
    | everything is retried next minute; a batch the server keeps rejecting
    | with a 4xx is moved to spool/failed after quarantine_after attempts.
    |
    */
    'shipping' => [
        'schedule' => (bool) env('LOG_MONITOR_SCHEDULE', true),
        'records_per_request' => 1000,
        'max_bytes_per_request' => 2 * 1024 * 1024,
        'time_budget_seconds' => 50,
        'quarantine_after' => 5,
        'gzip' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    |
    | Applied before anything is written to the spool: log messages and
    | context, exception messages, request and response bodies, outgoing call
    | bodies and SQL text. Keys match array keys (token based: "cardPin" and
    | "card_pin" match "pin") and key=value pairs inside strings.
    |
    */
    'redact' => [
        'replacement' => '[REDACTED]',
        'keys' => [
            'password',
            'password_confirmation',
            'token',
            'secret',
            'authorization',
            'cookie',
            'cnic',
            'card',
            'cvv',
            'pin',
            'iban',
            'account_no',
        ],
        'patterns' => [
            'cnic' => '/\b\d{5}-?\d{7}-?\d\b/',
            'card' => '/\b(?:\d[ -]?){15}\d\b/',
            'bcrypt' => '/\$2[abxy]?\$\d{2}\$[.\/A-Za-z0-9]{53}/',
            'email' => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
        ],
        'sql_bindings' => true,
        'trace_arguments' => true,
        'headers' => ['authorization', 'cookie', 'set-cookie', 'x-xsrf-token', 'x-csrf-token', 'proxy-authorization'],
        // Header and query parameter names masked on top of the keys above
        // (matched by words: "x-api-key" matches "key"). Headers in the
        // "headers" list are always masked too.
        'query_keys' => ['key', 'apikey', 'access_token', 'signature', 'sig', 'otp', 'code', 'hash', 'auth', 'passwd', 'pwd', 'credential', 'credentials', 'session', 'private'],
    ],

];
