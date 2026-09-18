<?php

namespace DevZone\LogMonitor\Support;

/**
 * The package's own error channel. Never Log:: (that would feed the
 * package's own log capture); error_log() only, and each distinct message
 * at most once per process so a broken setup cannot flood the PHP error log.
 */
final class Report
{
    /** @var array<string, bool> */
    private static $seen = [];

    /** @var int */
    const MAX_DISTINCT = 50;

    public static function error(string $message, ?\Throwable $e = null, ?string $secret = null): void
    {
        try {
            $text = $e === null ? $message : $message . ': ' . get_class($e) . ': ' . $e->getMessage();
            if ($secret !== null && $secret !== '') {
                $text = str_replace($secret, '[REDACTED]', $text);
            }
            $key = md5($text);
            if (isset(self::$seen[$key]) || count(self::$seen) >= self::MAX_DISTINCT) {
                return;
            }
            self::$seen[$key] = true;
            error_log('[log-monitor] ' . $text);
        } catch (\Throwable $ignored) {
            // Reporting must never throw.
        }
    }

    public static function reset(): void
    {
        self::$seen = [];
    }
}
