# devzone/log-monitor

Monitors a Laravel application and ships what happened to a DevZone
monitoring server: web requests, database queries, outgoing HTTP calls,
queued jobs, logs and exceptions. Everything is redacted on the server it
happens on, held in a small on-disk spool, and sent once a minute.

- Laravel 7, 8, 9, 10 and 11 (outgoing HTTP capture needs Laravel 8+)
- PHP 7.3+ and 8.x
- **Off by default.** Installing or deploying does nothing until
  `LOG_MONITOR_ENABLED=true`
- Never throws into the host application; the user never waits for the
  monitor (see [Performance](#performance))

> **Version 2 changes the wire format.** The monitoring server must accept
> the v2 batch described in [Server contract](#server-contract) before any
> client is upgraded. See [Upgrading from v1](#upgrading-from-v1).

## How it works

1. **During a request or job,** the package only copies facts into memory:
   route, timings, query counts, outgoing calls, log lines, exceptions.
2. **After the response has been sent** (Laravel's terminate phase, after
   `fastcgi_finish_request()`), it builds the records, redacts them, and
   appends them to `storage/app/log-monitor/spool/current.ndjson` in one
   locked write.
3. **Every minute,** `log-monitor:ship` renames `current.ndjson` to a batch
   file, gzips it, and POSTs it to the monitoring server. The batch is
   deleted only after the server accepts it. If the server is down, it is
   kept and retried; nothing is lost.

Each request or job gets a trace id. Every query, call, log line and
exception carries it, and a queued job carries the trace of the request (or
job) that queued it, so the dashboard can show one request with everything
that happened inside it and after it.

## Installation

```bash
composer require devzone/log-monitor
php artisan log-monitor:install
```

`log-monitor:install` publishes `config/log-monitor.php`, prints the `.env`
lines and checks the setup.

### Turn it on

```env
LOG_MONITOR_ENABLED=true
LOG_MONITOR_ENDPOINT=https://monitor.example.com/api/v2/ingest
LOG_MONITOR_API_KEY=dzm_xxxxxxxxxxxx.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
LOG_MONITOR_CLIENT=acme
LOG_MONITOR_APP="${APP_NAME}"
LOG_MONITOR_LOG_LEVEL=warning
```

On a server with cached config, run `php artisan config:cache` afterwards.

### Scheduler

The package schedules `log-monitor:ship` every minute
(`withoutOverlapping`). The Laravel scheduler must be running:

```
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

PHP-FPM (which writes the spool) and the scheduler (which renames and
deletes spool files) must run as the same user or share a group. On Forge
both are normally `forge`; `log-monitor:install` warns when they differ.

### Commands

| Command | What it does |
| --- | --- |
| `log-monitor:status` | Is it capturing, what is waiting in the spool, how did the last ship go |
| `log-monitor:ship` | Send the spool now (`--dry-run` counts without sending) |
| `log-monitor:off` | Emergency stop without touching `.env` or the config cache (`--purge` also empties the spool) |
| `log-monitor:on` | Undo `log-monitor:off` |
| `log-monitor:install` | Publish config, print setup, run checks |

## What is captured

| Record | When | Contents |
| --- | --- | --- |
| `request` | Every web request | Method, URL without query string, route pattern and name, action, status, duration, time before the app started, IP, user id, sizes, peak memory, totals of queries/calls/logs/exceptions. Headers and bodies only when a body condition matches |
| `queries` | Sampled executions, `all` mode, or any execution that errored | Each distinct SQL statement once, then every run as `[hash, ms]` |
| `slow-query` | A query over `slow_ms` | SQL, duration, the file and line in your code that ran it |
| `repeated-query` | The same statement run `repeated_threshold` times in one execution (N+1) | SQL, count, total time, file and line |
| `outgoing` | Every call made with Laravel's `Http::` client | Method, host, path, status, duration, sizes. Redacted bodies only when the call failed |
| `log` | `Log::` calls at or above `logs.level`, on any channel | Level, message, context, fingerprint |
| `exception` | Exceptions reported through Laravel (and fatal errors) | Class, message, file and line, frames without arguments, previous exceptions, fingerprint |
| `job` | Every queued job run | Class, queue, connection, attempt, status (`processed`, `failed`, `released`, `exception`, `interrupted`), duration, parent trace, totals |

Not captured yet: PHP `SoapClient` calls, Guzzle clients created directly,
raw `curl`, artisan commands and scheduled tasks.

### Queries

```php
'queries' => [
    'mode'              => 'sampled',   // summary | sampled | all
    'sample_rate'       => 0.10,
    'max_per_request'   => 2000,
    'capture_location'  => 'slow',      // slow | all
    'slow_ms'           => 100,
    'repeated_threshold'=> 10,
],
```

- `summary`: count and total time only, on the request/job record.
- `sampled`: every query for `sample_rate` of executions, **plus every
  execution that errored** (5xx, exception, error log, failed call or job).
- `all`: every query of every execution.

In every mode, slow and repeated queries get their own records. SQL keeps
its `?` placeholders; **binding values are never recorded**, and literal
values written directly into raw SQL are replaced with `?`. Finding the file
and line costs a stack walk, so by default it is done only for slow and
repeated queries.

### Queued jobs

Jobs are recorded like requests and linked to whatever queued them. Long
jobs write partial records every `flush_records` records or `flush_seconds`,
so a job killed by its timeout still leaves most of its data. The worker's
own bookkeeping on the database queue tables (reserving, deleting, failing
jobs) is not counted as the job's queries.

Any setting can be overridden for one job class:

```php
'jobs' => [
    'overrides' => [
        \App\Jobs\RunSanctions::class => [
            'queries.mode' => 'all',
            'queries.max_per_request' => 20000,
        ],
    ],
],
```

### Request bodies

Off for normal requests. Captured (redacted, cut to `max_bytes`) when:

- the status is at least `requests.bodies.on_status` (default 500), or
- the request took longer than `requests.bodies.slow_ms`, or
- the route name or path matches `requests.bodies.routes`.

File uploads, downloads and streamed responses are never captured.

## Spool

```
storage/app/log-monitor/
├── disabled                                  present while log-monitor:off is in effect
└── spool/
    ├── current.ndjson                        requests and jobs append here
    ├── batch-20260919-101500-7f3a.ndjson     rotated, waiting to be sent (UTC time, oldest first)
    ├── batch-20260919-101500-7f3a.ndjson.progress   lines already accepted, if a send was interrupted
    ├── failed/                               batches the server rejected repeatedly
    └── last-run.json                         summary of the last ship run
```

| Limit | Default | Effect |
| --- | --- | --- |
| `max_record_bytes` | 32 KB | Larger records have their bulky fields cut; query lists are split across lines |
| `rotate_bytes` | 5 MB | `current.ndjson` is rotated early instead of waiting for the minute |
| `max_total_bytes` | 200 MB | During a long outage the oldest batches are deleted first |
| `max_age_days` | 7 | Batches older than this are deleted |
| `min_free_disk` | 500 MB | Below this free space nothing is written; your app keeps priority |

Each write takes an exclusive lock and checks that the file it opened is
still `current.ndjson`, so lines from concurrent requests never interleave
and nothing can land in a batch that is already being sent.

### When the disk fills up

Tested on a real, deliberately filled volume with the monitoring server down:

| Situation | What happens |
| --- | --- |
| Server down, heavy traffic | The spool stops at `max_total_bytes`; the oldest batches are dropped first. Requests are unaffected |
| Free space below `min_free_disk` | Nothing more is written to the spool; one message goes to the PHP error log. Requests are unaffected |
| Space freed again | Writing resumes on the next request, no restart needed |
| Disk completely full (`min_free_disk` set to 0) | Writes fail; the partial write is cut back so no broken line is left. Requests are unaffected |
| Server returns while the disk is full | Shipping still works: batches are sent and deleted, which frees the space |

The package never makes a request fail because of disk space. Your
application's own log file is a different matter: when the disk is full,
Laravel's file logging throws and those requests fail with or without this
package. `min_free_disk` makes sure the monitor stops writing long before
that point, so it is never the thing that fills the disk.

## Server contract

`POST {LOG_MONITOR_ENDPOINT}`

```
Authorization: Bearer <api key>
Content-Type: application/json
Content-Encoding: gzip
```

```json
{
  "app": "Back Office",
  "env": "production",
  "host": "web-01",
  "client": "acme",
  "package": "2.0.0",
  "v": 2,
  "sent_at": "2026-09-19T10:16:00Z",
  "count": 7,
  "records": [
    {"t":"request","v":1,"trace":"8f2a1c0e9b7d4a31","at":"2026-09-19T10:15:02.114Z","ms":2410.3,"method":"POST","url":"https://app.example/transfers/7/verify","route":"/transfers/{id}/verify","route_name":"transfers.verify","status":200,"user":57,"queries":30,"query_ms":212.4,"queries_detail":false,"outgoing":5,"outgoing_ms":1880.2,"logs":1,"exceptions":0},
    {"t":"repeated-query","v":1,"trace":"8f2a1c0e9b7d4a31","hash":"3e5b0c7d9a1f","connection":"mysql","sql":"select * from `beneficiaries` where `transfer_id` = ?","count":18,"total_ms":22.1,"file":"app/Http/Controllers/TransferController.php","line":88},
    {"t":"outgoing","v":1,"trace":"8f2a1c0e9b7d4a31","method":"POST","host":"api.bank.example","path":"/title-fetch","status":500,"ms":410.5,"request_body":"<soap:Body><AccountNo>[REDACTED]</AccountNo></soap:Body>"},
    {"t":"log","v":1,"trace":"8f2a1c0e9b7d4a31","level":"warning","severity":300,"message":"Beneficiary name mismatch for [REDACTED]","context":{"transfer_id":"7"},"fingerprint":"6f1a4c0d5a2b9e8c7d3f2a1b0c9d8e7f"},
    {"t":"job","v":1,"trace":"4c1e77a02b9d6e15","parent":"8f2a1c0e9b7d4a31","class":"App\\Jobs\\RunSanctions","queue":"default","connection":"database","attempt":1,"status":"processed","ms":8420.0,"queries":640,"outgoing":12}
  ]
}
```

Records are shortened here; every record has `t` (type), `v` (record
version) and `trace`. Unknown fields must be ignored so records can grow.

Response codes the client understands:

| Server replies | Client does |
| --- | --- |
| 2xx | Batch accepted; deleted from the spool |
| 408, 425, 429, 5xx, timeout, connection error | Keeps everything, retries next minute |
| Any other 4xx | Retries; after `quarantine_after` attempts moves the batch to `spool/failed/` |

The server must:

- **Derive the client and application from the API key** and ignore
  `client`, `app` and `host` for authorisation. Otherwise any valid key could
  write data attributed to someone else.
- **Tolerate repeats.** If the client crashes between the server's 2xx and
  recording its progress, the same records are sent again. Deduplicate on the
  record contents (for example a hash of each record line).
- Reply quickly, for example by queueing the batch and processing it later.

## Security

- **Redaction happens on the client**, before anything is written to disk:
  log messages and context, exception messages, SQL text, request and
  response bodies and headers, outgoing call bodies. Keys (`password`,
  `token`, `cnic`, `card`, `pin`, `iban`, `account_no`, ...) are matched in
  arrays, `key=value` strings and XML/SOAP elements (`<ns:AccountNo>`);
  patterns match CNICs, card numbers, bcrypt hashes and emails. Extend both
  in `config/log-monitor.php`.
- **No binding values**, and literals in raw SQL are masked.
- **URLs are stored without query strings.** Bodies are kept only when a
  condition above matches.
- **Transport:** https only (plain http only with `LOG_MONITOR_ALLOW_HTTP`
  in a `local`, `development`, `dev` or `testing` environment), TLS
  verification always on, redirects never followed, the key only in the
  `Authorization` header and scrubbed from every error message.
- **Own errors go to `error_log()`**, never `Log::`, and each distinct
  message at most once per process.

## Performance

Measured in a test application for a request running 12 queries, one
`Http::` call and one warning log, median of 800 requests:

| | Laravel 11 / PHP 8.4 | Laravel 8 / PHP 7.4 |
| --- | --- | --- |
| Added while the user waits | about 60 µs | about 62 µs |
| Added after the response was sent | about 50 µs | about 49 µs |

About a third of the in-request cost is capturing the request itself; the
rest is mostly Laravel dispatching the query events to a listener, which any
monitoring listener pays. After the response, most of the cost is the
locked file append. For a real request of 50 to 500 ms this is well under
one percent.

Turn off what you do not need per type (`requests`, `queries`, `outgoing`,
`logs`, `exceptions`, `jobs`) or everything with `LOG_MONITOR_ENABLED`.

## Upgrading from v1

1. Make sure the monitoring server accepts the v2 batch (above).
2. Update the package and republish the config (v2 settings are different):

```bash
composer require devzone/log-monitor:^2.0
php artisan vendor:publish --tag=log-monitor-config --force
```

3. Remove the `AddAppContext` tap from `config/logging.php`. v2 does not read
   log files, so your log file can go back to Laravel's normal format and any
   level you like. The class still exists, so leaving it in does not break
   logging.
4. Point `LOG_MONITOR_ENDPOINT` at the v2 ingest URL, run
   `php artisan config:cache`, then `php artisan log-monitor:status`.
5. `storage/app/log-monitor/state.json` from v1 is no longer used and can be
   deleted.

## Local debugging

The spool is newline-delimited JSON:

```bash
tail -f storage/app/log-monitor/spool/current.ndjson | jq .
```

## Tests

```bash
composer install
vendor/bin/phpunit
```

## License

MIT. See [LICENSE](LICENSE).
