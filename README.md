# devzone/log-monitor

Monitors a Laravel application and ships what happened to a DevZone
monitoring server: web requests, database queries, outgoing HTTP calls,
queued jobs, logs and exceptions. Everything is masked on the server it
happens on, before it touches the disk, held in a small on-disk spool, and
sent once a minute.

- Laravel 7 to 13 (outgoing HTTP capture needs Laravel 8+)
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
   kept and retried, within the spool's size and age limits (see
   [When data is dropped](#when-data-is-dropped)).

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

Bodies and headers are **off by default**, for your own requests and for
outgoing calls. Masked query parameters are kept for every request. To keep
headers and bodies of your own app's requests:

```env
LOG_MONITOR_REQUEST_BODIES_ROUTES="integrations/*,webhooks/*"   # URI patterns or route names
LOG_MONITOR_REQUEST_BODIES_SLOW_MS=2000                         # also when slower than this
LOG_MONITOR_REQUEST_BODIES_ON_STATUS=500                        # and when status >= this
```

For outgoing HTTP calls:

```env
LOG_MONITOR_OUTGOING_BODIES=errors   # never (default) | errors | always
LOG_MONITOR_OUTGOING_HEADERS=true    # headers of every call; Authorization, Cookie, X-Api-Key etc. masked
```

To see the code that threw, and not only the file and line, turn on
snippets:

```env
LOG_MONITOR_SNIPPETS=true   # a few lines of your source around the throwing line
```

This is the only setting that sends code rather than data. Only your own
PHP files are read (never `vendor/`, never anything outside the project,
never a file over 2 MB), three lines either side by default
(`exceptions.snippet_context`, up to 10), each cut to 200 characters, and a
literal behind a secret-looking name (`$apiKey = '...'`) is masked. When the
throw itself is inside vendor code, the nearest line of your own code is
shown instead.

Narrow them further in `config/log-monitor.php`: `outgoing.body_hosts`
keeps bodies only for the hosts you list, and `only_fields` (for requests
and outgoing calls) keeps only the values under the keys you list.

`always` also stores successful responses, which for bank and payment APIs
means customer data; turn it on while debugging and back off after.

How a body is kept: it is parsed whole (JSON, form data, XML), masked by
key, and only then cut to 8 KB (`max_bytes`), ending with a note such as
`…[cut: kept 8 KB of 30 KB]`. A body larger than 64 KB
(`redact.max_body_parse_bytes`), one that does not parse, HTML, plain text
and binary content are not kept at all, only a note like
`[json body not kept, 1.2 MB: over the 64 KB parse limit]`. At most 64 KB of
an outgoing body is ever read, the stream is put back exactly where it was
(even if reading fails), and streamed responses (`'stream' => true`, sinks)
are never read. A call that would still exceed the 32 KB record limit keeps
its headers and has its bodies shortened, then dropped.

On a server with cached config, run `php artisan config:cache` afterwards.

### Scheduler

The package schedules `log-monitor:ship` every minute
(`withoutOverlapping`). The Laravel scheduler must be running:

```
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

PHP-FPM (which writes the spool) and the scheduler (which renames and
deletes spool files) must run as the same user. On Forge both are normally
`forge`; `log-monitor:install` warns when they differ. The spool is private
to that user (files `0600`, folder `0700`). If they must differ, put both
users in one group and set:

```env
LOG_MONITOR_SPOOL_FILE_MODE=0660
LOG_MONITOR_SPOOL_DIR_MODE=0770
LOG_MONITOR_SPOOL_GROUP=www-data
```

`log-monitor:ship` brings files written by older versions (which used
`0664`/`0775`) to these modes on its next run.

### Commands

`log-monitor:off` takes effect for new web requests at once. A running
queue worker notices within about 5 seconds: it stops collecting at once
(the job in hand is dropped from memory and writes nothing) and starts no
capture for the next job. No
`queue:restart` is needed for `off` and `on`, but it is needed after
changing `LOG_MONITOR_ENABLED` or any other setting, because workers read
the config once when they start.

| Command | What it does |
| --- | --- |
| `log-monitor:status` | Is it capturing, what is waiting in the spool, how did the last ship go |
| `log-monitor:ship` | Send the spool now (`--dry-run` counts without sending) |
| `log-monitor:off` | Emergency stop without touching `.env`, the config cache or the workers (`--purge` also deletes everything waiting, quarantined batches included) |
| `log-monitor:on` | Undo `log-monitor:off` |
| `log-monitor:install` | Publish config, print setup, run checks |

## What is captured

| Record | When | Contents |
| --- | --- | --- |
| `request` | Every web request | Method, URL as the route template (`/password/reset/{token}`, never the real path), route name, action, status, duration, time before the app started, IP, user id, sizes, peak memory, totals of queries/calls/logs/exceptions, masked query parameters. Headers and bodies only when a body condition matches |
| `queries` | Sampled executions, `all` mode, or any execution that errored | Each distinct SQL statement once, then every run as `[hash, ms]` |
| `slow-query` | A query over `slow_ms` | SQL, duration, the file and line in your code that ran it |
| `repeated-query` | The same statement run `repeated_threshold` times in one execution (N+1) | SQL, count, total time, file and line |
| `outgoing` | Every call made with Laravel's `Http::` client | Method, host, path (token-like segments replaced with `{token}`), masked query parameters, status, duration, sizes. Headers and masked bodies only when switched on |
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

Off by default. Captured (parsed, masked, then cut to `max_bytes`) when:

- the status is at least `requests.bodies.on_status` (not set by default), or
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
| `max_total_bytes` | 200 MB | Waiting and quarantined batches together. Quarantined ones are deleted first, then the oldest |
| `max_age_days` | 7 | Batches (waiting or quarantined) older than this are deleted |
| `file_mode` / `dir_mode` | `0600` / `0700` | Only the PHP user can read the spool |
| `min_free_disk` | 500 MB | Below this free space nothing is written; your app keeps priority |

Each write takes an exclusive lock and checks that the file it opened is
still `current.ndjson`, so lines from concurrent requests never interleave
and nothing can land in a batch that is already being sent. A request waits
at most 100 ms for that lock; if it cannot get it, its records are dropped
rather than holding up the request. The shipper waits at most a second for a
busy file and otherwise picks it up on the next run.

### When data is dropped

The package prefers losing monitoring data to slowing down or breaking your
application. Records are dropped, and never retried, when:

| Situation | What is lost | Where it shows |
| --- | --- | --- |
| Free disk below `min_free_disk` | Records written while below it | PHP error log |
| Spool over `max_total_bytes` or batches older than `max_age_days` (long outage) | Quarantined batches first, then the oldest batches | PHP error log, `log-monitor:status` |
| The spool lock stays busy for 100 ms | That request's or job chunk's records | PHP error log |
| Disk full or I/O error during a write | That write's records (the file is cut back, no broken line) | PHP error log |
| The server rejects a batch `quarantine_after` times with a 4xx | Moved to `spool/failed/`, never sent; later pruned | `log-monitor:status` |
| A cap per request or job is reached (`max_per_request`, `max_statements`, `memory.max_buffer_bytes`) | Extra records; totals are still counted | `dropped` on the request/job record |
| A record is larger than `max_record_bytes` | Bulky fields (bodies, headers, context) | `_cut` / `_truncated` on the record |
| `log-monitor:off` while a request or job runs | That execution's records | - |
| PHP is killed (`SIGKILL`, OOM killer) mid-request | That execution's records; a fatal error or `exit()` is still flushed | - |

### Memory

Everything a request or job captures is held in memory until it ends, within
these limits:

| Setting | Default | Bounds |
| --- | --- | --- |
| `memory.max_buffer_bytes` | 4 MB | Encoded records waiting to be written. A request then keeps only its totals; a job writes a partial chunk and carries on |
| `queries.max_statements` | 1000 | Distinct SQL statements tracked per execution |
| `queries.max_statement_bytes` | 2 MB | Their text. Statements over 128 KB are tracked by hash only |
| `queries.max_per_request` | 2000 | Queries listed individually (sampled/all mode) |
| `redact.max_body_parse_bytes` | 64 KB | Body read and parsed per request/call; also checked on input Laravel already parsed, before any masking |
| `redact.max_body_values` | 5000 | Values walked in one body; more and the body is not kept |
| Log context | 1000 values, depth 6 | Context passed with a log call |

A request therefore adds at most a few megabytes, whatever the application
does; a long-running job adds the same per flush.

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
  "package": "2.1.3",
  "v": 2,
  "sent_at": "2026-09-19T10:16:00Z",
  "count": 7,
  "records": [
    {"t":"request","v":1,"trace":"8f2a1c0e9b7d4a31","at":"2026-09-19T10:15:02.114Z","ms":2410.3,"method":"POST","url":"https://app.example/transfers/{id}/verify","route":"/transfers/{id}/verify","route_name":"transfers.verify","status":200,"user":57,"queries":30,"query_ms":212.4,"queries_detail":false,"outgoing":5,"outgoing_ms":1880.2,"logs":1,"exceptions":0},
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

- **Masking happens on the client**, before anything is written to disk,
  with one policy for log messages and context, exception messages, SQL
  text, headers, query parameters, URLs, and request, response and outgoing
  bodies. Built-in keys cover passwords, tokens, API keys and secrets, OTPs,
  sessions, signatures, cookies, card data, PINs, account numbers and CNICs;
  they are matched by words (`userPassword`, `user[password]`,
  `user%5Bpassword%5D`, `X-Api-Key`) in arrays, JSON, form data, XML/SOAP
  elements and attributes (the whole content of `<Credentials>` or a
  `CDATA` section), and `key=value` / `"key": "value"` pairs in free text.
  Patterns match CNICs, card numbers, bcrypt hashes, emails, JWTs, bearer
  tokens and private keys. What you add in `config/log-monitor.php` extends
  these lists.
- **Bodies are parsed, then masked, then cut.** A body that cannot be parsed
  is not kept. XML is parsed without network access, and XML with a DOCTYPE
  is refused.
- **URLs**: incoming requests are stored as the route template; other paths
  have token-like segments (16+ mixed letters and digits, hex strings, JWTs,
  `id:secret` pairs such as Telegram bot tokens) replaced with `{token}`;
  credentials in URLs and secret query parameters are masked, also inside
  headers such as `Referer` and `Location` and in free text. Webhook hosts
  that carry the credential in the path (Slack, Zapier, Office 365) keep no
  path at all, and `redact.hosts` adds rules for your own integrations.
- **Notes are never taken from content.** "Not kept" notes are produced by
  the package itself; a body that merely looks like one is sanitized like
  any other.
- **Allowlists apply to every body shape**: with `only_fields` set, a body
  that is a bare string, number or boolean is not kept.
- **SQL**: binding values are never recorded. Literals of every dialect
  (MySQL double quotes, SQLite double quotes, PostgreSQL `$$` and `$tag$`
  strings, `E''`, `N''`, `X''`) and comments (including nested PostgreSQL
  comments) are removed from the whole statement before it is cut; SQL that
  cannot be parsed safely is replaced by a note.
  `queries.capture_sql => false` records timings and hashes without any SQL
  text, and is applied to every record of every request or job separately,
  so a job override can never be undone by what ran before it.
- **Messages**: `exceptions.messages => false` hides exception messages
  everywhere, including the log line Laravel writes for the exception;
  `logs.messages => false` hides all log message text.
- **The spool** is readable only by the PHP user by default (see
  [Scheduler](#scheduler)).
- **Transport:** https only (plain http only with `LOG_MONITOR_ALLOW_HTTP`
  in a `local`, `development`, `dev` or `testing` environment), TLS
  verification always on, redirects never followed, the key only in the
  `Authorization` header and scrubbed from every error message.
- **Own errors go to `error_log()`**, never `Log::`, and each distinct
  message at most once per process.

### Known limitations

Masking reduces what can reach the monitoring server; it cannot guarantee
that nothing sensitive does. Check a pilot's records for your own data
before widening capture.

- Free text (log messages, exception messages, unparsed strings) is masked
  by rules. A secret with no recognisable key or pattern (for example
  `"Customer 4 said hunter2"`) cannot be found. Keep secrets out of log
  messages, or set `exceptions.messages => false` / `logs.context => false`.
- An unquoted value in free text ends at the first space: in
  `password: two words` only `two` is masked. Quoted values
  (`"password": "two words"`) are masked whole.
- Path segments are masked by shape and context: 16+ character tokens
  anywhere, `id:secret` pairs, and shorter ones after words such as
  `signed`, `reset`, `verify`, `invite` or `token`. A short token elsewhere
  in an outgoing path, or in an incoming path that matched no route, is
  kept. Add a `redact.hosts` rule (`'path' => 'omit'` or `path_patterns`)
  for every integration that puts a credential in its URL.
- In SQLite, `"name"` is masked as a possible string, so quoted identifiers
  show as `?`.
- A log message is fingerprinted by the call's location when messages are
  hidden, so different messages from one line group together.
- Masking by key can hide harmless values (`card_type`, `token_count`).
- Summary query mode still records the SQL text of slow and repeated
  queries; use `capture_sql => false` to avoid SQL text entirely.
- The server side (who may view which client's data, how long it is kept) is
  outside this package.

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

## Upgrading to 2.1

2.1 changes defaults to be safer. After `composer update`:

- **Bodies and outgoing headers are now off by default.** If you published
  the config before 2.1, your file still has the old values
  (`requests.bodies.on_status => 500`, `outgoing.bodies => 'errors'`,
  `outgoing.headers => true`). Set them to `null`, `'never'` and `false`, or
  republish the config, unless you want them on.
- **The spool is `0600`/`0700`.** If PHP-FPM and the scheduler run as
  different users, set `LOG_MONITOR_SPOOL_FILE_MODE=0660`,
  `LOG_MONITOR_SPOOL_DIR_MODE=0770` and `LOG_MONITOR_SPOOL_GROUP` before
  upgrading, or shipping will stop.
- **Your `redact.keys` and `redact.patterns` are now added to the built-in
  lists** instead of replacing them. Set `replace_default_keys` /
  `replace_default_patterns` to `true` for the old behaviour.
- Request URLs are the route template, and bodies that do not parse are no
  longer kept.
- Run `php artisan queue:restart` after deploying.

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
