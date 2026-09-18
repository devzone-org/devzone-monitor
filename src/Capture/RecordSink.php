<?php

namespace DevZone\LogMonitor\Capture;

/**
 * Where finished records go. Each call receives every line of one
 * execution (or one partial flush) and must store them together.
 */
interface RecordSink
{
    /**
     * @param array<int, string> $lines Encoded JSON records, no trailing newlines.
     */
    public function write(array $lines): void;
}
