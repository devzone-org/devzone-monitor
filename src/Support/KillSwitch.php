<?php

namespace DevZone\LogMonitor\Support;

/**
 * The emergency switch: a flag file. Checked when a request or job starts
 * and before records are written (one stat call, at most every few
 * seconds), so switching off needs no .env edit, no config cache and no
 * worker restart.
 */
final class KillSwitch
{
    public static function isOn(string $path): bool
    {
        return $path !== '' && @file_exists($path);
    }

    public static function turnOn(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        $dir = dirname($path);
        // World-readable on purpose: the web server and the workers must be
        // able to see the flag whoever created it. It holds only a timestamp.
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        return @file_put_contents($path, gmdate('Y-m-d\TH:i:s\Z') . "\n") !== false;
    }

    public static function turnOff(string $path): bool
    {
        return $path === '' || !file_exists($path) || @unlink($path);
    }
}
