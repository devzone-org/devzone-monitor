# devzone/log-monitor

Reads a Laravel application's JSON log files on a schedule and ships new
entries to a DevZone monitoring server over HTTPS. The monitoring server and
dashboard are a separate project; this package is the client only.

- Laravel 7, 8, 9, 10 and 11
- PHP 7.3+ and 8.x
- Monolog 2 and 3
- Never throws into the host application and never runs inside a request
- Log files are read-only; the only file the package writes is its own state file

## How it works

1. A logging **tap** switches your existing `daily` channel to one JSON object
   per line and adds application context (`client`, `app`, `env`, `hostname`,
   `url`, `user_id`) to every record. The same file serves local debugging and
   monitoring.
2. The `log-monitor:ship` command runs every minute from the scheduler. It
   remembers a byte offset per file, reads only what is new, parses each line,
   filters by `min_level`, redacts secrets, and POSTs batches of 100 to the
   monitoring server. Up to 1000 entries are shipped per run.
3. The offset is saved only after a batch has been shipped (or queued). A failed
   request means the same entries are retried on the next run.

## Installation

```bash
composer require devzone/log-monitor
php artisan log-monitor:install
```

The install command publishes `config/log-monitor.php`, prints the snippets
below and warns about two common problems: a channel using the `single` driver
(the file grows without bound and is not matched by the `laravel-*.log` glob)
and `LOG_LEVEL=debug` in production (volume).

### 1. Point your channel at the tap

In `config/logging.php`:

```php
'daily' => [
    'driver' => 'daily',
    'path' => storage_path('logs/laravel.log'),
    'days' => 14,
    'tap' => [\DevZone\LogMonitor\Logging\AddAppContext::class],
],
```

### 2. Environment

```env
LOG_CHANNEL=daily
LOG_STACK=daily
LOG_LEVEL=warning

LOG_MONITOR_ENABLED=true
LOG_MONITOR_ENDPOINT=https://monitor.example.com/api/ingest
LOG_MONITOR_API_KEY=your-per-application-key
LOG_MONITOR_CLIENT=acme
LOG_MONITOR_APP="${APP_NAME}"
LOG_MONITOR_MIN_LEVEL=warning
```

`LOG_LEVEL` controls what is written to disk. `LOG_MONITOR_MIN_LEVEL` controls
what is shipped; only that level and above leaves the server.

### 3. Scheduler

The package registers `log-monitor:ship` as `everyMinute()->withoutOverlapping()`.
Make sure the Laravel scheduler is running:

```
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Set `LOG_MONITOR_SCHEDULE=false` to register the schedule yourself.

### 4. Try it

```bash
php artisan log-monitor:ship --dry-run
```

Parses everything new and prints what would be shipped, without sending
anything or touching the state file.

## Configuration

`config/log-monitor.php`:

| Key | Default | Purpose |
| --- | --- | --- |
| `enabled` | `true` | `false` disables reading, shipping and the schedule instantly. |
| `endpoint` | env | Absolute **https** URL. Anything else is rejected. |
| `api_key` | env | Sent as `Authorization: Bearer`. One key per application. |
| `client`, `app` | env | Informational identity added to `extra`. |
| `paths` | `storage/logs/laravel-*.log` | Glob patterns. Must resolve inside `storage/logs`. |
| `max_file_age_hours` | `48` | Files older than this are ignored (no history flood on first install). |
| `min_level` | `warning` | Lowest level shipped. |
| `batch_size` | `100` | Entries per HTTP request. |
| `max_per_run` | `1000` | Hard ceiling per scheduler tick. |
| `max_chunk_bytes` | 2 MB | Bytes read per pass. |
| `message_max_length` | `4000` | Message cap, applied after redaction. |
| `timeout` | `10` | HTTP timeout in seconds. No in-request retries. |
| `state_path` | `storage/app/log-monitor/state.json` | Offsets file. |
| `min_free_disk_bytes` | 20 MB | State is not written below this floor. |
| `schedule` | `true` | Register the every-minute schedule. |
| `queue` | auto | Dispatch batches as jobs when the queue driver is not `sync`/`null`. |
| `redact` | see below | Keys, patterns and switches for client-side redaction. |

### Queue behaviour

When the application's queue connection is anything other than `sync` or
`null`, each batch is dispatched as a `ShipLogBatch` job and the offset advances
once the job is accepted by the queue. The job retries three times with backoff
and then lands in `failed_jobs`. Entries are redacted **before** dispatch, so the
serialised payload in Redis or the `jobs` table never contains secrets.

With no queue, batches are sent synchronously inside the command and the offset
advances only after a 2xx response.

## Example payload

`POST {endpoint}` with `Authorization: Bearer <api_key>`:

```json
{
  "app": "Acme Shop",
  "sent_at": "2026-09-16T10:16:00.012+05:00",
  "count": 1,
  "entries": [
    {
      "logged_at": "2026-09-16T10:15:30.123+05:00",
      "level": "error",
      "severity": 400,
      "message": "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry (SQL: insert into users (email, password) values [REDACTED])",
      "context": {
        "exception": {
          "class": "Illuminate\\Database\\QueryException",
          "message": "SQLSTATE[23000]: ... (SQL: insert into users (email, password) values [REDACTED])",
          "code": "23000",
          "file": "/var/www/releases/20260916/vendor/laravel/framework/src/Illuminate/Database/Connection.php:822",
          "trace": [
            "/var/www/releases/20260916/vendor/laravel/framework/src/Illuminate/Database/Connection.php:782",
            "/var/www/releases/20260916/app/Http/Controllers/RegisterController.php:41"
          ],
          "previous": {
            "class": "PDOException",
            "message": "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry",
            "code": 23000,
            "file": "/var/www/releases/20260916/vendor/laravel/framework/src/Illuminate/Database/Connection.php:492",
            "trace": [
              "/var/www/releases/20260916/vendor/laravel/framework/src/Illuminate/Database/Connection.php:492"
            ]
          }
        }
      },
      "extra": {
        "client": "acme",
        "app": "Acme Shop",
        "env": "production",
        "hostname": "web-01",
        "url": "https://shop.example.com/register",
        "user_id": null
      },
      "fingerprint": "250342a38defb5528f5bc25879216753"
    }
  ]
}
```

- `severity` is Monolog's numeric level; `>= 400` is alert-worthy.
- `fingerprint` is `md5(level|normalised message)` where digits become `N`,
  hex addresses become `HEX` and the message is cut at 200 characters, so
  repeat occurrences of the same bug group together. Deduplication and alerting
  happen server-side against this field.
- `logged_at` is ISO 8601 with millisecond precision and offset. Monolog 2
  installations that serialise `datetime` as `{"date": ..., "timezone": ...}`
  are handled transparently.

## State file

`storage/app/log-monitor/state.json`, keyed by **file name**, not full path,
so Envoyer-style release directories do not reset offsets on deploy:

```json
{
  "laravel-2026-09-16.log": {
    "offset": 48213,
    "size": 48213,
    "updated_at": "2026-09-16T10:16:00+05:00"
  }
}
```

- Written atomically: temp file in the same directory, then `rename()`.
- Entries whose file no longer exists are pruned.
- A file smaller than its stored offset (rotation or truncation) restarts at 0.
- Deliberately **not** the cache. `cache:clear` on deploy would otherwise
  re-ship a whole day, and the `array` driver stores nothing.
- Nothing is shipped when the state file cannot be written (permissions or free
  disk below `min_free_disk_bytes`).

## Security

### Redaction happens here, not on the server

Once a payload has been transmitted it has leaked, so everything is scrubbed
client-side before it is queued or sent. Redaction applies to the message, the
context, the extra block and exception traces, and consists of:

- **Keys** (default `password`, `password_confirmation`, `token`, `secret`,
  `authorization`, `cnic`, `card`, `cvv`, `pin`, `iban`, `account_no`).
  Matching is case-insensitive and token based: `card_pin`, `cardPin` and
  `api_token` all match. The same keys are matched as `key=value` /
  `key: value` pairs inside strings, including `Authorization: Bearer ...`.
- **Patterns** (default 13-digit CNIC, 16-digit card numbers, bcrypt hashes,
  email addresses). Any regular expression can be added.
- **SQL bindings.** `QueryException` messages embed the interpolated bindings
  in their `(SQL: ...)` tail. Everything after `VALUES`, `SET`, `WHERE` or
  `HAVING` is blanked while the statement shape is kept.
- **Trace arguments.** Monolog's JSON formatter writes exception traces as a
  list of `file:line` strings with no arguments, so nothing extra is needed
  there. If code logs `getTraceAsString()` output itself, which does include
  scalar function arguments, every argument list becomes `(...)`.

Review the defaults for your domain and extend `redact.keys` and
`redact.patterns` as needed. Redaction runs on the raw line, so it also covers
anything third-party code puts into context.

### Transport

- Endpoints must be absolute `https://` URLs; anything else is refused before
  a single byte is sent.
- TLS verification is never disabled and redirects are not followed, so the
  bearer token cannot be forwarded to another host.
- The API key travels in the `Authorization` header only, never in the URL.
  The transport refuses to run if the key appears in the endpoint string.
- Issue one key per application so it can be rotated independently. The key is
  masked in `var_dump()`/`dd()` output and stripped from any error text.

### Server-side note

The payload carries `extra.client` and top-level `app` for convenience. **The
monitoring server must derive client identity from the API key and ignore those
fields for authorisation.** Otherwise any valid token could write logs
attributed to another client.

### Other

- Readable paths are restricted to `storage/logs`. Patterns containing `..`,
  resolving outside the directory, or symlinks pointing elsewhere are rejected.
- `LOG_MONITOR_ENABLED=false` disables collection, shipping and scheduling
  instantly. The logging tap keeps producing JSON; that is a formatting choice
  of the host application.
- The package never reports its own problems through `Log::`. Doing so would
  write to the very file being read and loop forever. Problems go to
  `error_log()` (your PHP/CLI error log) and the command's console output.
- Request URLs are recorded without the query string.

## Local debugging

The log file is JSON, one object per line. For readable output:

```bash
tail -f storage/logs/laravel-$(date +%F).log | jq .
```

Only errors:

```bash
tail -f storage/logs/laravel-$(date +%F).log | jq -c 'select(.level >= 400) | {t: .datetime, m: .message}'
```

## Monolog compatibility

Monolog 2 processors receive arrays; Monolog 3 processors receive immutable
`LogRecord` objects. The two `ProcessorInterface` signatures are incompatible,
so the package ships two plain invokable classes and picks one at runtime:

```php
class_exists(\Monolog\LogRecord::class) ? Monolog3Processor::class : Monolog2Processor::class
```

`Monolog3Processor` uses PHP 8 named arguments and lives in its own file, which
is never loaded on PHP 7.x / Monolog 2 installations.

## Tests

```bash
composer install
vendor/bin/phpunit
```

Covers both Monolog datetime shapes, the redactor (keys, patterns, SQL
bindings, trace arguments), partial-line handling, offset reset on truncation
and atomic state writes.

## License

MIT. See [LICENSE](LICENSE).
