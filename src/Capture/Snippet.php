<?php

namespace DevZone\LogMonitor\Capture;

use DevZone\LogMonitor\Support\Redactor;

/**
 * A few lines of source around the line that threw, so the monitor can show
 * the statement itself rather than only a file and a line number.
 *
 * Read only when something throws, so a healthy request never pays for it.
 * This is the one place where the package sends code rather than data, so it
 * stays narrow: only the application's own PHP files (never vendor code,
 * never anything outside the project), at most a few short lines, and values
 * that look like credentials are masked the way log context is. Turn it off
 * with exceptions.snippets when code may not leave the server.
 */
final class Snippet
{
    /** @var int Longest line kept; longer lines are cut. */
    private const MAX_LINE = 200;

    /** @var int Files larger than this are not opened. */
    private const MAX_FILE_BYTES = 2097152;

    /** @var int Most lines either side, whatever the setting says. */
    private const MAX_CONTEXT = 10;

    /** @var string */
    private $basePath;

    /** @var array<int, string> Directories never read from. */
    private $vendorPaths;

    /** @var Redactor */
    private $redactor;

    public function __construct(Location $location, Redactor $redactor)
    {
        // Compared against realpath()ed files, so the project's own path is
        // resolved too: /tmp and /var are symlinks on macOS, and a release
        // directory is often one on a deployed server.
        $this->basePath = self::resolve($location->basePath());
        // The vendor directory in use, and the conventional one: a package
        // installed elsewhere (monorepo, custom vendor-dir) must not make
        // the project's own vendor/ readable.
        $this->vendorPaths = array_unique([
            self::resolve($location->vendorPath()),
            $this->basePath . 'vendor' . DIRECTORY_SEPARATOR,
        ]);
        $this->redactor = $redactor;
    }

    private static function resolve(string $path): string
    {
        $real = @realpath(rtrim($path, '/\\'));

        return ($real === false ? rtrim($path, '/\\') : $real) . DIRECTORY_SEPARATOR;
    }

    /**
     * @param string $file Absolute path, as the exception reported it.
     * @return array{start: int, line: int, lines: array<int, string>}|null
     *         start = number of the first line returned; line = the one that
     *         threw. Null when the file may not or cannot be read.
     */
    public function read(string $file, int $line, int $context): ?array
    {
        try {
            if ($line < 1 || !$this->allowed($file)) {
                return null;
            }

            $context = max(1, min($context, self::MAX_CONTEXT));
            $first = max(1, $line - $context);
            $last = $line + $context;

            $lines = [];
            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                return null;
            }
            try {
                $number = 0;
                while (($text = fgets($handle)) !== false) {
                    $number++;
                    if ($number < $first) {
                        continue;
                    }
                    if ($number > $last) {
                        break;
                    }
                    $lines[] = $this->clean($text);
                }
            } finally {
                fclose($handle);
            }

            if ($lines === [] || $number < $line) {
                return null;
            }

            return ['start' => $first, 'line' => $line, 'lines' => $lines];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Only the application's own PHP source: nothing from vendor, nothing
     * outside the project, no symlink pointing out of it, and no file so
     * large that reading it would cost the request anything.
     */
    private function allowed(string $file): bool
    {
        if (substr($file, -4) !== '.php' || strpos($file, "\0") !== false) {
            return false;
        }

        $real = @realpath($file);
        if ($real === false || !@is_file($real) || !@is_readable($real)) {
            return false;
        }
        if (strpos($real, $this->basePath) !== 0) {
            return false;
        }
        foreach ($this->vendorPaths as $vendor) {
            if (strpos($real, $vendor) === 0) {
                return false;
            }
        }

        $size = @filesize($real);

        return $size !== false && $size <= self::MAX_FILE_BYTES;
    }

    /**
     * One line of source, masked exactly as a log message is: tokens in URLs,
     * connection strings and the other patterns the redactor knows, plus any
     * literal sitting behind a secret-looking name. A credential written into
     * the code never ships with the snippet.
     */
    private function clean(string $text): string
    {
        $text = str_replace("\t", '    ', rtrim($text, "\r\n"));
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = $this->redactor->redactString((string) $text);

        // name = "value", 'name' => "value", "name": "value"
        $text = preg_replace_callback(
            '/([\'"]?)([A-Za-z0-9_\-.]{2,40})\1(\s*(?:=>|=|:)\s*)([\'"])(.*?)\4/',
            function (array $m) {
                return $m[5] !== '' && $this->redactor->isSecretName($m[2])
                    ? $m[1] . $m[2] . $m[1] . $m[3] . $m[4] . Redactor::DEFAULT_REPLACEMENT . $m[4]
                    : $m[0];
            },
            $text
        );
        $text = (string) $text;

        if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > self::MAX_LINE) {
            return mb_substr($text, 0, self::MAX_LINE, 'UTF-8') . '…';
        }

        return strlen($text) > self::MAX_LINE * 4 ? substr($text, 0, self::MAX_LINE) . '…' : $text;
    }
}
