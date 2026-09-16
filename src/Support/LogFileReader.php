<?php

namespace DevZone\LogMonitor\Support;

/**
 * Reads new bytes from a log file starting at a stored offset.
 *
 * Read-only: the file is opened with "rb" and never locked, truncated or
 * written. A trailing partial line (no newline yet) is left for the next run
 * so an incomplete JSON object is never parsed.
 */
final class LogFileReader
{
    const DEFAULT_MAX_CHUNK_BYTES = 2097152; // 2 MB
    const READ_BUFFER = 65536;

    /** @var int */
    private $maxChunkBytes;

    public function __construct(int $maxChunkBytes = self::DEFAULT_MAX_CHUNK_BYTES)
    {
        $this->maxChunkBytes = max(1024, $maxChunkBytes);
    }

    /**
     * @param int|null $maxBytes Optional per-call budget; the chunk is capped
     *                           at min(maxBytes, configured chunk size).
     */
    public function read(string $path, int $offset, ?int $maxBytes = null): LogChunk
    {
        $offset = max(0, $offset);
        $chunkCap = $maxBytes === null ? $this->maxChunkBytes : max(1, min($this->maxChunkBytes, $maxBytes));

        clearstatcache(true, $path);
        $size = @filesize($path);
        if ($size === false || !is_file($path)) {
            return new LogChunk($offset, [], $offset, 0, false);
        }

        $reset = false;
        if ($size < $offset) {
            // Rotation or truncation: the file is shorter than where we left off.
            $offset = 0;
            $reset = true;
        }

        if ($size === $offset) {
            return new LogChunk($offset, [], $offset, $size, $reset);
        }

        $chunk = $this->readBytes($path, $offset, min($chunkCap, $size - $offset));
        if ($chunk === null || $chunk === '') {
            return new LogChunk($offset, [], $offset, $size, $reset);
        }

        $lastNewline = strrpos($chunk, "\n");
        if ($lastNewline === false) {
            if (strlen($chunk) >= $this->maxChunkBytes) {
                // Only the configured chunk size, never a smaller per-call
                // budget, may declare a line oversized.
                // A single line larger than the chunk cap can never be parsed.
                // Skip past it; the remainder will be a malformed line and is
                // dropped silently by the parser.
                error_log(sprintf(
                    '[log-monitor] %s: line longer than %d bytes at offset %d skipped',
                    basename($path),
                    $this->maxChunkBytes,
                    $offset
                ));

                return new LogChunk($offset, [], $offset + strlen($chunk), $size, $reset);
            }

            // Only a partial line so far; wait for the writer to finish it.
            return new LogChunk($offset, [], $offset, $size, $reset);
        }

        $complete = substr($chunk, 0, $lastNewline);
        $lines = [];
        $position = $offset;
        foreach (explode("\n", $complete) as $line) {
            $position += strlen($line) + 1;
            $lines[] = [$line, $position];
        }

        return new LogChunk($offset, $lines, $position, $size, $reset);
    }

    private function readBytes(string $path, int $offset, int $length): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                return null;
            }

            $chunk = '';
            $remaining = $length;
            while ($remaining > 0 && !feof($handle)) {
                $buffer = fread($handle, min(self::READ_BUFFER, $remaining));
                if ($buffer === false || $buffer === '') {
                    break;
                }
                $chunk .= $buffer;
                $remaining -= strlen($buffer);
            }

            return $chunk;
        } finally {
            fclose($handle);
        }
    }
}
