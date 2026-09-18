<?php

namespace DevZone\LogMonitor\Capture;

/**
 * Makes SQL safe to store and stable to group:
 *  - quoted string literals and bare numbers become ? (raw SQL written with
 *    values inline would otherwise leak them)
 *  - IN (?, ?, ?) lists collapse to IN (...)
 *  - multi-row VALUES (...), (...) collapse to VALUES (...)
 *  - whitespace is collapsed
 * Identifiers are left alone, including ones that contain digits.
 */
final class SqlNormalizer
{
    const MAX_LENGTH = 10000;

    public static function normalize(string $sql): string
    {
        $sql = substr($sql, 0, self::MAX_LENGTH);

        $out = @preg_replace(
            [
                "/'(?:[^'\\\\]|\\\\.|'')*'/s",             // 'single quoted', with escapes
                '/"(?:[^"\\\\]|\\\\.|"")*"(?=\s*(?:[,)=<>]|$|\s+(?:and|or|then|else|end|limit|order|group)\b))/is', // "double quoted" values (MySQL)
                '/(?<![\w`$.])-?\d+(?:\.\d+)?(?:e[+-]?\d+)?(?![\w`])/i', // numbers not part of identifiers
                '/\bin\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i',
                '/\bvalues\s*\([^()]*\)(?:\s*,\s*\([^()]*\))+/i',
                '/\s+/',
            ],
            ['?', '?', '?', 'in (...)', 'values (...)', ' '],
            $sql
        );

        return trim(is_string($out) ? $out : $sql);
    }

    public static function hash(string $connection, string $normalized): string
    {
        return substr(md5($connection . '|' . $normalized), 0, 12);
    }
}
