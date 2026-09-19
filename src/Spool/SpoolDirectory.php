<?php

namespace DevZone\LogMonitor\Spool;

use DevZone\LogMonitor\Support\Report;

/**
 * The spool folder:
 *
 *   spool/current.ndjson                  appended to by every request/job
 *   spool/batch-YYYYmmdd-HHMMSS-xxxx.ndjson  rotated, waiting to be shipped
 *   spool/batch-....ndjson.progress       lines already accepted by the server
 *   spool/failed/batch-....ndjson         rejected repeatedly by the server
 *   spool/last-run.json                   summary of the last ship run
 *
 * Batch names sort oldest first. Rotation is a rename done while holding the
 * exclusive lock on current.ndjson, so it can never cut a request's lines.
 *
 * Files are created 0600 and folders 0700 by default (only the user PHP runs
 * as); spool.file_mode / dir_mode / group widen that to a shared group when
 * the web server and the scheduler run as different users.
 */
class SpoolDirectory
{
    const CURRENT = 'current.ndjson';
    const BATCH_GLOB = 'batch-*.ndjson';
    const FAILED_DIR = 'failed';
    const LAST_RUN = 'last-run.json';
    const LOCK = '.lock';

    const DEFAULT_FILE_MODE = 0600;
    const DEFAULT_DIR_MODE = 0700;

    /** Seconds a writer waits for the lock on current.ndjson before dropping its lines. */
    const WRITE_LOCK_SECONDS = 0.1;

    /** Seconds the shipper waits for a batch or current.ndjson to be free. */
    const READ_LOCK_SECONDS = 1.0;

    /** @var string */
    private $path;

    /** @var int */
    private $fileMode;

    /** @var int */
    private $dirMode;

    /** @var string|null */
    private $group;

    public function __construct(string $path, int $fileMode = self::DEFAULT_FILE_MODE, int $dirMode = self::DEFAULT_DIR_MODE, ?string $group = null)
    {
        $this->path = rtrim($path, '/\\');
        $this->fileMode = $fileMode & 0666;
        $this->dirMode = ($dirMode & 0777) | 0700;
        $this->group = $group !== null && $group !== '' ? $group : null;
    }

    /**
     * @param mixed $mode 0600, "0600" or "600"
     */
    public static function parseMode($mode, int $default): int
    {
        if (is_int($mode) && $mode > 0) {
            return $mode;
        }
        if (is_string($mode) && preg_match('/^0?[0-7]{3}$/', trim($mode)) === 1) {
            return (int) octdec(trim($mode));
        }

        return $default;
    }

    public function fileMode(): int
    {
        return $this->fileMode;
    }

    /**
     * Give a file or folder the configured mode (and group). Only the owner
     * can do this; failures are ignored.
     */
    public function protect(string $path, bool $directory = false): void
    {
        $mode = $directory ? $this->dirMode : $this->fileMode;
        clearstatcache(true, $path);
        $perms = @fileperms($path);
        if ($perms !== false && ($perms & 0777) !== $mode) {
            @chmod($path, $mode);
        }
        if ($this->group !== null) {
            @chgrp($path, $this->group);
        }
    }

    /**
     * Bring everything in the spool (written by an older version, or before
     * the modes were changed) to the configured modes. Run by log-monitor:ship.
     */
    public function tighten(): void
    {
        if (!is_dir($this->path)) {
            return;
        }
        $this->protect($this->path, true);
        foreach (glob($this->path . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            $this->protect($file, is_dir($file));
        }
        foreach (glob($this->failedPath() . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            $this->protect($file);
        }
    }

    /**
     * Create a file with the configured mode (none of its bytes are ever
     * readable more widely, even for a moment).
     *
     * @return resource|false
     */
    public function open(string $path, string $mode)
    {
        $old = umask(0777 & ~$this->fileMode);
        try {
            return @fopen($path, $mode);
        } finally {
            umask($old);
        }
    }

    /**
     * Take an exclusive lock, waiting at most $seconds. Never blocks for
     * longer: a stuck process holding the lock cannot stall a request.
     *
     * @param resource $handle
     */
    public static function lockWithin($handle, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                return true;
            }
            usleep(5000);
        } while (microtime(true) < $deadline);

        return false;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function currentPath(): string
    {
        return $this->path . DIRECTORY_SEPARATOR . self::CURRENT;
    }

    public function failedPath(): string
    {
        return $this->path . DIRECTORY_SEPARATOR . self::FAILED_DIR;
    }

    public function ensure(): bool
    {
        if (is_dir($this->path)) {
            return true;
        }

        return $this->makeDirectory($this->path);
    }

    private function makeDirectory(string $path): bool
    {
        // Parents (storage/app/log-monitor, which also holds the
        // log-monitor:off flag) stay traversable; only the spool is private.
        $parent = dirname($path);
        if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
            return false;
        }
        $old = umask(0777 & ~$this->dirMode);
        try {
            if (!@mkdir($path, $this->dirMode) && !is_dir($path)) {
                return false;
            }
        } finally {
            umask($old);
        }
        $this->protect($path, true);

        return true;
    }

    public function newBatchPath(): string
    {
        try {
            $suffix = bin2hex(random_bytes(2));
        } catch (\Throwable $e) {
            $suffix = substr(md5(uniqid('', true)), 0, 4);
        }

        return $this->path . DIRECTORY_SEPARATOR . 'batch-' . gmdate('Ymd-His') . '-' . $suffix . '.ndjson';
    }

    /**
     * Rename current.ndjson to a new batch file if it has content. Returns
     * the batch path, or null when there was nothing to rotate.
     */
    public function rotateCurrent(): ?string
    {
        $current = $this->currentPath();
        clearstatcache(true, $current);
        if (!is_file($current) || (int) @filesize($current) === 0) {
            return null;
        }

        $handle = @fopen($current, 'ab');
        if ($handle === false) {
            return null;
        }
        try {
            if (!self::lockWithin($handle, self::READ_LOCK_SECONDS)) {
                return null; // busy: rotated on the next run
            }
            if (!self::handleMatchesPath($handle, $current)) {
                return null; // someone else rotated it meanwhile
            }
            $stat = fstat($handle);
            if ($stat === false || (int) $stat['size'] === 0) {
                return null;
            }

            return $this->rotateLocked($current);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * Rename while the caller holds LOCK_EX on $current.
     */
    public function rotateLocked(string $current): ?string
    {
        $batch = $this->newBatchPath();
        if (!@rename($current, $batch)) {
            Report::error('could not rotate spool file ' . $current);

            return null;
        }

        return $batch;
    }

    /**
     * @return array<int, string> Batch paths, oldest first.
     */
    public function batches(): array
    {
        $files = glob($this->path . DIRECTORY_SEPARATOR . self::BATCH_GLOB);
        if (!is_array($files)) {
            return [];
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return array<int, string>
     */
    public function failedBatches(): array
    {
        $files = glob($this->failedPath() . DIRECTORY_SEPARATOR . self::BATCH_GLOB);

        return is_array($files) ? $files : [];
    }

    /**
     * Enforce the age and total size caps over batches and quarantined
     * batches together. Quarantined ones go first (they will never be
     * sent), then the oldest batches. Returns the number of files dropped.
     * Skipped when another process is pruning at the same moment.
     */
    public function prune(int $maxTotalBytes, int $maxAgeDays): int
    {
        return (int) $this->exclusively(function () use ($maxTotalBytes, $maxAgeDays) {
            $dropped = 0;
            $cutoff = $maxAgeDays > 0 ? time() - $maxAgeDays * 86400 : null;

            // Oldest first within each group; failed files before batches.
            $failed = $this->failedBatches();
            sort($failed, SORT_STRING);
            $sizes = [];
            foreach (array_merge($failed, $this->batches()) as $file) {
                $time = self::batchTime($file);
                if ($time === null) {
                    $time = @filemtime($file) ?: null;
                }
                if ($cutoff !== null && $time !== null && $time < $cutoff) {
                    $this->delete($file);
                    $dropped++;
                    continue;
                }
                $sizes[$file] = (int) @filesize($file);
            }

            if ($maxTotalBytes > 0) {
                $total = array_sum($sizes);
                foreach ($sizes as $file => $size) {
                    if ($total <= $maxTotalBytes) {
                        break;
                    }
                    $this->delete($file);
                    $total -= $size;
                    $dropped++;
                }
            }

            if ($dropped > 0) {
                Report::error(sprintf('spool limits reached: dropped %d oldest batch file(s)', $dropped));
            }

            return $dropped;
        }, 0);
    }

    /**
     * Delete everything waiting: current.ndjson, batches, their progress
     * files and quarantined batches. Returns the number of files deleted.
     */
    public function purge(): int
    {
        $removed = 0;
        foreach (array_merge([$this->currentPath()], $this->batches(), $this->failedBatches()) as $file) {
            if (is_file($file)) {
                $this->delete($file);
                $removed++;
            }
        }
        foreach (glob($this->path . DIRECTORY_SEPARATOR . '*.progress') ?: [] as $orphan) {
            @unlink($orphan);
        }

        return $removed;
    }

    /**
     * @return array{count: int, bytes: int, oldest: ?string}
     */
    public function failedSummary(): array
    {
        $failed = $this->failedBatches();
        sort($failed, SORT_STRING);
        $bytes = 0;
        foreach ($failed as $file) {
            $bytes += (int) @filesize($file);
        }

        return ['count' => count($failed), 'bytes' => $bytes, 'oldest' => $failed !== [] ? basename($failed[0]) : null];
    }

    /**
     * Run $work while holding the spool-wide lock, or return $busy at once
     * when another process holds it.
     *
     * @param mixed $busy
     * @return mixed
     */
    public function exclusively(callable $work, $busy = null)
    {
        if (!$this->ensure()) {
            return $busy;
        }
        $lock = $this->path . DIRECTORY_SEPARATOR . self::LOCK;
        $handle = $this->open($lock, 'cb');
        if ($handle === false) {
            return $work();
        }
        try {
            if (!@flock($handle, LOCK_EX | LOCK_NB)) {
                return $busy;
            }

            return $work();
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    public function delete(string $batch): void
    {
        @unlink($batch);
        @unlink($batch . '.progress');
    }

    public function quarantine(string $batch): void
    {
        $failed = $this->failedPath();
        if (!is_dir($failed)) {
            $this->makeDirectory($failed);
        }
        if (!@rename($batch, $failed . DIRECTORY_SEPARATOR . basename($batch))) {
            $this->delete($batch);
        }
        @unlink($batch . '.progress');
    }

    /**
     * @return array{sent: int, failures: int, last_error: string|null}
     */
    public function progress(string $batch): array
    {
        $default = ['sent' => 0, 'failures' => 0, 'last_error' => null];
        $raw = @file_get_contents($batch . '.progress');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return $default;
        }

        return [
            'sent' => max(0, (int) ($data['sent'] ?? 0)),
            'failures' => max(0, (int) ($data['failures'] ?? 0)),
            'last_error' => isset($data['last_error']) ? (string) $data['last_error'] : null,
        ];
    }

    /**
     * @param array{sent: int, failures: int, last_error: string|null} $progress
     */
    public function saveProgress(string $batch, array $progress): void
    {
        $this->atomicWrite($batch . '.progress', (string) json_encode($progress));
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function saveLastRun(array $summary): void
    {
        if ($this->ensure()) {
            $this->atomicWrite($this->path . DIRECTORY_SEPARATOR . self::LAST_RUN, (string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastRun(): ?array
    {
        $raw = @file_get_contents($this->path . DIRECTORY_SEPARATOR . self::LAST_RUN);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    /**
     * Read a batch once any writer that still holds it has finished: take the
     * exclusive lock (writers that opened current.ndjson just before the
     * rotation append under the same lock). Null when it stays busy for
     * READ_LOCK_SECONDS; the batch is then read on the next run.
     */
    public function read(string $batch): ?string
    {
        $handle = @fopen($batch, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            if (!self::lockWithin($handle, self::READ_LOCK_SECONDS)) {
                return null;
            }
            $content = stream_get_contents($handle);

            return is_string($content) ? $content : null;
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    public static function batchTime(string $batch): ?int
    {
        if (!preg_match('/batch-(\d{8})-(\d{6})-/', basename($batch), $m)) {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat('Ymd His', $m[1] . ' ' . $m[2], new \DateTimeZone('UTC'));

        return $time === false ? null : $time->getTimestamp();
    }

    /**
     * True when the open handle still refers to the file at $path (it was not
     * renamed away between fopen() and flock()).
     *
     * @param resource $handle
     */
    public static function handleMatchesPath($handle, string $path): bool
    {
        clearstatcache(true, $path);
        $open = @fstat($handle);
        $onDisk = @stat($path);
        if ($open === false || $onDisk === false) {
            return false;
        }

        return (int) $open['ino'] === (int) $onDisk['ino'] && (int) $open['dev'] === (int) $onDisk['dev'];
    }

    private function atomicWrite(string $path, string $content): void
    {
        $temp = $path . '.' . getmypid() . '.tmp';
        $handle = $this->open($temp, 'wb');
        if ($handle !== false) {
            $written = @fwrite($handle, $content);
            @fclose($handle);
            $this->protect($temp);
            if ($written === strlen($content) && @rename($temp, $path)) {
                return;
            }
        }
        @unlink($temp);
    }
}
