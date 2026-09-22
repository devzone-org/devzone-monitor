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
    | Web requests stop capturing at once; queue workers notice within a few
    | seconds, after the job in hand. Changing LOG_MONITOR_ENABLED itself
    | needs `php artisan queue:restart` (workers read config once).
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
    | totals of everything that happened inside it.
    |
    | url is the matched route template (https://app.test/password/reset/
    | {token}), never the real path, which can carry tokens; set it to "path"
    | for the real path with token-like segments replaced by {token}.
    |
    | Bodies and headers are off by default. When a condition below matches
    | they are parsed, masked (see "redact") and only then cut to max_bytes;
    | a body that cannot be parsed is not kept at all.
    |
    */
    'requests' => [
        'enabled' => (bool) env('LOG_MONITOR_REQUESTS', true),

        'url' => 'route',             // route | path

        // Paths never recorded ($request->is() patterns).
        'ignore_paths' => ['telescope*', 'horizon*', '_debugbar*', 'up'],

        // Headers and masked bodies of a request are kept when one of these
        // matches; masked query parameters are kept for every request.
        'bodies' => [
            // status >= this, e.g. 500; null (default) to disable
            'on_status' => env('LOG_MONITOR_REQUEST_BODIES_ON_STATUS'),
            // slower than this many ms; null to disable
            'slow_ms' => env('LOG_MONITOR_REQUEST_BODIES_SLOW_MS'),
            // route names or URI patterns always kept, e.g. webhooks:
            // LOG_MONITOR_REQUEST_BODIES_ROUTES="integrations/*,webhooks/*"
            'routes' => array_values(array_filter(array_map('trim', explode(',', (string) env('LOG_MONITOR_REQUEST_BODIES_ROUTES', ''))))),
            'max_bytes' => 8192,
            // When not empty, only values under these keys are kept (any
            // depth); every other value becomes "[not kept]".
            'only_fields' => [],
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
    | values are never recorded, and literals and comments in raw SQL are
    | removed before anything is cut. SQL that cannot be parsed safely is
    | replaced by a note. capture_sql => false keeps timings and statement
    | hashes but no SQL text at all.
    |
    | Memory: per request or job at most max_statements distinct statements
    | and max_statement_bytes of their text are tracked; beyond that queries
    | are only counted (dropped.statements on the record).
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
        'capture_sql' => true,
        'max_statements' => 1000,
        'max_statement_bytes' => 2 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Outgoing HTTP (Laravel's Http:: client, Laravel 8+)
    |--------------------------------------------------------------------------
    */
    'outgoing' => [
        'enabled' => true,
        // Masked request and response bodies: never (default), errors (status
        // >= 400 or the call failed) or always. "always" stores successful
        // bank and payment responses too; switch it on while debugging.
        'bodies' => env('LOG_MONITOR_OUTGOING_BODIES', 'never'),
        // Keep bodies only for these hosts ("api.bank.example", "*.bank.example");
        // empty = every host.
        'body_hosts' => [],
        // When not empty, only values under these keys are kept.
        'only_fields' => [],
        // Request and response headers of every call; secret headers
        // (Authorization, Cookie, X-Api-Key, ...) are masked.
        'headers' => (bool) env('LOG_MONITOR_OUTGOING_HEADERS', false),
        'max_bytes' => 8192,          // kept per body, cut after masking
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
        // Log message text (masked). false keeps level, time, trace and a
        // fingerprint of where the log call is, but no text.
        'messages' => true,
        // The context array passed with each log call, masked by key.
        'context' => true,
    ],

    'exceptions' => [
        'enabled' => true,
        'max_frames' => 50,
        // A few lines of your source around the line that threw, so the
        // monitor can show the statement itself. Read only when something
        // throws, so a healthy request never pays for it. This is the one
        // setting that sends code rather than data: only the application's
        // own PHP files are read (never vendor), lines are cut to 200
        // characters and masked like log messages, and 'messages' => false
        // above turns snippets off too (code quotes those messages).
        // Set LOG_MONITOR_SNIPPETS=false to keep code on the server.
        'snippets' => env('LOG_MONITOR_SNIPPETS', true),
        // Lines either side of the one that threw (1-10).
        'snippet_context' => 3,
        // Exception messages (masked). false keeps class, file, line and
        // frames only: for apps whose exceptions quote user input. Also
        // hides the log line Laravel writes with the same message.
        'messages' => true,
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
        // Batches waiting and quarantined batches together; quarantined
        // ones are dropped first, then the oldest.
        'max_total_bytes' => 200 * 1024 * 1024,
        'max_age_days' => 7,
        'min_free_disk' => 500 * 1024 * 1024,
        // Only the user PHP runs as can read the spool. When the web server
        // and the scheduler run as different users, put them in one group
        // and use 0660 / 0770 with that group.
        'file_mode' => env('LOG_MONITOR_SPOOL_FILE_MODE', '0600'),
        'dir_mode' => env('LOG_MONITOR_SPOOL_DIR_MODE', '0700'),
        'group' => env('LOG_MONITOR_SPOOL_GROUP'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Memory
    |--------------------------------------------------------------------------
    |
    | Records of a request or job are held in memory until it ends. Beyond
    | max_buffer_bytes a request keeps its totals but no more records
    | (dropped.memory on the record); a job writes what it has and goes on.
    |
    */
    'memory' => [
        'max_buffer_bytes' => 4 * 1024 * 1024,
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
    | One policy, applied before anything is written to the spool: log
    | messages and context, exception messages, headers, query parameters,
    | URLs, request and response bodies, outgoing call bodies and SQL text.
    |
    | Keys are matched by words: "cardPin", "card_pin" and "user[pin]" match
    | "pin"; "x-api-key" matches "api_key". The built-in lists
    | (DevZone\LogMonitor\Support\Redactor::DEFAULT_KEYS, DEFAULT_PATTERNS,
    | DEFAULT_SECRET_NAMES, DEFAULT_HEADERS) cover passwords, tokens, API
    | keys, secrets, OTPs, sessions, signatures, cookies, card data, PINs,
    | account numbers, CNICs, emails, JWTs and private keys. What you list
    | here is added to them; set replace_default_keys/patterns to use only
    | your own lists.
    |
    | Bodies are parsed whole (up to max_body_parse_bytes) before masking;
    | bigger or unparseable bodies, HTML and binary content are not kept.
    | Plain-text bodies are kept only when "text" is in body_formats.
    |
    */
    'redact' => [
        'replacement' => '[REDACTED]',
        'keys' => [],
        'replace_default_keys' => false,
        'patterns' => [],
        'replace_default_patterns' => false,
        // Header and query parameter names masked on top of the keys.
        'query_keys' => [],
        // Headers always masked.
        'headers' => [],
        // Regexes run on URL paths; matches become {token}.
        'path_patterns' => [],
        // Per-host rules for outgoing URLs ("api.x.com" or "*.x.com"):
        //   ['path' => 'omit']                      no path and no query kept
        //   ['path_patterns' => ['#(?<=/keys/)[^/]+#']]  matches become {token}
        // Built in: hooks.slack.com, hooks.zapier.com and Office 365 webhooks
        // are omitted. Add a rule for every API that puts a credential in
        // its URL path; generic token detection is best effort.
        'hosts' => [],
        'sql_bindings' => true,
        'trace_arguments' => true,
        'body_formats' => ['json', 'form', 'xml'],
        'max_body_parse_bytes' => 64 * 1024,
        'max_body_values' => 5000,
    ],

];
