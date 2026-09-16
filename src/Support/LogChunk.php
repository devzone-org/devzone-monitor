<?php

namespace DevZone\LogMonitor\Support;

/**
 * Result of one LogFileReader::read() pass.
 */
final class LogChunk
{
    /** @var int Byte offset the read started from (0 after a truncation reset). */
    public $start;

    /**
     * Complete lines only. Each element is [line, endOffset] where endOffset is
     * the byte position just past that line's newline.
     *
     * @var array<int, array{0: string, 1: int}>
     */
    public $lines;

    /** @var int Offset just past the last complete line; equals $start when nothing usable was read. */
    public $end;

    /** @var int File size at the time of the read. */
    public $size;

    /** @var bool True when the stored offset exceeded the file size (rotation / truncation). */
    public $reset;

    /**
     * @param array<int, array{0: string, 1: int}> $lines
     */
    public function __construct(int $start, array $lines, int $end, int $size, bool $reset)
    {
        $this->start = $start;
        $this->lines = $lines;
        $this->end = $end;
        $this->size = $size;
        $this->reset = $reset;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }
}
