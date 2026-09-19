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
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->dir . '/current.ndjson')), -4), 'owner only by default');
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->dir)), -4));
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

    public function testModesAndGroupAreConfigurable(): void
    {
        $writer = SpoolWriter::fromConfig(['path' => $this->dir, 'file_mode' => '0660', 'dir_mode' => '0770', 'rotate_bytes' => 0, 'min_free_disk' => 0]);
        $writer->write(['{"t":"log"}']);
        clearstatcache();
        $this->assertSame('0660', substr(sprintf('%o', fileperms($this->dir . '/current.ndjson')), -4));
        $this->assertSame('0770', substr(sprintf('%o', fileperms($this->dir)), -4));
    }

    public function testFilesFromOlderVersionsAreTightened(): void
    {
        $directory = new SpoolDirectory($this->dir);
        $directory->ensure();
        chmod($this->dir, 0775);
        file_put_contents($this->dir . '/current.ndjson', "{\"t\":\"log\"}\n");
        chmod($this->dir . '/current.ndjson', 0664);
        $batch = $this->dir . '/batch-' . gmdate('Ymd-His') . '-aaaa.ndjson';
        file_put_contents($batch, "{}\n");
        chmod($batch, 0644);

        $this->writer()->write(['{"t":"log"}']);
        clearstatcache();
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->dir . '/current.ndjson')), -4), 'the writer fixes current.ndjson');

        $directory->tighten();
        clearstatcache();
        $this->assertSame('0600', substr(sprintf('%o', fileperms($batch)), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->dir)), -4));
    }

    public function testQuarantinedBatchesCountTowardsTheCapsAndGoFirst(): void
    {
        $directory = new SpoolDirectory($this->dir);
        $directory->ensure();
        $failedOld = $this->dir . '/batch-' . gmdate('Ymd-His', time() - 10 * 86400) . '-ffff.ndjson';
        $failedNew = $this->dir . '/batch-' . gmdate('Ymd-His', time() - 30) . '-eeee.ndjson';
        $waiting = $this->dir . '/batch-' . gmdate('Ymd-His', time() - 120) . '-aaaa.ndjson';
        file_put_contents($failedOld, str_repeat('f', 100));
        file_put_contents($failedNew, str_repeat('e', 600));
        file_put_contents($waiting, str_repeat('a', 600));
        $directory->quarantine($failedOld);
        $directory->quarantine($failedNew);

        $dropped = @$directory->prune(1000, 7);

        $this->assertSame(2, $dropped, 'the week-old failed batch by age, the newer one by size');
        $this->assertSame([], $directory->failedBatches());
        $this->assertSame([$waiting], $directory->batches(), 'a batch that can still be sent outlives failed ones');
        $this->assertSame(['count' => 0, 'bytes' => 0, 'oldest' => null], $directory->failedSummary());
    }

    public function testPurgeRemovesEverythingIncludingQuarantined(): void
    {
        $directory = new SpoolDirectory($this->dir);
        $this->writer()->write(['{"t":"log"}']);
        $batch = $directory->rotateCurrent();
        $this->writer()->write(['{"t":"log"}']);
        file_put_contents($batch . '.progress', '{}');
        $failed = $this->dir . '/batch-' . gmdate('Ymd-His') . '-ffff.ndjson';
        file_put_contents($failed, '{}');
        $directory->quarantine($failed);

        $this->assertSame(3, $directory->purge());
        $this->assertSame([], glob($this->dir . '/*.ndjson*'));
        $this->assertSame([], $directory->failedBatches());
    }

    public function testAWriterNeverWaitsLongForTheLock(): void
    {
        $this->writer()->write(['{"n":1}']);
        $holder = fopen($this->dir . '/current.ndjson', 'ab');
        flock($holder, LOCK_EX);

        $started = microtime(true);
        @$this->writer()->write(['{"n":2}']);
        $elapsed = microtime(true) - $started;

        flock($holder, LOCK_UN);
        fclose($holder);
        $this->assertLessThan(0.5, $elapsed, 'gave up instead of blocking');
        $this->assertSame("{\"n\":1}\n", file_get_contents($this->dir . '/current.ndjson'), 'the lines were dropped, nothing half-written');

        $directory = new SpoolDirectory($this->dir);
        $holder = fopen($this->dir . '/current.ndjson', 'ab');
        flock($holder, LOCK_EX);
        $this->assertNull($directory->read($this->dir . '/current.ndjson'), 'the shipper skips a busy file');
        flock($holder, LOCK_UN);
        fclose($holder);
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
