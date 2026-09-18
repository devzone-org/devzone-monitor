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
 */
class SpoolDirectory
{
    const CURRENT = 'current.ndjson';
    const BATCH_GLOB = 'batch-*.ndjson';
    const FAILED_DIR = 'failed';
    const LAST_RUN = 'last-run.json';

    /** @var string */
    private $path;

    public function __construct(string $path)
    {
        $this->path = rtrim($path, '/\\');
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
        $old = umask(0002);
        try {
            return @mkdir($this->path, 0775, true) || is_dir($this->path);
        } finally {
            umask($old);
        }
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
            if (!@flock($handle, LOCK_EX)) {
                return null;
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
     * Enforce the age and total size caps, deleting the oldest batches first.
     * Returns the number of batches dropped.
     */
    public function prune(int $maxTotalBytes, int $maxAgeDays): int
    {
        $dropped = 0;
        $batches = $this->batches();
        $cutoff = $maxAgeDays > 0 ? time() - $maxAgeDays * 86400 : null;

        $sizes = [];
        foreach ($batches as $batch) {
            $time = self::batchTime($batch);
            if ($cutoff !== null && $time !== null && $time < $cutoff) {
                $this->delete($batch);
                $dropped++;
                continue;
            }
            $sizes[$batch] = (int) @filesize($batch);
        }

        if ($maxTotalBytes > 0) {
            $total = array_sum($sizes);
            foreach ($sizes as $batch => $size) {
                if ($total <= $maxTotalBytes) {
                    break;
                }
                $this->delete($batch);
                $total -= $size;
                $dropped++;
            }
        }

        if ($dropped > 0) {
            Report::error(sprintf('spool limits reached: dropped %d oldest batch file(s)', $dropped));
        }

        return $dropped;
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
            @mkdir($failed, 0775, true);
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
        self::atomicWrite($batch . '.progress', (string) json_encode($progress));
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function saveLastRun(array $summary): void
    {
        if ($this->ensure()) {
            self::atomicWrite($this->path . DIRECTORY_SEPARATOR . self::LAST_RUN, (string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
     * rotation append under the same lock).
     */
    public function read(string $batch): ?string
    {
        $handle = @fopen($batch, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            @flock($handle, LOCK_EX);
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

    private static function atomicWrite(string $path, string $content): void
    {
        $temp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temp, $content) === strlen($content)) {
            if (@rename($temp, $path)) {
                return;
            }
        }
        @unlink($temp);
    }
}
