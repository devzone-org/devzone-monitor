<?php

namespace DevZone\LogMonitor\Support;

/**
 * Persists per-file byte offsets in storage/app/log-monitor/state.json.
 *
 * Keyed by file NAME, not full path: Envoyer-style deployments change the
 * release directory on every deploy and a path-keyed store would restart from
 * zero each time. Deliberately not the cache: cache:clear on deploy would
 * re-ship the whole day and the array driver stores nothing.
 *
 * This is the only file the package ever writes. Writes are atomic (temp file
 * then rename) and refused when free disk space is below the configured floor.
 */
final class StateStore
{
    const DEFAULT_MIN_FREE_BYTES = 20971520; // 20 MB

    /** @var string */
    private $path;

    /** @var int */
    private $minFreeBytes;

    /** @var array<string, array{offset: int, size: int, updated_at: string}>|null */
    private $state = null;

    /** @var bool True when in-memory state differs from what is on disk. */
    private $dirty = false;

    public function __construct(string $path, int $minFreeBytes = self::DEFAULT_MIN_FREE_BYTES)
    {
        $this->path = $path;
        $this->minFreeBytes = max(0, $minFreeBytes);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, array{offset: int, size: int, updated_at: string}>
     */
    public function load(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }

        $this->state = [];
        if (!is_file($this->path)) {
            return $this->state;
        }

        $raw = @file_get_contents($this->path);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            error_log('[log-monitor] state file unreadable or corrupt, starting fresh: ' . $this->path);

            return $this->state;
        }

        foreach ($decoded as $name => $entry) {
            if (!is_string($name) || !is_array($entry)) {
                continue;
            }
            $this->state[$name] = [
                'offset' => max(0, (int) ($entry['offset'] ?? 0)),
                'size' => max(0, (int) ($entry['size'] ?? 0)),
                'updated_at' => isset($entry['updated_at']) && is_string($entry['updated_at']) ? $entry['updated_at'] : '',
            ];
        }

        return $this->state;
    }

    public function has(string $filename): bool
    {
        $state = $this->load();

        return isset($state[$filename]);
    }

    public function offset(string $filename): int
    {
        $state = $this->load();

        return isset($state[$filename]) ? $state[$filename]['offset'] : 0;
    }

    public function set(string $filename, int $offset, int $size): void
    {
        $this->load();
        $this->state[$filename] = [
            'offset' => max(0, $offset),
            'size' => max(0, $size),
            'updated_at' => (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP'),
        ];
        $this->dirty = true;
    }

    /**
     * Drop entries whose file no longer exists.
     *
     * @param array<int, string> $existingFilenames
     */
    public function prune(array $existingFilenames): void
    {
        $this->load();
        $keep = array_fill_keys($existingFilenames, true);
        foreach (array_keys($this->state) as $name) {
            if (!isset($keep[$name])) {
                unset($this->state[$name]);
                $this->dirty = true;
            }
        }
    }

    /**
     * Whether a save would currently succeed: directory writable and enough
     * free disk. Checked before shipping so nothing is sent that cannot be
     * recorded.
     */
    public function canWrite(): bool
    {
        $dir = dirname($this->path);
        if (!$this->ensureDirectory($dir) || !is_writable($dir)) {
            return false;
        }
        if (is_file($this->path) && !is_writable($this->path)) {
            return false;
        }

        return $this->hasFreeSpace($dir, 0);
    }

    /**
     * Atomic write: serialise to a temp file in the same directory, then rename.
     */
    public function save(): bool
    {
        $this->load();
        if (!$this->dirty && is_file($this->path)) {
            return true;
        }
        $dir = dirname($this->path);
        if (!$this->ensureDirectory($dir)) {
            error_log('[log-monitor] cannot create state directory: ' . $dir);

            return false;
        }

        $json = json_encode((object) $this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            error_log('[log-monitor] cannot encode state');

            return false;
        }

        if (!$this->hasFreeSpace($dir, strlen($json))) {
            error_log('[log-monitor] refusing to write state: free disk space below ' . $this->minFreeBytes . ' bytes');

            return false;
        }

        $temp = $this->path . '.' . str_replace('.', '', uniqid('tmp', true));
        if (@file_put_contents($temp, $json, LOCK_EX) !== strlen($json)) {
            @unlink($temp);
            error_log('[log-monitor] cannot write state temp file: ' . $temp);

            return false;
        }

        if (!@rename($temp, $this->path)) {
            @unlink($temp);
            error_log('[log-monitor] cannot rename state file into place: ' . $this->path);

            return false;
        }
        $this->dirty = false;

        return true;
    }

    private function ensureDirectory(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }

        return @mkdir($dir, 0755, true) || is_dir($dir);
    }

    private function hasFreeSpace(string $dir, int $needed): bool
    {
        $free = @disk_free_space($dir);
        if ($free === false) {
            // Unknown filesystem; do not block on a metric we cannot read.
            return true;
        }

        return $free >= $this->minFreeBytes + $needed;
    }
}
