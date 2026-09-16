<?php

namespace DevZone\LogMonitor\Support;

/**
 * Restricts readable files to a single root directory (storage/logs).
 *
 * Every configured pattern and every globbed file is resolved with realpath()
 * and must sit inside the root, so "../" segments and symlinks pointing
 * elsewhere are rejected.
 */
final class PathGuard
{
    /** @var string */
    private $root;

    public function __construct(string $root)
    {
        $real = realpath($root);
        $this->root = rtrim($real === false ? $root : $real, DIRECTORY_SEPARATOR);
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Expand glob patterns into readable regular files inside the root.
     * Rejected patterns and files are reported via error_log and dropped.
     *
     * @param array<int, string> $patterns
     * @return array<int, string> Sorted, de-duplicated real paths.
     */
    public function files(array $patterns): array
    {
        $files = [];
        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }
            if (!$this->isPatternAllowed($pattern)) {
                error_log('[log-monitor] refusing to read outside ' . $this->root . ': ' . $pattern);
                continue;
            }
            $matches = glob($pattern, GLOB_NOSORT);
            if (!is_array($matches)) {
                continue;
            }
            foreach ($matches as $match) {
                $real = realpath($match);
                if ($real === false || !$this->isAllowed($real)) {
                    error_log('[log-monitor] refusing to read outside ' . $this->root . ': ' . $match);
                    continue;
                }
                $files[$real] = true;
            }
        }

        $paths = array_keys($files);
        sort($paths, SORT_STRING);

        return $paths;
    }

    public function isPatternAllowed(string $pattern): bool
    {
        if (strpos($pattern, '..') !== false || strpos($pattern, "\0") !== false) {
            return false;
        }
        $dir = realpath(dirname($pattern));
        if ($dir === false) {
            return false;
        }

        return $this->isInsideRoot($dir);
    }

    public function isAllowed(string $path): bool
    {
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            return false;
        }

        return $this->isInsideRoot($real);
    }

    private function isInsideRoot(string $realPath): bool
    {
        $root = $this->root . DIRECTORY_SEPARATOR;
        $candidate = rtrim($realPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return strncmp($candidate, $root, strlen($root)) === 0;
    }
}
