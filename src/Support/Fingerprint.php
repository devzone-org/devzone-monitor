<?php

namespace DevZone\LogMonitor\Support;

/**
 * Stable grouping keys so repeat occurrences of the same problem group
 * together on the server regardless of ids, amounts or memory addresses.
 */
final class Fingerprint
{
    const NORMALISED_MAX_LENGTH = 200;

    public static function log(string $level, string $message): string
    {
        return md5(strtolower($level) . '|' . self::normalise($message));
    }

    public static function exception(string $class, string $file, int $line): string
    {
        return md5($class . '|' . $file . ':' . $line);
    }

    public static function route(string $method, string $route): string
    {
        return md5(strtoupper($method) . '|' . $route);
    }

    /**
     * Digits become N, hex addresses and hashes become HEX, whitespace is
     * collapsed and the result is capped.
     */
    public static function normalise(string $message): string
    {
        $normalised = @preg_replace('/0x[0-9a-fA-F]+/', 'HEX', $message);
        $normalised = @preg_replace('/\b(?=[0-9a-fA-F]*[a-fA-F])[0-9a-fA-F]{8,}\b/', 'HEX', (string) $normalised);
        $normalised = @preg_replace('/\d+/', 'N', (string) $normalised);
        $normalised = @preg_replace('/\s+/', ' ', trim((string) $normalised));

        return Text::limit((string) $normalised, self::NORMALISED_MAX_LENGTH);
    }
}
