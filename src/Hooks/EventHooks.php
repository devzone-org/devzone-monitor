<?php

namespace DevZone\LogMonitor\Hooks;

use DevZone\LogMonitor\Capture\BodyReader;
use DevZone\LogMonitor\Capture\Recorder;
use DevZone\LogMonitor\Support\Report;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Translates Laravel events into Recorder calls. Every listener catches
 * everything: a monitoring failure must never reach the application.
 */
class EventHooks
{
    /** @var Recorder */
    private $recorder;

    /** @var array<string, array<int, float>> method+url => start times, for calls without transfer stats */
    private $outgoingStarts = [];

    /**
     * Matches SQL on the database queue's own tables. Inside a job run the
     * worker reserves, deletes and fails jobs with these queries; they are
     * queue bookkeeping, not the job's work, so they are not recorded.
     *
     * @var string|null
     */
    private $queueTablePattern;

    /**
     * @param array<int, string> $queueTables
     */
    public function __construct(Recorder $recorder, array $queueTables = [])
    {
        $this->recorder = $recorder;
        $tables = array_values(array_unique(array_filter($queueTables, function ($table) {
            return is_string($table) && $table !== '';
        })));
        if ($tables !== []) {
            $names = implode('|', array_map(function ($table) {
                return preg_quote($table, '/');
            }, $tables));
            $this->queueTablePattern = '/\\b(?:from|into|update|table)\\s+[`"\\[]?(?:' . $names . ')[`"\\]]?(?:\\s|$|;|\\()/i';
        }
    }

    public function register(Dispatcher $events): void
    {
        if ($this->recorder->setting('queries.enabled', true)) {
            $events->listen(\Illuminate\Database\Events\QueryExecuted::class, [$this, 'onQuery']);
        }

        if ($this->recorder->setting('logs.enabled', true) || $this->recorder->setting('exceptions.enabled', true)) {
            $events->listen(\Illuminate\Log\Events\MessageLogged::class, [$this, 'onLog']);
        }

        // Laravel's HTTP client events exist from Laravel 8.
        if ($this->recorder->setting('outgoing.enabled', true) && class_exists(\Illuminate\Http\Client\Events\ResponseReceived::class)) {
            $events->listen(\Illuminate\Http\Client\Events\RequestSending::class, [$this, 'onRequestSending']);
            $events->listen(\Illuminate\Http\Client\Events\ResponseReceived::class, [$this, 'onResponseReceived']);
            if (class_exists(\Illuminate\Http\Client\Events\ConnectionFailed::class)) {
                $events->listen(\Illuminate\Http\Client\Events\ConnectionFailed::class, [$this, 'onConnectionFailed']);
            }
        }

        if ($this->recorder->setting('jobs.enabled', true)) {
            $events->listen(\Illuminate\Queue\Events\JobProcessing::class, [$this, 'onJobProcessing']);
            $events->listen(\Illuminate\Queue\Events\JobProcessed::class, [$this, 'onJobProcessed']);
            $events->listen(\Illuminate\Queue\Events\JobExceptionOccurred::class, [$this, 'onJobExceptionOccurred']);
            $events->listen(\Illuminate\Queue\Events\JobFailed::class, [$this, 'onJobFailed']);

            if (method_exists(\Illuminate\Queue\Queue::class, 'createPayloadUsing')) {
                $recorder = $this->recorder;
                \Illuminate\Queue\Queue::createPayloadUsing(function ($connection, $queue, $payload) use ($recorder) {
                    try {
                        $trace = $recorder->currentTrace();

                        return $trace === null ? [] : ['log_monitor' => ['parent' => $trace]];
                    } catch (\Throwable $e) {
                        return [];
                    }
                });
            }
        }
    }

    // ------------------------------------------------------------------

    public function onQuery($event): void
    {
        try {
            if ($this->queueTablePattern !== null) {
                $current = $this->recorder->current();
                if ($current !== null && $current->kind === 'job' && @preg_match($this->queueTablePattern, (string) $event->sql) === 1) {
                    return;
                }
            }
            $this->recorder->recordQuery((string) $event->sql, (float) $event->time, (string) ($event->connectionName ?? ''));
        } catch (\Throwable $e) {
            Report::error('query listener failed', $e);
        }
    }

    public function onLog($event): void
    {
        try {
            $context = is_array($event->context ?? null) ? $event->context : [];
            $this->recorder->recordLog((string) $event->level, $event->message, $context);
        } catch (\Throwable $e) {
            Report::error('log listener failed', $e);
        }
    }

    public function onRequestSending($event): void
    {
        try {
            if ($this->recorder->current() === null) {
                return;
            }
            $key = $this->callKey($event->request);
            $this->outgoingStarts[$key][] = microtime(true);
        } catch (\Throwable $e) {
            Report::error('outgoing listener failed', $e);
        }
    }

    public function onResponseReceived($event): void
    {
        try {
            if ($this->recorder->current() === null) {
                return;
            }
            $request = $event->request;
            $response = $event->response;
            $start = $this->popStart($this->callKey($request));

            $stats = method_exists($response, 'handlerStats') ? (array) $response->handlerStats() : [];
            $ms = isset($stats['total_time']) && is_numeric($stats['total_time'])
                ? (float) $stats['total_time'] * 1000
                : ($start !== null ? (microtime(true) - $start) * 1000 : null);

            $status = (int) $response->status();
            $failed = $status >= 400;

            $call = [
                'method' => $request->method(),
                'url' => $request->url(),
                'status' => $status,
                'ms' => $ms,
                'request_bytes' => isset($stats['size_upload']) ? (int) $stats['size_upload'] : null,
                'response_bytes' => isset($stats['size_download']) ? (int) $stats['size_download'] : $this->headerLength($response),
            ];
            if ($this->recorder->setting('outgoing.headers', true)) {
                $call['request_headers'] = method_exists($request, 'headers') ? (array) $request->headers() : [];
                $call['response_headers'] = method_exists($response, 'headers') ? (array) $response->headers() : [];
            }
            if ($this->recorder->keepsOutgoingBodies($failed)) {
                // Read only when kept, and only the first few KB: see BodyReader.
                $call += $this->bodies(['request' => $request, 'response' => $response]);
            }
            $this->recorder->recordOutgoing($call);
        } catch (\Throwable $e) {
            Report::error('outgoing listener failed', $e);
        }
    }

    public function onConnectionFailed($event): void
    {
        try {
            if ($this->recorder->current() === null) {
                return;
            }
            $request = $event->request;
            $start = $this->popStart($this->callKey($request));
            $this->recorder->recordOutgoing([
                'method' => $request->method(),
                'url' => $request->url(),
                'status' => null,
                'ms' => $start !== null ? (microtime(true) - $start) * 1000 : null,
                'error' => 'connection failed',
                'request_headers' => $this->recorder->setting('outgoing.headers', true) && method_exists($request, 'headers')
                    ? (array) $request->headers()
                    : null,
            ] + ($this->recorder->keepsOutgoingBodies(true) ? $this->bodies(['request' => $request]) : []));
        } catch (\Throwable $e) {
            Report::error('outgoing listener failed', $e);
        }
    }

    /**
     * request_body / response_body (+ _size) for the messages given, read
     * with a cap well under memory: redaction headroom of 4x what is kept.
     *
     * @param array<string, mixed> $messages
     * @return array<string, mixed>
     */
    private function bodies(array $messages): array
    {
        $limit = 4 * max(256, (int) $this->recorder->setting('outgoing.max_bytes', 8192));
        $out = [];
        foreach ($messages as $side => $message) {
            $read = BodyReader::read($message, $limit);
            if ($read !== null) {
                $out[$side . '_body'] = $read['body'];
                $out[$side . '_body_size'] = $read['size'];
            }
        }

        return $out;
    }

    public function onJobProcessing($event): void
    {
        try {
            $job = $event->job;
            $payload = (array) $job->payload();
            $parent = isset($payload['log_monitor']['parent']) && is_string($payload['log_monitor']['parent'])
                ? $payload['log_monitor']['parent']
                : null;
            $this->recorder->startJob($this->jobMeta($event->connectionName, $job), $parent);
        } catch (\Throwable $e) {
            Report::error('job listener failed', $e);
        }
    }

    public function onJobProcessed($event): void
    {
        try {
            $execution = $this->recorder->findJob($this->jobKey($event->job));
            if ($execution !== null) {
                $this->recorder->finishJob($execution, $this->jobStatus($event->job, 'processed'));
            }
        } catch (\Throwable $e) {
            Report::error('job listener failed', $e);
        }
    }

    public function onJobExceptionOccurred($event): void
    {
        try {
            $execution = $this->recorder->findJob($this->jobKey($event->job));
            if ($execution !== null) {
                $this->recorder->finishJob($execution, $this->jobStatus($event->job, 'exception'), $event->exception);
            }
        } catch (\Throwable $e) {
            Report::error('job listener failed', $e);
        }
    }

    public function onJobFailed($event): void
    {
        try {
            $key = $this->jobKey($event->job);
            if ($this->recorder->findJob($key) !== null) {
                return; // still running here: JobExceptionOccurred finishes it with status failed
            }
            $payload = (array) $event->job->payload();
            $parent = isset($payload['log_monitor']['parent']) && is_string($payload['log_monitor']['parent'])
                ? $payload['log_monitor']['parent']
                : null;
            $this->recorder->recordJobFailure($this->jobMeta($event->connectionName, $event->job), $parent, $event->exception ?? null);
        } catch (\Throwable $e) {
            Report::error('job listener failed', $e);
        }
    }

    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function jobMeta($connectionName, $job): array
    {
        return [
            'class' => method_exists($job, 'resolveName') ? (string) $job->resolveName() : get_class($job),
            'queue' => method_exists($job, 'getQueue') ? $job->getQueue() : null,
            'connection' => is_string($connectionName) ? $connectionName : null,
            'job_id' => $this->jobKey($job),
            'attempt' => method_exists($job, 'attempts') ? (int) $job->attempts() : null,
        ];
    }

    private function jobKey($job): string
    {
        $uuid = method_exists($job, 'uuid') ? $job->uuid() : null;
        if (is_string($uuid) && $uuid !== '') {
            return $uuid;
        }
        $id = method_exists($job, 'getJobId') ? $job->getJobId() : null;
        if ((is_string($id) || is_int($id)) && (string) $id !== '') {
            return (string) $id;
        }

        return 'object:' . spl_object_id($job);
    }

    private function jobStatus($job, string $default): string
    {
        if (method_exists($job, 'hasFailed') && $job->hasFailed()) {
            return 'failed';
        }
        if (method_exists($job, 'isReleased') && $job->isReleased()) {
            return 'released';
        }

        return $default;
    }

    private function callKey($request): string
    {
        return strtoupper((string) $request->method()) . ' ' . (string) $request->url();
    }

    private function popStart(string $key): ?float
    {
        if (empty($this->outgoingStarts[$key])) {
            return null;
        }
        $start = array_shift($this->outgoingStarts[$key]);
        if ($this->outgoingStarts[$key] === []) {
            unset($this->outgoingStarts[$key]);
        }

        return $start;
    }

    private function headerLength($response): ?int
    {
        $length = method_exists($response, 'header') ? $response->header('Content-Length') : null;

        return is_numeric($length) ? (int) $length : null;
    }
}
