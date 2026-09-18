<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\Spool\SpoolDirectory;
use DevZone\LogMonitor\Spool\SpoolWriter;
use PHPUnit\Framework\TestCase;

final class SpoolTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lm-spool-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    private function writer(int $rotateBytes = 5 * 1024 * 1024, int $minFree = 0, int $maxTotal = 0, int $maxAge = 0): SpoolWriter
    {
        return new SpoolWriter(new SpoolDirectory($this->dir), $rotateBytes, $minFree, $maxTotal, $maxAge);
    }

    public function testEachWriteIsAppendedAsOneBlock(): void
    {
        $writer = $this->writer();
        $writer->write(['{"t":"request","trace":"a"}', '{"t":"queries","trace":"a"}']);
        $writer->write(['{"t":"request","trace":"b"}']);

        $this->assertSame(
            "{\"t\":\"request\",\"trace\":\"a\"}\n{\"t\":\"queries\",\"trace\":\"a\"}\n{\"t\":\"request\",\"trace\":\"b\"}\n",
            file_get_contents($this->dir . '/current.ndjson')
        );
        $this->assertSame('0664', substr(sprintf('%o', fileperms($this->dir . '/current.ndjson')), -4), 'shared group can rename and delete');
    }

    public function testWriterRotatesAtTheSizeLimit(): void
    {
        $writer = $this->writer(100);
        $writer->write(['{"t":"log","message":"' . str_repeat('x', 120) . '"}']);
        $writer->write(['{"t":"log","message":"next"}']);

        $batches = (new SpoolDirectory($this->dir))->batches();
        $this->assertCount(1, $batches);
        $this->assertMatchesRegularExpression('/batch-\d{8}-\d{6}-[0-9a-f]{4}\.ndjson$/', $batches[0]);
        $this->assertSame("{\"t\":\"log\",\"message\":\"next\"}\n", file_get_contents($this->dir . '/current.ndjson'), 'new lines start a fresh current file');
    }

    public function testRotationRenamesOnlyWhenThereIsContent(): void
    {
        $directory = new SpoolDirectory($this->dir);
        $directory->ensure();
        $this->assertNull($directory->rotateCurrent());

        $this->writer()->write(['{"t":"log"}']);
        $batch = $directory->rotateCurrent();

        $this->assertNotNull($batch);
        $this->assertFileDoesNotExist($this->dir . '/current.ndjson');
        $this->assertSame("{\"t\":\"log\"}\n", file_get_contents($batch));
    }

    public function testWriterReopensWhenTheFileWasRotatedUnderIt(): void
    {
        $directory = new SpoolDirectory($this->dir);
        $writer = $this->writer();
        $writer->write(['{"n":1}']);

        // Simulate a writer that opened current.ndjson just before a rotation.
        $stale = fopen($this->dir . '/current.ndjson', 'ab');
        $batch = $directory->rotateCurrent();
        $this->assertFalse(SpoolDirectory::handleMatchesPath($stale, $this->dir . '/current.ndjson'), 'the stale handle is detected');
        fclose($stale);

        $writer->write(['{"n":2}']);
        $this->assertSame("{\"n\":1}\n", file_get_contents($batch), 'nothing late lands in the rotated batch');
        $this->assertSame("{\"n\":2}\n", file_get_contents($this->dir . '/current.ndjson'));
    }

    public function testWriterRefusesWhenDiskIsNearlyFull(): void
    {
        $writer = $this->writer(5 * 1024 * 1024, PHP_INT_MAX);
        @$writer->write(['{"t":"log"}']);

        $this->assertFileDoesNotExist($this->dir . '/current.ndjson');
    }

    public function testPruneDropsOldestFirstBySizeAndAge(): void
    {
        $directory = new SpoolDirectory($this->dir);
        $directory->ensure();
        $old = $this->dir . '/batch-' . gmdate('Ymd-His', time() - 10 * 86400) . '-aaaa.ndjson';
        $a = $this->dir . '/batch-' . gmdate('Ymd-His', time() - 120) . '-bbbb.ndjson';
        $b = $this->dir . '/batch-' . gmdate('Ymd-His', time() - 60) . '-cccc.ndjson';
        file_put_contents($old, str_repeat('o', 10));
        file_put_contents($a, str_repeat('a', 600));
        file_put_contents($b, str_repeat('b', 600));
        file_put_contents($a . '.progress', '{"sent":1}');

        $dropped = @$directory->prune(1000, 7);

        $this->assertSame(2, $dropped);
        $this->assertSame([$b], $directory->batches(), 'the newest batch survives');
        $this->assertFileDoesNotExist($a . '.progress');
    }

    public function testBatchNamesSortOldestFirst(): void
    {
        $this->assertSame(1789900000, SpoolDirectory::batchTime('/x/batch-' . gmdate('Ymd-His', 1789900000) . '-abcd.ndjson'));
        $this->assertNull(SpoolDirectory::batchTime('/x/current.ndjson'));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
