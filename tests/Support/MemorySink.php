<?php

namespace DevZone\LogMonitor\Tests\Support;

use DevZone\LogMonitor\Capture\RecordSink;

final class MemorySink implements RecordSink
{
    /** @var array<int, array<int, string>> one entry per write() call */
    public $writes = [];

    public function write(array $lines): void
    {
        $this->writes[] = $lines;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function records(?string $type = null): array
    {
        $out = [];
        foreach ($this->writes as $lines) {
            foreach ($lines as $line) {
                $record = json_decode($line, true);
                if ($type === null || ($record['t'] ?? null) === $type) {
                    $out[] = $record;
                }
            }
        }

        return $out;
    }
}
