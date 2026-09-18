<?php

namespace DevZone\LogMonitor\Support;

/**
 * The emergency switch: a flag file. Checked once per request/command boot
 * (one stat call), so switching off needs no .env edit and no config cache.
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
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        return @file_put_contents($path, gmdate('Y-m-d\TH:i:s\Z') . "\n") !== false;
    }

    public static function turnOff(string $path): bool
    {
        return $path === '' || !file_exists($path) || @unlink($path);
    }
}
