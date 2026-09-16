<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\Support\LogFileReader;
use PHPUnit\Framework\TestCase;

final class LogFileReaderTest extends TestCase
{
    /** @var string */
    private $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'lm-reader-');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testReadsCompleteLinesAndLeavesPartialTail(): void
    {
        file_put_contents($this->path, "{\"a\":1}\n{\"b\":2}\n{\"c\":");

        $chunk = (new LogFileReader())->read($this->path, 0);

        $this->assertFalse($chunk->reset);
        $this->assertSame(0, $chunk->start);
        $this->assertCount(2, $chunk->lines);
        $this->assertSame('{"a":1}', $chunk->lines[0][0]);
        $this->assertSame(8, $chunk->lines[0][1]);
        $this->assertSame('{"b":2}', $chunk->lines[1][0]);
        $this->assertSame(16, $chunk->lines[1][1]);
        $this->assertSame(16, $chunk->end);

        // The partial line is completed later and picked up from the saved offset.
        file_put_contents($this->path, "3}\n", FILE_APPEND);
        $next = (new LogFileReader())->read($this->path, $chunk->end);
        $this->assertCount(1, $next->lines);
        $this->assertSame('{"c":3}', $next->lines[0][0]);
        $this->assertSame(24, $next->end);
    }

    public function testNothingNewReturnsEmptyChunk(): void
    {
        file_put_contents($this->path, "{\"a\":1}\n");

        $chunk = (new LogFileReader())->read($this->path, 8);

        $this->assertTrue($chunk->isEmpty());
        $this->assertSame(8, $chunk->end);
        $this->assertFalse($chunk->reset);
    }

    public function testOffsetResetsWhenFileIsTruncated(): void
    {
        file_put_contents($this->path, "{\"fresh\":true}\n");

        $chunk = (new LogFileReader())->read($this->path, 5000);

        $this->assertTrue($chunk->reset);
        $this->assertSame(0, $chunk->start);
        $this->assertCount(1, $chunk->lines);
        $this->assertSame('{"fresh":true}', $chunk->lines[0][0]);
        $this->assertSame(15, $chunk->end);
    }

    public function testChunkIsCappedAtLastNewline(): void
    {
        $line = '{"m":"' . str_repeat('x', 600) . '"}';
        file_put_contents($this->path, $line . "\n" . $line . "\n" . $line . "\n");

        $reader = new LogFileReader(1300); // fits two lines, not three
        $chunk = $reader->read($this->path, 0);

        $this->assertCount(2, $chunk->lines);
        $this->assertSame(2 * (strlen($line) + 1), $chunk->end);

        $rest = $reader->read($this->path, $chunk->end);
        $this->assertCount(1, $rest->lines);
        $this->assertSame(3 * (strlen($line) + 1), $rest->end);
    }

    public function testOversizedLineIsSkippedInsteadOfBlocking(): void
    {
        file_put_contents($this->path, str_repeat('y', 3000) . "\n{\"ok\":1}\n");

        $reader = new LogFileReader(1024);
        $chunk = @$reader->read($this->path, 0);

        $this->assertTrue($chunk->isEmpty());
        $this->assertSame(1024, $chunk->end);
    }

    public function testPerCallByteBudgetCapsTheChunk(): void
    {
        $line = '{"m":"' . str_repeat('x', 100) . '"}';
        file_put_contents($this->path, str_repeat($line . "\n", 10));

        $reader = new LogFileReader();
        $chunk = $reader->read($this->path, 0, 2 * (strlen($line) + 1) + 5);

        $this->assertCount(2, $chunk->lines);
        $this->assertSame(2 * (strlen($line) + 1), $chunk->end);

        // A budget smaller than one line yields nothing and does not advance.
        $small = @$reader->read($this->path, $chunk->end, 20);
        $this->assertTrue($small->isEmpty());
        $this->assertSame($chunk->end, $small->end);
    }

    public function testMissingFileIsHarmless(): void
    {
        $chunk = (new LogFileReader())->read($this->path . '.missing', 10);

        $this->assertTrue($chunk->isEmpty());
        $this->assertSame(10, $chunk->end);
    }
}
