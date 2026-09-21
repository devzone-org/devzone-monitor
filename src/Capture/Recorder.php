<?php

namespace DevZone\LogMonitor\Capture;

use DevZone\LogMonitor\Support\BodySanitizer;
use DevZone\LogMonitor\Support\Fingerprint;
use DevZone\LogMonitor\Support\Levels;
use DevZone\LogMonitor\Support\Redactor;
use DevZone\LogMonitor\Support\Report;
use DevZone\LogMonitor\Support\Text;

/**
 * The capture core. Laravel adapters (middleware, event listeners) call the
 * record* methods; nothing here touches the disk or the network until an
 * execution finishes, and then only through the RecordSink, in one write.
 *
 * Every public method is safe to call from inside the host application's
 * request: failures are reported through error_log() and swallowed.
 */
class Recorder
{
    const RECORD_VERSION = 1;
    const MESSAGE_MAX = 4000;
    const MAX_EXCEPTIONS = 50;
    const MAX_PREVIOUS = 3;
    const CONTEXT_MAX_DEPTH = 6;
    const CONTEXT_MAX_ITEMS = 100;
    const CONTEXT_MAX_VALUES = 1000;
    const SWITCH_CHECK_SECONDS = 5.0;
    const NORMALIZED_CACHE_BYTES = 2097152;
    const SQL_NOT_CAPTURED = '[sql not captured]';
    const MESSAGE_NOT_CAPTURED = '[message not captured]';

    /** @var array<string, mixed> */
    private $config;

    /** @var RecordSink */
    private $sink;

    /** @var Redactor */
    private $redactor;

    /** @var Location */
    private $location;

    /** @var callable(): float */
    private $clock;

    /** @var callable(): float Uniform in [0, 1). */
    private $random;

    /** @var array<int, Execution> Innermost last. */
    private $stack = [];

    /** @var bool */
    private $shutdownRegistered = false;

    /** @var array<string, array{0: string, 1: string, 2: string}> statement key => [hash, normalized sql, connection] */
    private $normalized = [];

    /** @var int Bytes of SQL held in $normalized. */
    private $normalizedBytes = 0;

    /** @var BodySanitizer */
    private $sanitizer;

    /** @var callable(): bool|null Whether log-monitor:off is in force. */
    private $switchedOff;

    /** @var float|null */
    private $switchCheckedAt = null;

    /** @var bool */
    private $off = false;

    /** @var int Values left for the log context being normalised. */
    private $contextBudget = 0;

    public function __construct(
        array $config,
        RecordSink $sink,
        Redactor $redactor,
        Location $location,
        ?callable $clock = null,
        ?callable $random = null,
        ?callable $switchedOff = null
    ) {
        $this->config = $config;
        $this->sink = $sink;
        $this->redactor = $redactor;
        $this->location = $location;
        $this->switchedOff = $switchedOff;
        $redact = isset($config['redact']) && is_array($config['redact']) ? $config['redact'] : [];
        $this->sanitizer = BodySanitizer::fromConfig($redactor, $redact);
        $this->clock = $clock ?: function () {
            return microtime(true);
        };
        $this->random = $random ?: function () {
            return mt_rand() / (mt_getrandmax() + 1);
        };
    }

    // ------------------------------------------------------------------
    // Executions
    // ------------------------------------------------------------------

    public function startRequest(): ?Execution
    {
        try {
            // A request starts a fresh stack: anything left from a previous
            // request in a long-running process is abandoned.
            $this->stack = [];
            if ($this->isSwitchedOff()) {
                return null;
            }
            $execution = $this->newExecution('request', null, $this->config);
            $this->stack[] = $execution;

            return $execution;
        } catch (\Throwable $e) {
            Report::error('could not start request capture', $e);

            return null;
        }
    }

    public function currentRequest(): ?Execution
    {
        foreach ($this->stack as $execution) {
            if ($execution->kind === 'request') {
                return $execution;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $info method, url, route, route_name, action, status, ip,
     *                                   user, request_bytes, response_bytes, bootstrap_ms,
     *                                   request_body, response_body, request_headers
     */
    public function finishRequest(Execution $execution, array $info): void
    {
        if ($execution->finished) {
            return;
        }
        try {
            $execution->finished = true;
            $this->remove($execution);

            $status = isset($info['status']) ? (int) $info['status'] : null;
            if ($status === null || $status >= 500) {
                $execution->errored = true;
            }

            $lines = [];
            if ($this->setting('requests.enabled', true)) {
                $record = [
                    't' => 'request',
                    'v' => self::RECORD_VERSION,
                    'trace' => $execution->trace,
                    'at' => $this->iso($execution->startedAt),
                    'ms' => $this->elapsedMs($execution),
                    'bootstrap_ms' => $info['bootstrap_ms'] ?? null,
                    'method' => $info['method'] ?? null,
                    'url' => isset($info['url']) ? $this->redactor->redactUrl($this->stripQuery((string) $info['url'])) : null,
                    'route' => $info['route'] ?? null,
                    'route_name' => $info['route_name'] ?? null,
                    'action' => $info['action'] ?? null,
                    'group' => isset($info['method'], $info['route']) ? Fingerprint::route((string) $info['method'], (string) $info['route']) : null,
                    'status' => $status,
                    'ip' => $info['ip'] ?? null,
                    'user' => $info['user'] ?? null,
                    'request_bytes' => $info['request_bytes'] ?? null,
                    'response_bytes' => $info['response_bytes'] ?? null,
                    'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                ] + $this->totals($execution);

                if (!empty($info['interrupted'])) {
                    $record['interrupted'] = true;
                }
                foreach (['query', 'request_headers', 'request_body', 'response_body', '_redacted', '_cut'] as $key) {
                    if (isset($info[$key])) {
                        $record[$key] = $info[$key];
                    }
                }
                $lines[] = $this->encode($record);
            }

            $lines = array_merge($lines, $this->detailLines($execution, true));
            $this->write($lines);
        } catch (\Throwable $e) {
            Report::error('could not finish request capture', $e);
        }
    }

    /**
     * @param array<string, mixed> $meta class, queue, connection, job_id, attempt
     */
    public function startJob(array $meta, ?string $parent): ?Execution
    {
        try {
            if (!$this->setting('jobs.enabled', true) || $this->isSwitchedOff()) {
                return null;
            }
            $class = isset($meta['class']) ? (string) $meta['class'] : '';
            $overrides = $this->setting('jobs.overrides.' . $class, []);
            $config = is_array($overrides) && $overrides !== [] ? self::applyOverrides($this->config, $overrides) : $this->config;

            if ($parent === null && ($current = $this->current()) !== null) {
                $parent = $current->trace; // sync queue: the job runs inside its parent
            }

            $execution = $this->newExecution('job', $parent, $config);
            $execution->meta = $meta;
            $this->stack[] = $execution;

            return $execution;
        } catch (\Throwable $e) {
            Report::error('could not start job capture', $e);

            return null;
        }
    }

    public function findJob(string $jobId): ?Execution
    {
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            $execution = $this->stack[$i];
            if ($execution->kind === 'job' && (string) ($execution->meta['job_id'] ?? '') === $jobId) {
                return $execution;
            }
        }

        return null;
    }

    public function finishJob(Execution $execution, string $status, ?\Throwable $exception = null): void
    {
        if ($execution->finished) {
            return;
        }
        try {
            if ($exception !== null) {
                $this->recordExceptionOn($execution, $exception);
            }
            $execution->finished = true;
            $this->remove($execution);
            if (in_array($status, ['failed', 'exception', 'interrupted'], true)) {
                $execution->errored = true;
            }

            $record = [
                't' => 'job',
                'v' => self::RECORD_VERSION,
                'trace' => $execution->trace,
                'parent' => $execution->parent,
                'at' => $this->iso($execution->startedAt),
                'ms' => $this->elapsedMs($execution),
                'class' => $execution->meta['class'] ?? null,
                'queue' => $execution->meta['queue'] ?? null,
                'connection' => $execution->meta['connection'] ?? null,
                'job_id' => $execution->meta['job_id'] ?? null,
                'attempt' => $execution->meta['attempt'] ?? null,
                'status' => $status,
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                'partial_flushes' => $execution->flushes,
            ] + $this->totals($execution);

            if ($exception !== null) {
                [$message, $redacted, $cut] = $this->exceptionMessage($exception, 1000);
                $record['exception'] = ['class' => get_class($exception), 'message' => $message];
                self::mark($record, 'exception.message', $redacted, $cut);
            }

            $lines = array_merge([$this->encode($record)], $this->detailLines($execution, true));
            $this->write($lines);
        } catch (\Throwable $e) {
            Report::error('could not finish job capture', $e);
        }
    }

    /**
     * A job that failed without running here (timed out in another attempt,
     * exceeded its tries before starting): one standalone job line.
     *
     * @param array<string, mixed> $meta
     */
    public function recordJobFailure(array $meta, ?string $parent, ?\Throwable $exception): void
    {
        try {
            if (!$this->setting('jobs.enabled', true)) {
                return;
            }
            $record = [
                't' => 'job',
                'v' => self::RECORD_VERSION,
                'trace' => self::newTrace(),
                'parent' => $parent,
                'at' => $this->iso($this->now()),
                'ms' => null,
                'class' => $meta['class'] ?? null,
                'queue' => $meta['queue'] ?? null,
                'connection' => $meta['connection'] ?? null,
                'job_id' => $meta['job_id'] ?? null,
                'attempt' => $meta['attempt'] ?? null,
                'status' => 'failed',
            ];
            if ($exception !== null) {
                [$message, $redacted, $cut] = $this->exceptionMessage($exception, 1000);
                $record['exception'] = ['class' => get_class($exception), 'message' => $message];
                self::mark($record, 'exception.message', $redacted, $cut);
            }
            $this->write([$this->encode($record)]);
        } catch (\Throwable $e) {
            Report::error('could not record job failure', $e);
        }
    }

    public function current(): ?Execution
    {
        $count = count($this->stack);

        return $count > 0 ? $this->stack[$count - 1] : null;
    }

    public function currentTrace(): ?string
    {
        $current = $this->current();

        return $current === null ? null : $current->trace;
    }

    /**
     * Registered with register_shutdown_function: writes whatever is still
     * open when PHP stops (fatal error, exit() inside a request, a worker
     * exiting mid-job), so those executions are not silently lost.
     */
    public function shutdown(): void
    {
        try {
            $fatal = self::fatalError();
            for ($i = count($this->stack) - 1; $i >= 0; $i--) {
                if (!isset($this->stack[$i])) {
                    continue; // switched off meanwhile: the stack was dropped
                }
                $execution = $this->stack[$i];
                if ($fatal !== null && $i === count($this->stack) - 1) {
                    $line = $this->encode($this->fatalRecord($execution, $fatal));
                    if ($line !== null) {
                        $execution->exceptions[] = $line;
                    }
                    $execution->exceptionCount++;
                }
                if ($execution->kind === 'request') {
                    $info = [];
                    if (is_callable($execution->describe)) {
                        try {
                            $info = (array) call_user_func($execution->describe);
                        } catch (\Throwable $e) {
                            $info = [];
                        }
                    }
                    $this->finishRequest($execution, ['status' => null, 'interrupted' => true] + $info);
                } else {
                    $this->finishJob($execution, 'interrupted');
                }
            }
        } catch (\Throwable $e) {
            Report::error('could not flush at shutdown', $e);
        }
    }

    // ------------------------------------------------------------------
    // Capture
    // ------------------------------------------------------------------

    public function recordQuery(string $sql, float $ms, string $connection, string $driver = ''): void
    {
        try {
            $execution = $this->current();
            if ($execution === null || !$execution->setting('queries.enabled', true)) {
                return;
            }

            $execution->queryCount++;
            $execution->queryMs += $ms;

            // Long statements are keyed by their hash so the key itself stays small.
            $key = $connection . "\0" . (strlen($sql) > 512 ? 'md5:' . md5($sql) : $sql);
            if (!isset($execution->statements[$key])) {
                // Memory caps: beyond them a new statement is only counted.
                $keep = strlen($sql) <= SqlNormalizer::MAX_INPUT ? $sql : null;
                $bytes = strlen((string) $keep) + strlen($key);
                if (count($execution->statements) >= (int) $execution->setting('queries.max_statements', 1000)
                    || $execution->statementBytes + $bytes > (int) $execution->setting('queries.max_statement_bytes', 2097152)) {
                    $execution->dropped['statements']++;

                    return;
                }
                $execution->statements[$key] = [$connection, $keep, 0, 0.0, null, $driver];
                $execution->statementBytes += $bytes;
            }
            $execution->statements[$key][2]++;
            $execution->statements[$key][3] += $ms;
            $count = $execution->statements[$key][2];

            $slowMs = (float) $execution->setting('queries.slow_ms', 100);
            $isSlow = $slowMs > 0 && $ms >= $slowMs;
            $threshold = (int) $execution->setting('queries.repeated_threshold', 10);
            $becameRepeated = $threshold > 0 && $count === $threshold;
            $locateAll = $execution->setting('queries.capture_location', 'slow') === 'all';
            $mode = (string) $execution->setting('queries.mode', 'sampled');

            $location = null;
            if ($locateAll || $isSlow || $becameRepeated) {
                $location = $this->location->find();
            }
            if ($becameRepeated) {
                $execution->statements[$key][4] = $location;
            }

            if ($isSlow) {
                if (count($execution->slowQueries) < (int) $execution->setting('queries.max_slow_per_request', 100)) {
                    $execution->slowQueries[] = ['key' => $key, 'ms' => $ms, 'at' => $this->now(), 'location' => $location];
                    $execution->pending++;
                } else {
                    $execution->dropped['slow_queries']++;
                }
            }

            if ($mode !== 'summary') {
                $kept = count($execution->queries) + (int) ($execution->meta['_queries_flushed'] ?? 0);
                if ($kept < (int) $execution->setting('queries.max_per_request', 2000)) {
                    $execution->queries[] = [$key, $ms, $locateAll ? $location : null];
                    if ($execution->keepQueries) {
                        $execution->pending++;
                    }
                } else {
                    $execution->dropped['queries']++;
                }
            }

            $this->maybeFlush($execution);
        } catch (\Throwable $e) {
            Report::error('could not record query', $e);
        }
    }

    /**
     * @param array<string, mixed> $call method, url, status (int|null), ms (float|null),
     *                                   request_bytes, response_bytes, error,
     *                                   request_body, response_body
     */
    public function recordOutgoing(array $call): void
    {
        try {
            $execution = $this->current();
            if ($execution === null || !$execution->setting('outgoing.enabled', true)) {
                return;
            }

            $ms = isset($call['ms']) ? (float) $call['ms'] : null;
            $execution->outgoingCount++;
            $execution->outgoingMs += (float) $ms;

            if (count($execution->outgoing) + (int) ($execution->meta['_outgoing_flushed'] ?? 0) >= (int) $execution->setting('outgoing.max_per_request', 500)) {
                $execution->dropped['outgoing']++;

                return;
            }

            $status = isset($call['status']) ? (int) $call['status'] : null;
            $failed = $status === null || $status >= 400 || !empty($call['error']);
            $parts = parse_url((string) ($call['url'] ?? '')) ?: [];
            $host = isset($parts['host']) ? (string) $parts['host'] : null;

            $record = [
                't' => 'outgoing',
                'v' => self::RECORD_VERSION,
                'trace' => $execution->trace,
                'at' => $this->iso($this->now() - ($ms !== null ? $ms / 1000 : 0)),
                'method' => isset($call['method']) ? strtoupper((string) $call['method']) : null,
                'scheme' => $parts['scheme'] ?? null,
                'host' => $parts['host'] ?? null,
                'port' => $parts['port'] ?? null,
                'path' => $this->redactor->redactPath((string) ($parts['path'] ?? '/')),
                'status' => $status,
                'ms' => $ms !== null ? round($ms, 2) : null,
                'request_bytes' => $call['request_bytes'] ?? null,
                'response_bytes' => $call['response_bytes'] ?? null,
            ];
            if (!empty($call['error'])) {
                [$record['error'], $redacted, $cut] = $this->cleanText((string) $call['error'], 1000);
                self::mark($record, 'error', $redacted, $cut);
            }
            if (isset($parts['query']) && $parts['query'] !== '') {
                parse_str((string) $parts['query'], $params);
                if ($params !== []) {
                    $record['query'] = $this->queryParams($params, $meta);
                    self::mark($record, 'query', $meta['redacted'], $meta['cut']);
                }
            }
            if ($execution->setting('outgoing.headers', false)) {
                foreach (['request_headers', 'response_headers'] as $key) {
                    if (isset($call[$key]) && is_array($call[$key]) && $call[$key] !== []) {
                        $record[$key] = $this->headers($call[$key], $meta);
                        self::mark($record, $key, $meta['redacted'], $meta['cut']);
                    }
                }
            }
            if ($this->keepsOutgoingBodies($failed, $host)) {
                $max = (int) $execution->setting('outgoing.max_bytes', 8192);
                $only = (array) $execution->setting('outgoing.only_fields', []);
                foreach (['request_body', 'response_body'] as $key) {
                    if (isset($call[$key]) && is_string($call[$key]) && $call[$key] !== '') {
                        $size = isset($call[$key . '_size']) && is_int($call[$key . '_size']) ? $call[$key . '_size'] : null;
                        $type = isset($call[$key . '_type']) && is_string($call[$key . '_type']) ? $call[$key . '_type'] : null;
                        $record[$key] = $this->outgoingBody($call[$key], $type, $max, $size, $only, $meta);
                        self::mark($record, $key, $meta['redacted'], $meta['cut']);
                    }
                }
            }
            if ($failed) {
                $execution->errored = true;
            }

            $this->buffer($execution, 'outgoing', $record);
        } catch (\Throwable $e) {
            Report::error('could not record outgoing call', $e);
        }
    }

    /**
     * @param mixed $message
     */
    public function recordLog(string $level, $message, array $context): void
    {
        try {
            if (!$this->setting('logs.enabled', true)) {
                return;
            }
            $execution = $this->current();
            $minimum = (string) ($execution !== null ? $execution->setting('logs.level', 'warning') : $this->setting('logs.level', 'warning'));

            // Exceptions reported through the log are captured as exception
            // records whatever the log level.
            $exception = isset($context['exception']) && $context['exception'] instanceof \Throwable ? $context['exception'] : null;
            if ($exception !== null && $this->setting('exceptions.enabled', true)) {
                if ($execution !== null) {
                    $this->recordExceptionOn($execution, $exception);
                } else {
                    $this->write([$this->encode($this->exceptionRecord(null, $exception))]);
                }
            }

            if (!Levels::passes($level, $minimum)) {
                return;
            }

            [$text, $redacted, $cut] = $this->cleanText($this->stringify($message), self::MESSAGE_MAX);
            $record = [
                't' => 'log',
                'v' => self::RECORD_VERSION,
                'trace' => $execution !== null ? $execution->trace : null,
                'at' => $this->iso($this->now()),
                'level' => strtolower($level),
                'severity' => Levels::severity($level),
                'message' => $text,
                'context' => [],
                'fingerprint' => Fingerprint::log($level, $text),
            ];
            self::mark($record, 'message', $redacted, $cut);
            if ($this->setting('logs.context', true)) {
                $normalizedContext = $this->normalizeContext($context);
                $record['context'] = $this->redactor->redact($normalizedContext);
                self::mark($record, 'context', $record['context'] !== $normalizedContext);
            } elseif ($context !== []) {
                self::mark($record, 'context', false, 'not captured (logs.context is off)');
            }

            if ($execution === null) {
                $this->write([$this->encode($record)]);

                return;
            }

            $execution->logCount++;
            if (Levels::severity($level) !== null && Levels::severity($level) >= 400) {
                $execution->errored = true;
            }
            if (count($execution->logs) + (int) ($execution->meta['_logs_flushed'] ?? 0) >= (int) $execution->setting('logs.max_per_request', 200)) {
                $execution->dropped['logs']++;

                return;
            }
            $this->buffer($execution, 'logs', $record);
        } catch (\Throwable $e) {
            Report::error('could not record log', $e);
        }
    }

    public function recordException(\Throwable $exception): void
    {
        try {
            if (!$this->setting('exceptions.enabled', true)) {
                return;
            }
            $execution = $this->current();
            if ($execution === null) {
                $this->write([$this->encode($this->exceptionRecord(null, $exception))]);

                return;
            }
            $this->recordExceptionOn($execution, $exception);
        } catch (\Throwable $e) {
            Report::error('could not record exception', $e);
        }
    }

    // ------------------------------------------------------------------
    // Building records
    // ------------------------------------------------------------------

    private function recordExceptionOn(Execution $execution, \Throwable $exception): void
    {
        if (!$this->setting('exceptions.enabled', true)) {
            return;
        }
        $id = spl_object_id($exception);
        if (isset($execution->seenExceptions[$id])) {
            return;
        }
        $execution->seenExceptions[$id] = true;
        $execution->exceptionCount++;
        $execution->errored = true;
        if (count($execution->exceptions) >= self::MAX_EXCEPTIONS) {
            $execution->dropped['exceptions']++;

            return;
        }
        $this->buffer($execution, 'exceptions', $this->exceptionRecord($execution, $exception));
    }

    /**
     * @return array<string, mixed>
     */
    private function exceptionRecord(?Execution $execution, \Throwable $exception): array
    {
        $file = $this->location->relative((string) $exception->getFile());
        $line = (int) $exception->getLine();
        $maxFrames = (int) $this->setting('exceptions.max_frames', 50);

        $frames = [];
        foreach ($exception->getTrace() as $frame) {
            if (count($frames) >= $maxFrames) {
                break;
            }
            if (isset($frame['file'], $frame['line'])) {
                $frames[] = $this->location->relative((string) $frame['file']) . ':' . (int) $frame['line'];
            }
        }

        $previous = [];
        $cause = $exception->getPrevious();
        while ($cause !== null && count($previous) < self::MAX_PREVIOUS) {
            $previous[] = [
                'class' => get_class($cause),
                'message' => $this->exceptionMessage($cause, 1000)[0],
                'file' => $this->location->relative((string) $cause->getFile()),
                'line' => (int) $cause->getLine(),
            ];
            $cause = $cause->getPrevious();
        }

        [$message, $redacted, $cut] = $this->exceptionMessage($exception, self::MESSAGE_MAX);
        $record = [
            't' => 'exception',
            'v' => self::RECORD_VERSION,
            'trace' => $execution !== null ? $execution->trace : null,
            'at' => $this->iso($this->now()),
            'class' => get_class($exception),
            'message' => $message,
            'code' => (string) $exception->getCode(),
            'file' => $file,
            'line' => $line,
            'frames' => $frames,
            'previous' => $previous,
            'fingerprint' => Fingerprint::exception(get_class($exception), $file, $line),
        ];
        self::mark($record, 'message', $redacted, $cut);

        return $record;
    }

    /**
     * @param array{type: int, message: string, file: string, line: int} $error
     * @return array<string, mixed>
     */
    private function fatalRecord(Execution $execution, array $error): array
    {
        $file = $this->location->relative((string) $error['file']);

        return [
            't' => 'exception',
            'v' => self::RECORD_VERSION,
            'trace' => $execution->trace,
            'at' => $this->iso($this->now()),
            'class' => 'FatalError',
            'message' => $this->setting('exceptions.messages', true)
                ? $this->cleanText((string) $error['message'], self::MESSAGE_MAX)[0]
                : self::MESSAGE_NOT_CAPTURED,
            'code' => (string) $error['type'],
            'file' => $file,
            'line' => (int) $error['line'],
            'frames' => [],
            'previous' => [],
            'fingerprint' => Fingerprint::exception('FatalError', $file, (int) $error['line']),
        ];
    }

    /**
     * Lines for everything buffered on the execution. On the final call the
     * repeated-query records are added and the buffers are dropped with the
     * execution; on a partial flush the buffers are cleared and kept going.
     *
     * @return array<int, string>
     */
    private function detailLines(Execution $execution, bool $final): array
    {
        $lines = [];
        $includeQueries = $execution->keepQueries || ($final && $execution->errored);

        if ($includeQueries && $execution->queries !== []) {
            foreach ($this->queryLines($execution, $final) as $line) {
                $lines[] = $line;
            }
        }

        foreach ($execution->slowQueries as $slow) {
            list($hash, $sql, $connection) = $this->statement($execution, $slow['key']);
            $record = [
                't' => 'slow-query',
                'v' => self::RECORD_VERSION,
                'trace' => $execution->trace,
                'at' => $this->iso($slow['at'] - $slow['ms'] / 1000),
                'hash' => $hash,
                'connection' => $connection,
                'sql' => $sql,
                'ms' => round($slow['ms'], 2),
            ];
            if ($slow['location'] !== null) {
                $record['file'] = $slow['location'][0];
                $record['line'] = $slow['location'][1];
            }
            $lines[] = $this->encode($record);
        }

        if ($final) {
            $threshold = (int) $execution->setting('queries.repeated_threshold', 10);
            if ($threshold > 0) {
                foreach ($execution->statements as $key => $statement) {
                    if ($statement[2] < $threshold) {
                        continue;
                    }
                    list($hash, $sql, $connection) = $this->statement($execution, $key);
                    $record = [
                        't' => 'repeated-query',
                        'v' => self::RECORD_VERSION,
                        'trace' => $execution->trace,
                        'hash' => $hash,
                        'connection' => $connection,
                        'sql' => $sql,
                        'count' => $statement[2],
                        'total_ms' => round($statement[3], 2),
                    ];
                    if ($statement[4] !== null) {
                        $record['file'] = $statement[4][0];
                        $record['line'] = $statement[4][1];
                    }
                    $lines[] = $this->encode($record);
                }
            }
        }

        foreach (['outgoing', 'logs', 'exceptions'] as $bucket) {
            foreach ($execution->{$bucket} as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The compact per-execution query list: each distinct statement once in
     * "sql", every execution as [hash, ms] (plus "file:line" when locations
     * are captured for all queries). Split into several lines when needed so
     * no line exceeds max_record_bytes.
     *
     * @return array<int, string>
     */
    private function queryLines(Execution $execution, bool $final): array
    {
        $limit = (int) $this->setting('spool.max_record_bytes', 32768);
        $budget = max(1024, (int) ($limit * 0.85));

        $chunks = [];
        $sql = [];
        $entries = [];
        $size = 200;

        foreach ($execution->queries as $query) {
            list($hash, $normalized, $connection) = $this->statement($execution, $query[0]);
            $entry = [$hash, round($query[1], 2)];
            if ($query[2] !== null) {
                $entry[] = $query[2][0] . ':' . $query[2][1];
            }
            $cost = 24 + (isset($entry[2]) ? strlen($entry[2]) + 3 : 0);
            if (!isset($sql[$hash])) {
                $cost += strlen($normalized) + strlen($connection) + 40;
            }
            if ($entries !== [] && $size + $cost > $budget) {
                $chunks[] = [$sql, $entries];
                $sql = [];
                $entries = [];
                $size = 200;
                $cost = 24 + (isset($entry[2]) ? strlen($entry[2]) + 3 : 0) + strlen($normalized) + strlen($connection) + 40;
            }
            if (!isset($sql[$hash])) {
                $sql[$hash] = ['sql' => $normalized, 'connection' => $connection];
            }
            $entries[] = $entry;
            $size += $cost;
        }
        if ($entries !== []) {
            $chunks[] = [$sql, $entries];
        }

        $lines = [];
        foreach ($chunks as $chunk) {
            $lines[] = $this->encode([
                't' => 'queries',
                'v' => self::RECORD_VERSION,
                'trace' => $execution->trace,
                'partial' => !$final,
                'sql' => $chunk[0],
                'q' => $chunk[1],
            ]);
        }

        return $lines;
    }

    /**
     * @return array{0: string, 1: string, 2: string} [hash, normalized redacted sql, connection]
     */
    private function statement(Execution $execution, string $key): array
    {
        if (!isset($this->normalized[$key])) {
            if (count($this->normalized) >= 2000 || $this->normalizedBytes > self::NORMALIZED_CACHE_BYTES) {
                $this->normalized = [];
                $this->normalizedBytes = 0;
            }
            $statement = $execution->statements[$key] ?? null;
            $connection = $statement !== null ? $statement[0] : '';
            $raw = $statement !== null ? $statement[1] : '';
            $normalized = $raw === null ? SqlNormalizer::TOO_LONG : SqlNormalizer::normalize($raw, $statement !== null ? (string) $statement[5] : '');
            $sql = $execution->setting('queries.capture_sql', true) ? $this->redactor->redactPatterns($normalized) : self::SQL_NOT_CAPTURED;
            $this->normalized[$key] = [SqlNormalizer::hash($connection, $normalized), $sql, $connection];
            $this->normalizedBytes += strlen($sql) + strlen($key);
        }

        return $this->normalized[$key];
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(Execution $execution): array
    {
        $totals = [
            'queries' => $execution->queryCount,
            'query_ms' => round($execution->queryMs, 2),
            'queries_detail' => $execution->keepQueries || $execution->errored,
            'outgoing' => $execution->outgoingCount,
            'outgoing_ms' => round($execution->outgoingMs, 2),
            'logs' => $execution->logCount,
            'exceptions' => $execution->exceptionCount,
        ];
        $dropped = array_filter($execution->dropped);
        if ($dropped !== []) {
            $totals['dropped'] = $dropped;
        }

        return $totals;
    }

    // ------------------------------------------------------------------
    // Partial flushes (long jobs)
    // ------------------------------------------------------------------

    /**
     * Encode a record into one of the execution's buffers, within the
     * memory budget: a request that reaches it keeps its totals but no more
     * records; a job writes what it has and carries on.
     *
     * @param array<string, mixed> $record
     */
    private function buffer(Execution $execution, string $bucket, array $record): void
    {
        $line = $this->encode($record);
        if ($line === null) {
            return;
        }
        $budget = (int) $execution->setting('memory.max_buffer_bytes', 4194304);
        if ($budget > 0 && $execution->bufferedBytes + strlen($line) > $budget) {
            if ($execution->kind !== 'job') {
                $execution->dropped['memory']++;

                return;
            }
            $this->maybeFlush($execution, true);
        }
        $execution->{$bucket}[] = $line;
        $execution->bufferedBytes += strlen($line);
        $execution->pending++;
        $this->maybeFlush($execution);
    }

    private function maybeFlush(Execution $execution, bool $force = false): void
    {
        if ($execution->kind !== 'job' || $execution->pending === 0) {
            return;
        }
        $records = (int) $execution->setting('jobs.flush_records', 500);
        $seconds = (float) $execution->setting('jobs.flush_seconds', 30);
        $due = $force
            || ($records > 0 && $execution->pending >= $records)
            || ($seconds > 0 && $this->now() - $execution->lastFlushAt >= $seconds);
        if (!$due) {
            return;
        }

        $lines = $this->detailLines($execution, false);
        $this->write($lines);

        if ($execution->keepQueries) {
            $execution->meta['_queries_flushed'] = (int) ($execution->meta['_queries_flushed'] ?? 0) + count($execution->queries);
            $execution->queries = [];
        }
        $execution->meta['_outgoing_flushed'] = (int) ($execution->meta['_outgoing_flushed'] ?? 0) + count($execution->outgoing);
        $execution->meta['_logs_flushed'] = (int) ($execution->meta['_logs_flushed'] ?? 0) + count($execution->logs);
        $execution->slowQueries = [];
        $execution->outgoing = [];
        $execution->logs = [];
        $execution->exceptions = [];
        $execution->bufferedBytes = 0;
        $execution->pending = 0;
        $execution->lastFlushAt = $this->now();
        $execution->flushes++;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function newExecution(string $kind, ?string $parent, array $config): Execution
    {
        $this->registerShutdown();
        $queries = isset($config['queries']) && is_array($config['queries']) ? $config['queries'] : [];
        $mode = (string) ($queries['mode'] ?? 'sampled');
        $rate = (float) ($queries['sample_rate'] ?? 0.1);
        $keep = $mode === 'all' || ($mode === 'sampled' && call_user_func($this->random) < $rate);

        return new Execution($kind, self::newTrace(), $parent, $this->now(), $config, $keep);
    }

    private function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function([$this, 'shutdown']);
    }

    private function remove(Execution $execution): void
    {
        foreach ($this->stack as $i => $candidate) {
            if ($candidate === $execution) {
                array_splice($this->stack, $i, 1);

                return;
            }
        }
    }

    /**
     * @param array<int, string|null> $lines
     */
    private function write(array $lines): void
    {
        if ($this->isSwitchedOff()) {
            // Switched off while this execution ran: drop it and anything
            // else still open, write nothing.
            $this->stack = [];

            return;
        }
        $lines = array_values(array_filter($lines, 'is_string'));
        if ($lines !== []) {
            $this->sink->write($lines);
        }
    }

    /**
     * Encode one record, shrinking it if it is larger than max_record_bytes.
     *
     * @param array<string, mixed> $record
     */
    public function encode(array $record): ?string
    {
        $limit = (int) $this->setting('spool.max_record_bytes', 32768);
        $json = self::json($record);
        if ($json !== null && strlen($json) <= $limit) {
            return $json;
        }

        $limitNote = 'to fit the ' . BodyReader::formatBytes($limit) . ' record limit';

        // Drop or cut the bulky parts, biggest offenders first; headers
        // outlive bodies, bodies are shortened before they are dropped.
        $steps = [
            function (array $r) use ($limitNote) {
                foreach (['request_body', 'response_body'] as $key) {
                    if (isset($r[$key]) && is_string($r[$key]) && strlen($r[$key]) > 2048) {
                        $r[$key] = Text::limitBytes($r[$key], 2048) . "\n…[cut to fit the record size limit]";
                        self::mark($r, $key, false, 'shortened to 2 KB ' . $limitNote);
                    }
                }

                return $r;
            },
            function (array $r) use ($limitNote) {
                return self::drop($r, ['request_body', 'response_body'], 'dropped ' . $limitNote);
            },
            function (array $r) use ($limitNote) {
                return self::drop($r, ['request_headers', 'response_headers'], 'dropped ' . $limitNote);
            },
            function (array $r) use ($limitNote) {
                return self::drop($r, ['query'], 'dropped ' . $limitNote);
            },
            function (array $r) use ($limitNote) {
                if (isset($r['context'])) {
                    $r['context'] = ['_truncated' => true];
                    self::mark($r, 'context', false, 'dropped ' . $limitNote);
                }
                if (isset($r['frames']) && is_array($r['frames']) && count($r['frames']) > 10) {
                    self::mark($r, 'frames', false, 'kept 10 of ' . count($r['frames']) . ' ' . $limitNote);
                    $r['frames'] = array_slice($r['frames'], 0, 10);
                }

                return $r;
            },
            function (array $r) use ($limitNote) {
                foreach (['message', 'sql', 'error'] as $key) {
                    if (isset($r[$key]) && is_string($r[$key]) && mb_strlen($r[$key]) > 1000) {
                        $r[$key] = Text::limit($r[$key], 1000);
                        self::mark($r, $key, false, 'shortened to 1,000 characters ' . $limitNote);
                    }
                }

                return self::drop($r, ['previous'], 'dropped ' . $limitNote);
            },
        ];
        foreach ($steps as $step) {
            $record = $step($record);
            $record['_truncated'] = true;
            $json = self::json($record);
            if ($json !== null && strlen($json) <= $limit) {
                return $json;
            }
        }

        return self::json([
            't' => $record['t'] ?? 'unknown',
            'v' => self::RECORD_VERSION,
            'trace' => $record['trace'] ?? null,
            'at' => $record['at'] ?? $this->iso($this->now()),
            '_truncated' => true,
            '_cut' => ['record' => 'details dropped ' . $limitNote],
        ]);
    }

    /**
     * Tags a record field as masked (_redacted: list of fields) and/or cut
     * (_cut: field => what happened), so the server can say so next to it.
     *
     * @param array<string, mixed> $record
     */
    public static function mark(array &$record, string $field, bool $redacted, ?string $cut = null): void
    {
        if ($redacted && !in_array($field, $record['_redacted'] ?? [], true)) {
            $record['_redacted'][] = $field;
        }
        if ($cut !== null) {
            $record['_cut'][$field] = $cut;
        }
    }

    /**
     * @param array<string, mixed> $record
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private static function drop(array $record, array $fields, string $note): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $record)) {
                unset($record[$field]);
                self::mark($record, $field, false, $note);
            }
        }

        return $record;
    }

    /**
     * Text redacted, then cut to $maxChars. Only a bounded head of a very
     * long text is redacted; its last characters are dropped with the cut,
     * so a secret split by the first cut can never be what is kept.
     *
     * @return array{0: string, 1: bool, 2: ?string} [text, was anything masked, cut note]
     */
    private function cleanText(string $raw, int $maxChars): array
    {
        $cut = self::cutNote($raw, $maxChars);
        if ($cut === null) {
            $clean = $this->redactor->redactString($raw);

            return [$clean, $clean !== $raw, null];
        }

        $margin = 256;
        $head = Text::limit($raw, $maxChars + $margin);
        $clean = $this->redactor->redactString($head);
        $length = function_exists('mb_strlen') ? mb_strlen($clean, 'UTF-8') : strlen($clean);

        return [Text::limit($clean, max(0, min($maxChars, $length - $margin))), $clean !== $head, $cut];
    }

    /**
     * An exception message, redacted and cut, or a placeholder when
     * exceptions.messages is off.
     *
     * @return array{0: string, 1: bool, 2: ?string}
     */
    private function exceptionMessage(\Throwable $exception, int $maxChars): array
    {
        if (!$this->setting('exceptions.messages', true)) {
            return [self::MESSAGE_NOT_CAPTURED, false, null];
        }

        return $this->cleanText((string) $exception->getMessage(), $maxChars);
    }

    /**
     * Whether log-monitor:off is in force. Checked at most every few
     * seconds (one stat call), so a long-running worker notices the switch
     * without paying for it on every event.
     */
    public function isSwitchedOff(): bool
    {
        if ($this->switchedOff === null) {
            return false;
        }
        $now = $this->now();
        if ($this->switchCheckedAt === null || $now - $this->switchCheckedAt >= self::SWITCH_CHECK_SECONDS || $now < $this->switchCheckedAt) {
            $this->switchCheckedAt = $now;
            try {
                $this->off = (bool) call_user_func($this->switchedOff);
            } catch (\Throwable $e) {
                $this->off = false;
            }
        }

        return $this->off;
    }

    public function sanitizer(): BodySanitizer
    {
        return $this->sanitizer;
    }

    /**
     * "kept 4,000 of 12,345 characters" when $text is longer than $maxChars.
     */
    public static function cutNote(string $text, int $maxChars): ?string
    {
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);

        return $length > $maxChars ? 'kept ' . number_format($maxChars) . ' of ' . number_format($length) . ' characters' : null;
    }

    private static function json(array $record): ?string
    {
        $json = json_encode(
            $record,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
        );

        return is_string($json) ? $json : null;
    }

    /**
     * Whether outgoing bodies are kept for a call: outgoing.bodies is
     * errors | always | never. The older outgoing.bodies_on_error flag still
     * works when bodies is not set.
     */
    public function keepsOutgoingBodies(bool $failed, ?string $host = null): bool
    {
        $mode = $this->setting('outgoing.bodies');
        if (!is_string($mode) || !in_array($mode, ['errors', 'always', 'never'], true)) {
            $mode = $this->setting('outgoing.bodies_on_error', false) ? 'errors' : 'never';
        }
        if (!($mode === 'always' || ($mode === 'errors' && $failed))) {
            return false;
        }

        $hosts = $this->setting('outgoing.body_hosts', []);
        if (!is_array($hosts) || $hosts === [] || $host === null) {
            return true;
        }
        $host = strtolower($host);
        foreach ($hosts as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern !== '' && ($pattern === $host || (strpos($pattern, '*.') === 0 && substr($host, -strlen($pattern) + 1) === substr($pattern, 1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Query parameters as name => value, masked and bounded: secret-looking
     * names (redact.keys and redact.query_keys) masked, values run through
     * the redactor, nested values kept as JSON, at most 50 parameters of 500
     * characters and 8 KB in all. What does not fit is counted in a note.
     *
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    public function queryParams(array $params, ?array &$meta = null): array
    {
        $meta = ['redacted' => false, 'cut' => null];
        $replacement = $this->redactor->replacement();

        $out = [];
        $bytes = 0;
        $skipped = 0;
        foreach ($params as $name => $value) {
            $name = Text::limit((string) $name, 100);
            if (count($out) >= 50) {
                $skipped++;
                continue;
            }

            if ($this->redactor->isSecretName($name)) {
                $value = $replacement;
                $meta['redacted'] = true;
            } else {
                $clean = $this->redactor->redact($value);
                $meta['redacted'] = $meta['redacted'] || $clean !== $value;
                $value = is_array($clean) ? (string) self::json($clean) : (is_scalar($clean) || $clean === null ? (string) $clean : '');
                if (self::cutNote($value, 500) !== null) {
                    $meta['cut'] = 'long values cut to 500 characters';
                }
                $value = Text::limit($value, 500);
            }

            $bytes += strlen($name) + strlen($value);
            if ($bytes > 8192) {
                $skipped++;
                continue;
            }
            $out[$name] = $value;
        }
        if ($skipped > 0) {
            $out['…'] = "[{$skipped} more parameter" . ($skipped === 1 ? '' : 's') . ' not kept]';
            $meta['cut'] = "kept " . (count($out) - 1) . ' of ' . (count($out) - 1 + $skipped) . ' parameters';
        }

        return $out;
    }

    /**
     * Header name => value, lower-cased names, with the names listed in
     * redact.headers masked and every other value redacted and cut.
     *
     * @param array<string, mixed> $headers name => string or list of strings
     * @return array<string, string>
     */
    public function headers(array $headers, ?array &$meta = null): array
    {
        $meta = ['redacted' => false, 'cut' => count($headers) > 100 ? 'kept 100 of ' . count($headers) . ' headers' : null];
        $replacement = $this->redactor->replacement();
        $out = [];
        foreach (array_slice($headers, 0, 100, true) as $name => $values) {
            $name = strtolower((string) $name);
            $value = is_array($values) ? implode(', ', array_map('strval', $values)) : (string) $values;
            if ($this->redactor->isSecretHeader($name)) {
                $out[$name] = $replacement;
                $meta['redacted'] = true;
                continue;
            }
            $clean = $this->redactor->redactString($value);
            $meta['redacted'] = $meta['redacted'] || $clean !== $value;
            if ($meta['cut'] === null && self::cutNote($clean, 500) !== null) {
                $meta['cut'] = 'long values cut to 500 characters';
            }
            $out[$name] = Text::limit($clean, 500);
        }

        return $out;
    }

    /**
     * A body parsed, masked and then cut (see BodySanitizer). Notes from
     * BodyReader ("[streamed body not kept, 2.1 MB]") pass as is.
     *
     * @param array<int, string> $onlyFields
     */
    private function outgoingBody(string $body, ?string $contentType, int $maxBytes, ?int $fullSize, array $onlyFields, ?array &$meta = null): string
    {
        $meta = ['redacted' => false, 'cut' => null];
        if (preg_match('/^\[[^\]]* not kept(, [^\]]*)?\]$/', $body) === 1) {
            $meta['cut'] = trim($body, '[]');

            return $body;
        }

        $clean = $this->sanitizer->sanitize($body, $contentType, $maxBytes, $fullSize, $onlyFields);
        $meta = ['redacted' => $clean['redacted'], 'cut' => $clean['cut']];

        return $clean['text'];
    }

    /**
     * Whether redaction changed a body. JSON is re-encoded by the redactor,
     * so compare decoded values rather than bytes.
     */
    public static function masked(string $before, string $after): bool
    {
        if ($before === $after) {
            return false;
        }
        $a = json_decode($before, true);
        $b = json_decode($after, true);

        return !(is_array($a) && is_array($b) && $a == $b);
    }

    /**
     * @param mixed $message
     */
    private function stringify($message): string
    {
        if (is_string($message)) {
            return $message;
        }
        if (is_scalar($message) || $message === null) {
            return var_export($message, true);
        }
        if (is_object($message) && method_exists($message, '__toString')) {
            return (string) $message;
        }
        $json = self::json(['m' => $this->normalizeValue($message, 0)]);

        return $json === null ? '' : substr($json, 5, -1);
    }

    /**
     * Turn log context into plain arrays and scalars, never exposing object
     * internals. Throwables are summarised (they get their own record).
     *
     * @return array<string, mixed>
     */
    private function normalizeContext(array $context): array
    {
        $this->contextBudget = self::CONTEXT_MAX_VALUES;
        $normalized = $this->normalizeValue($context, 0);

        return is_array($normalized) ? $normalized : [];
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function normalizeValue($value, int $depth)
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : (string) $value;
        }
        if (is_string($value)) {
            // Long strings are redacted before they are cut (see cleanText).
            return self::cutNote($value, self::MESSAGE_MAX) === null ? $value : $this->cleanText($value, self::MESSAGE_MAX)[0];
        }
        if ($depth >= self::CONTEXT_MAX_DEPTH) {
            return '[depth limit]';
        }
        if (is_array($value)) {
            $out = [];
            $i = 0;
            foreach ($value as $key => $item) {
                if ($i++ >= self::CONTEXT_MAX_ITEMS || --$this->contextBudget < 0) {
                    $out['_more'] = count($value) - $i + 1;
                    break;
                }
                $out[$key] = $this->normalizeValue($item, $depth + 1);
            }

            return $out;
        }
        if ($value instanceof \Throwable) {
            return [
                'class' => get_class($value),
                'message' => $this->exceptionMessage($value, 1000)[0],
                'file' => $this->location->relative((string) $value->getFile()),
                'line' => (int) $value->getLine(),
            ];
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if ($value instanceof \JsonSerializable) {
            return $this->normalizeValue($value->jsonSerialize(), $depth + 1);
        }
        if (is_object($value) && method_exists($value, 'toArray')) {
            return $this->normalizeValue($value->toArray(), $depth + 1);
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return $this->normalizeValue((string) $value, $depth);
        }
        if (is_object($value)) {
            return ['class' => get_class($value)];
        }

        return gettype($value);
    }

    private function stripQuery(string $url): string
    {
        $cut = strcspn($url, '?#');

        return substr($url, 0, $cut);
    }

    private function elapsedMs(Execution $execution): float
    {
        return round(($this->now() - $execution->startedAt) * 1000, 2);
    }

    public function elapsedMsFor(Execution $execution): float
    {
        return $this->elapsedMs($execution);
    }

    private function now(): float
    {
        return (float) call_user_func($this->clock);
    }

    private function iso(float $time): string
    {
        $seconds = (int) floor($time);
        $millis = (int) floor(($time - $seconds) * 1000);

        return gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%03dZ', $millis);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function setting(string $path, $default = null)
    {
        $value = $this->config;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $overrides "queries.mode" => "all", ...
     * @return array<string, mixed>
     */
    public static function applyOverrides(array $config, array $overrides): array
    {
        foreach ($overrides as $path => $value) {
            $segments = explode('.', (string) $path);
            $target = &$config;
            foreach ($segments as $segment) {
                if (!isset($target[$segment]) || !is_array($target[$segment])) {
                    $target[$segment] = isset($target[$segment]) && is_array($target[$segment]) ? $target[$segment] : [];
                }
                $target = &$target[$segment];
            }
            $target = $value;
            unset($target);
        }

        return $config;
    }

    public static function newTrace(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return substr(md5(uniqid('', true)), 0, 16);
        }
    }

    /**
     * @return array{type: int, message: string, file: string, line: int}|null
     */
    private static function fatalError(): ?array
    {
        $error = error_get_last();
        if (!is_array($error)) {
            return null;
        }

        return in_array($error['type'] ?? 0, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR], true) ? $error : null;
    }
}
