<?php

namespace DevZone\LogMonitor\Capture;

/**
 * Makes SQL safe to store and stable to group:
 *  - comments are removed (they can carry anything)
 *  - string literals of every dialect become ?: 'single', MySQL "double",
 *    E'escaped', N'unicode', X'hex', B'bits', _utf8mb4'x', U&'x',
 *    PostgreSQL $$dollar$$ and $tag$dollar$tag$ strings
 *  - bare numbers and 0x hex literals become ?
 *  - IN (?, ?, ?) lists collapse to IN (...)
 *  - multi-row VALUES (...), (...) collapse to VALUES (...)
 *  - whitespace is collapsed
 * Identifiers are left alone, including quoted ones and ones with digits.
 *
 * Masking happens on the whole statement before anything is cut. SQL that
 * cannot be tokenised with confidence (an unclosed quote or comment) or
 * that is longer than MAX_INPUT is replaced with a note, never stored raw.
 */
final class SqlNormalizer
{
    /** Normalised SQL is cut to this many characters (after masking). */
    const MAX_LENGTH = 10000;

    /** Statements longer than this are not parsed at all. */
    const MAX_INPUT = 131072;

    const UNPARSED = '[sql not kept: could not be parsed safely]';
    const TOO_LONG = '[sql not kept: statement over 128 KB]';

    /** @var array<string, string> driver => tokenizer pattern */
    private static $patterns = [];

    public static function normalize(string $sql, string $driver = ''): string
    {
        if (strlen($sql) > self::MAX_INPUT) {
            return self::TOO_LONG;
        }
        $driver = self::dialect($driver);

        // Quoted identifiers are set aside while the text is checked, so
        // their quotes are not mistaken for unclosed literals.
        $kept = [];
        $out = @preg_replace_callback(self::pattern($driver), function (array $m) use (&$kept) {
            if (isset($m['keep']) && $m['keep'] !== '') {
                $kept[] = $m['keep'];

                return "\x01" . (count($kept) - 1) . "\x02";
            }
            if (isset($m['comment']) && $m['comment'] !== '') {
                return ' ';
            }

            return '?';
        }, $sql);
        if (!is_string($out)) {
            return self::UNPARSED; // backtrack or JIT limit: never fall back to the raw text
        }

        // Anything that opens a literal or comment and survived was never
        // closed: the statement was cut or is not SQL we understand.
        $stray = [
            'mysql' => '/[\'"`]|\/\*/',
            'other' => '/[\'"`]|\/\*|(?<![\w$])\$(?:[A-Za-z_]\w*)?\$/',
            'pgsql' => '/[\'"]|\/\*|(?<![\w$])\$(?:[A-Za-z_]\w*)?\$/',
            'sqlite' => '/[\'"`]|\/\*/',
            'sqlsrv' => '/[\'"]|\/\*/',
        ][$driver];
        if (preg_match($stray, $out) === 1) {
            return self::UNPARSED;
        }

        $out = @preg_replace(
            [
                '/\bin\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i',
                '/\bvalues\s*\([^()]*\)(?:\s*,\s*\([^()]*\))+/i',
                '/\s+/',
            ],
            ['in (...)', 'values (...)', ' '],
            $out
        );
        if (!is_string($out)) {
            return self::UNPARSED;
        }
        $out = trim($out);
        if ($kept !== []) {
            $out = preg_replace_callback('/\x01(\d+)\x02/', function (array $m) use ($kept) {
                return $kept[(int) $m[1]] ?? '';
            }, $out) ?? self::UNPARSED;
        }

        if (strlen($out) > self::MAX_LENGTH) {
            $out = rtrim(substr($out, 0, self::MAX_LENGTH)) . ' …';
        }

        return $out;
    }

    public static function hash(string $connection, string $normalized): string
    {
        return substr(md5($connection . '|' . $normalized), 0, 12);
    }

    /**
     * mysql (MySQL, MariaDB), pgsql, sqlite, sqlsrv, or "other" when the
     * driver is unknown (then every quoting style is treated as a literal
     * where that is the safer reading).
     */
    private static function dialect(string $driver): string
    {
        $driver = strtolower($driver);
        if ($driver === 'mysql' || $driver === 'mariadb' || $driver === 'singlestore') {
            return 'mysql';
        }
        if ($driver === 'pgsql' || $driver === 'postgres' || $driver === 'postgresql') {
            return 'pgsql';
        }
        if ($driver === 'sqlite' || $driver === 'sqlsrv') {
            return $driver;
        }

        return 'other';
    }

    private static function pattern(string $driver): string
    {
        if (isset(self::$patterns[$driver])) {
            return self::$patterns[$driver];
        }

        $prefix = "(?:\\b(?:[EeNnXxBb]|_[A-Za-z0-9]+)|\\bU&)?";
        // MySQL (and unknown drivers) treat backslash as an escape inside
        // 'strings'; standard SQL only doubles the quote, but PostgreSQL
        // E'...' strings use backslashes too.
        $backslashSingle = "'(?:[^'\\\\]++|\\\\.|'')*+'";
        $standardSingle = "'(?:[^']++|'')*+'";

        $parts = [
            // Comments. MySQL also uses # to end of line; so may an unknown driver.
            '(?<comment>--[^\r\n]*+|\/\*.*?\*\/' . ($driver === 'mysql' || $driver === 'other' ? '|#[^\r\n]*+' : '') . ')',
        ];

        if ($driver === 'mysql' || $driver === 'other') {
            $parts[] = '(?<str>' . $prefix . $backslashSingle . ')';
        } else {
            $parts[] = '(?<estr>\b[Ee]' . $backslashSingle . ')';
            $parts[] = '(?<str>' . $prefix . $standardSingle . ')';
        }

        if ($driver === 'pgsql' || $driver === 'other') {
            // $$...$$ and $tag$...$tag$, not $1 placeholders.
            $parts[] = '(?<dollar>(?<![\w$])\$(?<tag>(?:[A-Za-z_]\w*)?)\$.*?\$\k<tag>\$)';
        }

        // Quoted identifiers are kept as written.
        $identifiers = [
            'mysql' => ['`(?:[^`]++|``)*+`'],
            'other' => ['`(?:[^`]++|``)*+`'],
            'pgsql' => ['"(?:[^"]++|"")*+"'],
            'sqlite' => ['"(?:[^"]++|"")*+"', '`(?:[^`]++|``)*+`', '\[[^\]\r\n]*+\]'],
            'sqlsrv' => ['"(?:[^"]++|"")*+"', '\[[^\]\r\n]*+\]'],
        ];
        $parts[] = '(?<keep>' . implode('|', $identifiers[$driver]) . ')';

        if ($driver === 'mysql' || $driver === 'other') {
            // Double quotes are strings in MySQL. For unknown drivers a
            // quoted identifier masked by mistake only costs readability.
            $parts[] = '(?<dstr>"(?:[^"\\\\]++|\\\\.|"")*+")';
        }

        // Numbers not part of an identifier or a $1 placeholder.
        $parts[] = '(?<num>(?<![\w$.])-?(?:0x[0-9a-f]++|\d++(?:\.\d++)?(?:e[+-]?\d++)?|\.\d++)(?!\w))';

        return self::$patterns[$driver] = '/' . implode('|', $parts) . '/is';
    }
}
