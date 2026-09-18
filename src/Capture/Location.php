<?php

namespace DevZone\LogMonitor\Capture;

/**
 * Finds the first stack frame in the application's own code, skipping the
 * framework, vendor packages and this package. Costs a debug_backtrace, so
 * it is only called for slow and repeated queries by default.
 */
class Location
{
    /** @var string */
    private $basePath;

    /** @var string */
    private $vendorPath;

    /** @var string */
    private $packagePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR;
        $this->vendorPath = self::vendorDirectory() ?? $this->basePath . 'vendor' . DIRECTORY_SEPARATOR;
        $this->packagePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
    }

    /**
     * @return array{0: string, 1: int}|null [relative file, line]
     */
    public function find(): ?array
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60);
        foreach ($frames as $frame) {
            if (!isset($frame['file'], $frame['line'])) {
                continue;
            }
            $located = $this->fromFrame((string) $frame['file'], (int) $frame['line']);
            if ($located !== null) {
                return $located;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    public function fromFrame(string $file, int $line): ?array
    {
        if (strpos($file, $this->vendorPath) === 0 || strpos($file, $this->packagePath) === 0) {
            return null;
        }

        return [$this->relative($file), $line];
    }

    /**
     * The Composer vendor directory actually in use, which is not always
     * base_path('vendor') (monorepos, custom vendor-dir).
     */
    private static function vendorDirectory(): ?string
    {
        if (!class_exists(\Composer\Autoload\ClassLoader::class, false)) {
            return null;
        }
        try {
            $file = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        } catch (\Throwable $e) {
            return null;
        }

        return is_string($file) ? dirname($file, 2) . DIRECTORY_SEPARATOR : null;
    }

    public function relative(string $file): string
    {
        return strpos($file, $this->basePath) === 0 ? substr($file, strlen($this->basePath)) : $file;
    }
}
