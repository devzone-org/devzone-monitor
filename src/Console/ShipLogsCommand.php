<?php

namespace DevZone\LogMonitor\Console;

use DevZone\LogMonitor\Jobs\ShipLogBatch;
use DevZone\LogMonitor\Support\EntryBuilder;
use DevZone\LogMonitor\Support\LogFileReader;
use DevZone\LogMonitor\Support\PathGuard;
use DevZone\LogMonitor\Support\StateStore;
use DevZone\LogMonitor\Transport\HttpTransport;
use Illuminate\Console\Command;

/**
 * Reads new JSON log lines and ships them to the monitoring server.
 *
 * Self-protection rules:
 *  - the whole run is wrapped in try/catch(\Throwable); nothing escapes
 *  - the package never reports its own problems through Log::, which would
 *    write to the very file being read and loop forever; error_log() only
 *  - offsets are advanced only after a batch has been shipped (or queued)
 */
class ShipLogsCommand extends Command
{
    /** @var string */
    protected $signature = 'log-monitor:ship
        {--dry-run : Read and parse new entries, but do not ship them or save state}';

    /** @var string */
    protected $description = 'Ship new JSON log entries to the DevZone monitoring server';

    /** @var LogFileReader */
    private $reader;

    /** @var EntryBuilder */
    private $builder;

    /** @var StateStore */
    private $state;

    /** @var PathGuard */
    private $guard;

    /** @var HttpTransport */
    private $transport;

    /** @var array<string, mixed> */
    private $config = [];

    /** @var int */
    private $shipped = 0;

    /** @var int */
    private $filtered = 0;

    /** @var int */
    private $malformed = 0;

    /** @var bool */
    private $failed = false;

    /** @var bool */
    private $dryRun = false;

    public function handle(
        LogFileReader $reader,
        EntryBuilder $builder,
        StateStore $state,
        PathGuard $guard,
        HttpTransport $transport
    ): int {
        $this->reader = $reader;
        $this->builder = $builder;
        $this->state = $state;
        $this->guard = $guard;
        $this->transport = $transport;

        // Command instances are reused by in-process Artisan::call(); start clean.
        $this->shipped = 0;
        $this->filtered = 0;
        $this->malformed = 0;
        $this->failed = false;
        $this->dryRun = false;

        try {
            return $this->ship();
        } catch (\Throwable $e) {
            $message = $this->sanitize(get_class($e) . ': ' . $e->getMessage());
            error_log('[log-monitor] ship aborted: ' . $message);
            $this->error('log-monitor: ' . $message);

            return 1;
        }
    }

    private function ship(): int
    {
        $config = config('log-monitor');
        $this->config = is_array($config) ? $config : [];
        $this->dryRun = (bool) $this->option('dry-run');

        if (empty($this->config['enabled'])) {
            $this->line('log-monitor is disabled (LOG_MONITOR_ENABLED=false).');

            return 0;
        }

        if (!$this->transport->isConfigured()) {
            $this->warn('log-monitor is not configured: set LOG_MONITOR_ENDPOINT and LOG_MONITOR_API_KEY.');

            return 0;
        }

        $endpoint = (string) ($this->config['endpoint'] ?? '');
        if (!HttpTransport::isSecureEndpoint($endpoint)) {
            error_log('[log-monitor] endpoint rejected: must be an absolute https URL');
            $this->error('log-monitor: endpoint rejected, it must be an absolute https URL.');

            return 1;
        }

        $patterns = isset($this->config['paths']) && is_array($this->config['paths']) ? $this->config['paths'] : [];
        $allFiles = $this->guard->files($patterns);

        $this->state->load();
        $this->state->prune($this->existingFilenames($allFiles));

        if (!$this->dryRun && !$this->state->canWrite()) {
            error_log('[log-monitor] state file is not writable or disk space is low: ' . $this->state->path());
            $this->error('log-monitor: cannot write state file, nothing shipped.');

            return 1;
        }

        $files = $this->recentFiles($allFiles);
        $minSeverity = EntryBuilder::severity((string) ($this->config['min_level'] ?? 'warning'));
        if ($minSeverity === null) {
            $minSeverity = EntryBuilder::LEVELS['warning'];
        }
        $batchSize = max(1, (int) ($this->config['batch_size'] ?? 100));
        $maxPerRun = max(1, (int) ($this->config['max_per_run'] ?? 1000));

        foreach ($files as $path) {
            if ($this->failed || $this->shipped >= $maxPerRun) {
                break;
            }
            $this->drainFile($path, $minSeverity, $batchSize, $maxPerRun);
        }

        // Persist prunes / resets even when nothing was shipped.
        if (!$this->dryRun && !$this->failed) {
            $this->state->save();
        }

        $this->line(sprintf(
            'log-monitor: %s %d entr%s, %d below %s, %d malformed line%s skipped%s.',
            $this->dryRun ? 'would ship' : 'shipped',
            $this->shipped,
            $this->shipped === 1 ? 'y' : 'ies',
            $this->filtered,
            (string) ($this->config['min_level'] ?? 'warning'),
            $this->malformed,
            $this->malformed === 1 ? '' : 's',
            $this->failed ? ' (stopped early: shipping failed, entries will be retried next run)' : ''
        ));

        return $this->failed ? 1 : 0;
    }

    /**
     * Keep draining one file until it is caught up, the per-run ceiling is hit
     * or a batch fails.
     */
    private function drainFile(string $path, int $minSeverity, int $batchSize, int $maxPerRun): void
    {
        $name = basename($path);
        $offset = $this->state->offset($name);

        while (!$this->failed && $this->shipped < $maxPerRun) {
            $chunk = $this->reader->read($path, $offset);

            if ($chunk->reset) {
                $this->line(sprintf('%s: file is smaller than the stored offset, restarting from 0.', $name));
            }

            if ($chunk->isEmpty()) {
                if ($chunk->end !== $offset || $chunk->reset) {
                    // Reset to 0 or an oversized line skipped: record it.
                    $offset = $chunk->end;
                    $this->commit($name, $offset, $chunk->size);
                }
                break;
            }

            $batch = [];
            $consumedTo = $chunk->start;

            foreach ($chunk->lines as $item) {
                $line = $item[0];
                $end = $item[1];

                $entry = $this->builder->fromLine($line);
                if ($entry === null) {
                    $this->malformed++;
                    $consumedTo = $end;
                    continue;
                }
                if ($entry['severity'] < $minSeverity) {
                    $this->filtered++;
                    $consumedTo = $end;
                    continue;
                }

                $batch[] = $entry;
                $consumedTo = $end;

                if (count($batch) >= $batchSize || $this->shipped + count($batch) >= $maxPerRun) {
                    if (!$this->shipBatch($batch)) {
                        $this->failed = true;

                        return;
                    }
                    $this->shipped += count($batch);
                    $batch = [];
                    $offset = $consumedTo;
                    if (!$this->commit($name, $offset, $chunk->size)) {
                        return;
                    }
                    if ($this->shipped >= $maxPerRun) {
                        return;
                    }
                }
            }

            if ($batch !== []) {
                if (!$this->shipBatch($batch)) {
                    $this->failed = true;

                    return;
                }
                $this->shipped += count($batch);
            }

            $offset = $consumedTo;
            if (!$this->commit($name, $offset, $chunk->size)) {
                return;
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function shipBatch(array $entries): bool
    {
        if ($this->dryRun) {
            return true;
        }

        if ($this->shouldQueue()) {
            try {
                $pending = ShipLogBatch::dispatch($entries);
                $connection = $this->config['queue']['connection'] ?? null;
                $queue = $this->config['queue']['name'] ?? null;
                if (is_string($connection) && $connection !== '') {
                    $pending->onConnection($connection);
                }
                if (is_string($queue) && $queue !== '') {
                    $pending->onQueue($queue);
                }
                // Dispatch happens when $pending is destructed.
                unset($pending);

                return true;
            } catch (\Throwable $e) {
                error_log('[log-monitor] could not queue batch: ' . $this->sanitize(get_class($e) . ': ' . $e->getMessage()));

                return false;
            }
        }

        return $this->transport->send($entries);
    }

    private function shouldQueue(): bool
    {
        $queueConfig = isset($this->config['queue']) && is_array($this->config['queue']) ? $this->config['queue'] : [];
        if (isset($queueConfig['enabled']) && !$queueConfig['enabled']) {
            return false;
        }

        $connection = $queueConfig['connection'] ?? null;
        if (!is_string($connection) || $connection === '') {
            $connection = config('queue.default');
        }
        if (!is_string($connection) || $connection === '') {
            return false;
        }

        $driver = config('queue.connections.' . $connection . '.driver');

        return is_string($driver) && !in_array($driver, ['sync', 'null'], true);
    }

    private function commit(string $name, int $offset, int $size): bool
    {
        $this->state->set($name, $offset, $size);
        if ($this->dryRun) {
            return true;
        }
        if ($this->state->save()) {
            return true;
        }

        // Shipped but could not record it: stop now to keep any duplication
        // limited to this single batch.
        $this->failed = true;
        $this->error('log-monitor: could not save state, stopping.');

        return false;
    }

    /**
     * @param array<int, string> $files
     * @return array<int, string>
     */
    private function recentFiles(array $files): array
    {
        $maxAgeHours = (int) ($this->config['max_file_age_hours'] ?? 48);
        if ($maxAgeHours <= 0) {
            return $files;
        }
        $cutoff = time() - $maxAgeHours * 3600;

        return array_values(array_filter($files, function ($path) use ($cutoff) {
            $mtime = @filemtime($path);

            return $mtime !== false && $mtime >= $cutoff;
        }));
    }

    /**
     * Names whose file still exists: anything matched by the patterns plus any
     * tracked name still present in the log root. Keyed on existence, not on
     * the patterns alone, so a broken paths config cannot wipe the offsets.
     *
     * @param array<int, string> $matchedFiles
     * @return array<int, string>
     */
    private function existingFilenames(array $matchedFiles): array
    {
        $names = array_map('basename', $matchedFiles);
        $root = $this->guard->root();
        foreach (array_keys($this->state->load()) as $name) {
            if (basename($name) === $name && is_file($root . DIRECTORY_SEPARATOR . $name)) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    private function sanitize(string $text): string
    {
        $key = $this->config['api_key'] ?? config('log-monitor.api_key');
        if (is_string($key) && $key !== '') {
            $text = str_replace($key, '[REDACTED]', $text);
        }

        return $text;
    }
}
