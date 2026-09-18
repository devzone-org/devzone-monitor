<?php

namespace DevZone\LogMonitor\Support;

/**
 * Monolog / RFC 5424 level names and their numeric severities.
 */
final class Levels
{
    const MAP = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
        'alert' => 550,
        'emergency' => 600,
    ];

    public static function severity(string $level): ?int
    {
        $level = strtolower(trim($level));

        return self::MAP[$level] ?? null;
    }

    /**
     * Whether $level is at or above $minimum. Unknown levels pass so nothing
     * is silently dropped because of an unusual level name.
     */
    public static function passes(string $level, string $minimum): bool
    {
        $severity = self::severity($level);
        $threshold = self::severity($minimum);
        if ($severity === null || $threshold === null) {
            return true;
        }

        return $severity >= $threshold;
    }
}
