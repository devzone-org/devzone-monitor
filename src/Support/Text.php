<?php

namespace DevZone\LogMonitor\Support;

final class Text
{
    /**
     * Cut a string to $length characters (multibyte safe when mbstring exists).
     */
    public static function limit(string $value, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') > $length ? mb_substr($value, 0, $length, 'UTF-8') : $value;
        }

        return strlen($value) > $length ? substr($value, 0, $length) : $value;
    }

    /**
     * Cut a string to at most $bytes bytes without splitting a UTF-8 sequence.
     */
    public static function limitBytes(string $value, int $bytes): string
    {
        if (strlen($value) <= $bytes) {
            return $value;
        }
        $cut = substr($value, 0, max(0, $bytes));
        // Drop a trailing partial multibyte sequence.
        return (string) preg_replace('/[\x80-\xBF]*[\xC0-\xFF]?$/', '', $cut) ?: $cut;
    }
}
