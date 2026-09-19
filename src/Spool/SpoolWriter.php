<?php

namespace DevZone\LogMonitor\Spool;

use DevZone\LogMonitor\Capture\RecordSink;
use DevZone\LogMonitor\Support\Report;

/**
 * Appends one execution's lines to current.ndjson in a single write under
 * an exclusive lock, so lines from concurrent requests never interleave.
 *
 * After taking the lock it checks that the file it opened is still the one
 * at current.ndjson; if a rotation renamed it away in the meantime it
 * reopens, so no line can land in a batch that is already being shipped.
 */
class SpoolWriter implements RecordSink
{
    const ATTEMPTS = 3;

    /** @var SpoolDirectory */
    private $directory;

    /** @var int */
    private $rotateBytes;

    /** @var int */
    private $minFreeDisk;

    /** @var int */
    private $maxTotalBytes;

    /** @var int */
    private $maxAgeDays;

    /** @var bool|null */
    private $ready = null;

    public function __construct(SpoolDirectory $directory, int $rotateBytes, int $minFreeDisk, int $maxTotalBytes, int $maxAgeDays)
    {
        $this->directory = $directory;
        $this->rotateBytes = $rotateBytes;
        $this->minFreeDisk = $minFreeDisk;
        $this->maxTotalBytes = $maxTotalBytes;
        $this->maxAgeDays = $maxAgeDays;
    }

    /**
     * @param array<string, mixed> $spool The "spool" config section.
     */
    public static function fromConfig(array $spool): self
    {
        return new self(
            new SpoolDirectory(
                (string) ($spool['path'] ?? sys_get_temp_dir() . '/log-monitor-spool'),
                SpoolDirectory::parseMode($spool['file_mode'] ?? null, SpoolDirectory::DEFAULT_FILE_MODE),
                SpoolDirectory::parseMode($spool['dir_mode'] ?? null, SpoolDirectory::DEFAULT_DIR_MODE),
                isset($spool['group']) && is_string($spool['group']) ? $spool['group'] : null
            ),
            (int) ($spool['rotate_bytes'] ?? 5 * 1024 * 1024),
            (int) ($spool['min_free_disk'] ?? 500 * 1024 * 1024),
            (int) ($spool['max_total_bytes'] ?? 200 * 1024 * 1024),
            (int) ($spool['max_age_days'] ?? 7)
        );
    }

    public function directory(): SpoolDirectory
    {
        return $this->directory;
    }

    public function write(array $lines): void
    {
        if ($lines === []) {
            return;
        }
        try {
            if ($this->ready === null) {
                $this->ready = $this->directory->ensure();
            }
            if (!$this->ready) {
                Report::error('spool directory is not writable: ' . $this->directory->path());

                return;
            }
            if ($this->minFreeDisk > 0) {
                $free = @disk_free_space($this->directory->path());
                if ($free !== false && $free < $this->minFreeDisk) {
                    Report::error('free disk space below min_free_disk, records dropped');

                    return;
                }
            }

            $data = implode("\n", $lines) . "\n";
            $current = $this->directory->currentPath();

            for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
                $result = $this->appendOnce($current, $data);
                if ($result === false) {
                    Report::error('spool file stayed locked, records dropped');

                    return;
                }
                if ($result !== null) {
                    if ($result !== '') {
                        // This write rotated the file; enforce the caps now in
                        // case the scheduler is not running to do it.
                        $this->directory->prune($this->maxTotalBytes, $this->maxAgeDays);
                    }

                    return;
                }
            }
            Report::error('could not append to spool after ' . self::ATTEMPTS . ' attempts');
        } catch (\Throwable $e) {
            Report::error('spool write failed', $e);
        }
    }

    /**
     * @return string|false|null null to retry, false when the lock could not
     *         be had in time, '' when written, the batch path when written
     *         and rotated
     */
    private function appendOnce(string $current, string $data)
    {
        $handle = $this->directory->open($current, 'ab');
        if ($handle === false) {
            return null;
        }

        try {
            // A request never waits on the spool for more than a moment.
            if (!SpoolDirectory::lockWithin($handle, SpoolDirectory::WRITE_LOCK_SECONDS)) {
                return false;
            }
            if (!SpoolDirectory::handleMatchesPath($handle, $current)) {
                return null; // rotated between fopen and flock: reopen
            }

            $before = @fstat($handle);
            $startSize = $before !== false ? (int) $before['size'] : null;
            if ($before !== false && ((int) $before['mode'] & 0777) !== $this->directory->fileMode()) {
                $this->directory->protect($current); // created by an older version, or before a mode change
            }

            $length = strlen($data);
            $written = 0;
            while ($written < $length) {
                $chunk = @fwrite($handle, substr($data, $written));
                if ($chunk === false || $chunk === 0) {
                    break;
                }
                $written += $chunk;
            }
            $flushed = @fflush($handle);
            if ($written < $length || $flushed === false) {
                // Disk full or I/O error: cut the file back to where this write
                // started so no half-written line is left for the next append
                // to glue onto. These records are lost; earlier ones are intact.
                if ($startSize !== null) {
                    @ftruncate($handle, $startSize);
                    @fflush($handle);
                }
                Report::error('spool write failed (disk full or I/O error), records dropped');

                return '';
            }

            $stat = @fstat($handle);
            if ($this->rotateBytes > 0 && $stat !== false && (int) $stat['size'] >= $this->rotateBytes) {
                return (string) $this->directory->rotateLocked($current);
            }

            return '';
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }
}
