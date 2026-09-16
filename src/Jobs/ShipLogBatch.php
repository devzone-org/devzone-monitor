<?php

namespace DevZone\LogMonitor\Jobs;

use DevZone\LogMonitor\Transport\HttpTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Ships one batch of entries from a queue worker.
 *
 * The entries are redacted BEFORE this job is dispatched, so the serialised
 * payload sitting in Redis / the jobs table / failed_jobs never contains
 * secrets. Failures are retried with backoff via release(); nothing is thrown
 * and nothing is written through Log::, so a broken monitoring server cannot
 * flood the very file this package reads.
 */
class ShipLogBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @var int */
    public $tries = 3;

    /** @var int */
    public $timeout = 30;

    /** @var array<int, array<string, mixed>> */
    public $entries;

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    public function __construct(array $entries)
    {
        $this->entries = array_values($entries);
    }

    public function handle(HttpTransport $transport): void
    {
        if ($transport->send($this->entries)) {
            return;
        }

        $attempt = max(1, (int) $this->attempts());
        if ($attempt >= $this->tries) {
            $this->fail(new \RuntimeException(sprintf(
                'log-monitor: batch of %d entries could not be shipped after %d attempts',
                count($this->entries),
                $attempt
            )));

            return;
        }

        $this->release($attempt * 60);
    }

    public function failed(\Throwable $exception): void
    {
        error_log('[log-monitor] queued batch failed permanently: ' . $exception->getMessage());
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['log-monitor'];
    }
}
