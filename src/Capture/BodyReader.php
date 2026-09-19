<?php

namespace DevZone\LogMonitor\Capture;

use Psr\Http\Message\MessageInterface;

/**
 * Reads at most a few kilobytes of an outgoing call's request or response
 * body without disturbing the application:
 *
 * - never more than $limit bytes, however large the body (a 200 MB download
 *   costs the same as a 2 KB one);
 * - never from a stream that cannot be rewound (Http::withOptions(['stream'
 *   => true]), sinks), because reading it would take the data away from the
 *   code that made the call;
 * - never binary content (files, images, PDFs, archives, uploads), only a
 *   note of its type and size;
 * - the stream is put back where it was, so the application reads the body
 *   exactly as if it had not been looked at.
 */
final class BodyReader
{
    private const BINARY_TYPES = [
        'image/', 'audio/', 'video/', 'font/',
        'application/pdf', 'application/zip', 'application/gzip', 'application/x-gzip',
        'application/octet-stream', 'application/x-tar', 'application/x-7z-compressed',
        'application/vnd.', 'application/msword', 'multipart/',
    ];

    /**
     * @return array{body: string, size: ?int}|null body = text read (at most
     *         $limit bytes) or a bracketed note; size = full body size when
     *         known. Null when there is no body.
     */
    public static function read($message, int $limit): ?array
    {
        try {
            $psr = self::psr($message);
            if ($psr === null) {
                return null;
            }

            $stream = $psr->getBody();
            $size = $stream->getSize();
            if ($size === 0) {
                return null;
            }

            $type = strtolower(trim(explode(';', $psr->getHeaderLine('Content-Type'))[0]));
            if ($type !== '' && self::isBinaryType($type)) {
                return ['body' => self::note("{$type} body not kept", $size), 'size' => $size];
            }

            if (!$stream->isSeekable() || !$stream->isReadable()) {
                return ['body' => self::note('streamed body not kept', $size), 'size' => $size];
            }

            $position = $stream->tell();
            $stream->rewind();
            $data = '';
            while (strlen($data) < $limit && !$stream->eof()) {
                $chunk = $stream->read($limit - strlen($data));
                if ($chunk === '') {
                    break;
                }
                $data .= $chunk;
            }
            $stream->seek($position);

            if ($data === '') {
                return null;
            }
            if (strpos($data, "\0") !== false) {
                return ['body' => self::note('binary body not kept', $size), 'size' => $size];
            }

            return ['body' => $data, 'size' => $size];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function isBinaryType(string $type): bool
    {
        foreach (self::BINARY_TYPES as $prefix) {
            if (strpos($type, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
    }

    private static function note(string $what, ?int $size): string
    {
        return '[' . $what . ($size !== null ? ', ' . self::formatBytes($size) : '') . ']';
    }

    /**
     * @param mixed $message Illuminate\Http\Client\Request|Response or a PSR-7 message
     */
    private static function psr($message): ?MessageInterface
    {
        if ($message instanceof MessageInterface) {
            return $message;
        }
        if (is_object($message) && method_exists($message, 'toPsrResponse')) {
            return $message->toPsrResponse();
        }
        if (is_object($message) && method_exists($message, 'toPsrRequest')) {
            return $message->toPsrRequest();
        }

        return null;
    }
}
