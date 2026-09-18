<?php

namespace DevZone\LogMonitor\Capture;

/**
 * Everything captured for one unit of work: a web request or a queued job.
 * Lives only in memory until the execution finishes (or flushes a partial
 * chunk, for long jobs).
 */
final class Execution
{
    /** @var string request | job */
    public $kind;

    /** @var string */
    public $trace;

    /** @var string|null Trace of the request or job that started this one. */
    public $parent;

    /** @var float microtime(true) at start */
    public $startedAt;

    /** @var array<string, mixed> Effective settings (after job overrides). */
    public $config;

    /** @var bool Whether individual queries are kept (sampled/all mode). */
    public $keepQueries;

    /** @var array<string, mixed> Kind-specific details (job class, queue...). */
    public $meta = [];

    // Totals, never reset by partial flushes.
    public $queryCount = 0;
    public $queryMs = 0.0;
    public $outgoingCount = 0;
    public $outgoingMs = 0.0;
    public $logCount = 0;
    public $exceptionCount = 0;

    /** @var array<string, int> Records not kept because a cap was reached. */
    public $dropped = ['queries' => 0, 'slow_queries' => 0, 'outgoing' => 0, 'logs' => 0, 'exceptions' => 0];

    /**
     * Per distinct statement: [connection, sql, count, total_ms, location|null].
     *
     * @var array<string, array{0: string, 1: string, 2: int, 3: float, 4: array|null}>
     */
    public $statements = [];

    /** @var array<int, array{0: string, 1: float, 2: array|null}> [statement key, ms, location] */
    public $queries = [];

    /** @var array<int, array<string, mixed>> */
    public $slowQueries = [];

    /** @var array<int, array<string, mixed>> Built records waiting to be written. */
    public $outgoing = [];

    /** @var array<int, array<string, mixed>> */
    public $logs = [];

    /** @var array<int, array<string, mixed>> */
    public $exceptions = [];

    /** @var array<int, bool> spl_object_id of exceptions already recorded */
    public $seenExceptions = [];

    /** @var bool Something went wrong; forces full query detail in sampled mode. */
    public $errored = false;

    /** @var int Records buffered since the last flush (jobs). */
    public $pending = 0;

    /** @var float */
    public $lastFlushAt;

    /** @var int */
    public $flushes = 0;

    /** @var bool */
    public $finished = false;

    public function __construct(string $kind, string $trace, ?string $parent, float $startedAt, array $config, bool $keepQueries)
    {
        $this->kind = $kind;
        $this->trace = $trace;
        $this->parent = $parent;
        $this->startedAt = $startedAt;
        $this->lastFlushAt = $startedAt;
        $this->config = $config;
        $this->keepQueries = $keepQueries;
    }

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
}
