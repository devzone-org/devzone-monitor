<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | false disables collection, shipping and the scheduled task instantly.
    | The AddAppContext logging tap is a formatting concern of the host and is
    | not affected by this switch.
    |
    */
    'enabled' => (bool) env('LOG_MONITOR_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Monitoring server
    |--------------------------------------------------------------------------
    |
    | The endpoint MUST be https. Plain http endpoints are rejected outright.
    | The API key is sent as "Authorization: Bearer <key>" and never appears in
    | the URL. Use one key per application so it can be rotated independently.
    |
    */
    'endpoint' => env('LOG_MONITOR_ENDPOINT'),
    'api_key' => env('LOG_MONITOR_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Identity
    |--------------------------------------------------------------------------
    |
    | Injected into every entry's "extra" block by the logging tap. The
    | monitoring server derives client identity from the API key and treats
    | these as informational only.
    |
    */
    'client' => env('LOG_MONITOR_CLIENT'),
    'app' => env('LOG_MONITOR_APP', env('APP_NAME', 'Laravel')),

    /*
    |--------------------------------------------------------------------------
    | Log files
    |--------------------------------------------------------------------------
    |
    | Glob patterns for the JSON log files to read. Every pattern must resolve
    | to a location inside storage/logs; anything else is rejected. Files not
    | modified within max_file_age_hours are ignored, which prevents a fresh
    | install from shipping two weeks of history.
    |
    */
    'paths' => [
        storage_path('logs/laravel-*.log'),
    ],
    'max_file_age_hours' => 48,

    /*
    |--------------------------------------------------------------------------
    | Shipping
    |--------------------------------------------------------------------------
    |
    | min_level:       lowest level shipped; the file may contain more (LOG_LEVEL).
    | batch_size:      entries per HTTP request.
    | max_per_run:     hard ceiling of entries shipped per scheduler tick.
    | max_chunk_bytes: bytes read from a file per pass.
    | timeout:         HTTP timeout in seconds; there are no in-request retries.
    |
    */
    'min_level' => env('LOG_MONITOR_MIN_LEVEL', 'warning'),
    'batch_size' => 100,
    'max_per_run' => 1000,
    'max_chunk_bytes' => 2 * 1024 * 1024,
    'message_max_length' => 4000,
    'timeout' => 10,

    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    |
    | Byte offsets per log file are stored here, keyed by file name so Envoyer
    | style release directories do not reset them. This is deliberately a file
    | and not the cache: cache:clear must never re-ship a whole day.
    |
    */
    'state_path' => storage_path('app/log-monitor/state.json'),
    'min_free_disk_bytes' => 20 * 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Scheduling and queueing
    |--------------------------------------------------------------------------
    |
    | schedule: register log-monitor:ship everyMinute()->withoutOverlapping().
    | queue:    batches are dispatched as jobs when the queue connection is not
    |           sync/null; otherwise they are sent synchronously inside the
    |           command. connection/name null means the application defaults.
    |
    */
    'schedule' => (bool) env('LOG_MONITOR_SCHEDULE', true),
    'queue' => [
        'enabled' => true,
        'connection' => null,
        'name' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    |
    | Applied client-side to message, context and extra (including exception
    | traces) before an entry is queued or shipped. Keys match array keys in
    | context/extra (case-insensitive, token based: "card_pin", "cardPin" and
    | "pin" all match "pin") and "key=value" / "key: value" pairs inside
    | strings. Patterns are regular expressions applied to every string.
    |
    | sql_bindings:    blank everything after VALUES/SET/WHERE in the
    |                  "(SQL: ...)" tail of QueryException messages, which
    |                  otherwise contains interpolated bindings.
    | trace_arguments: replace scalar arguments in getTraceAsString() output.
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
    ],

];
