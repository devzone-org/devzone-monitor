<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\Shipping\Shipper;
use DevZone\LogMonitor\Spool\SpoolDirectory;
use DevZone\LogMonitor\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ShipperTest extends TestCase
{
    /** @var string */
    private $dir;

    /** @var SpoolDirectory */
    private $directory;

    /** @var FakeTransport */
    private $transport;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lm-ship-' . bin2hex(random_bytes(4));
        $this->directory = new SpoolDirectory($this->dir);
        $this->directory->ensure();
        $this->transport = new FakeTransport();
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->dir . '/*') ?: [], glob($this->dir . '/failed/*') ?: []) as $file) {
            @unlink($file);
        }
        @rmdir($this->dir . '/failed');
        @rmdir($this->dir);
    }

    private function shipper(array $shipping = []): Shipper
    {
        return new Shipper($this->directory, $this->transport, [
            'shipping' => $shipping + ['records_per_request' => 1000, 'max_bytes_per_request' => 2 * 1024 * 1024, 'time_budget_seconds' => 50, 'quarantine_after' => 3],
            'spool' => ['max_total_bytes' => 0, 'max_age_days' => 0],
        ], ['app' => 'Back Office', 'env' => 'production', 'host' => 'web-01', 'client' => 'acme', 'package' => '2.0.0']);
    }

    private function lines(int $count, string $prefix = 'r'): string
    {
        $out = '';
        for ($i = 0; $i < $count; $i++) {
            $out .= json_encode(['t' => 'log', 'id' => $prefix . $i]) . "\n";
        }

        return $out;
    }

    public function testTheTimeBudgetIsCheckedBeforeEveryRequest(): void
    {
        file_put_contents($this->directory->currentPath(), $this->lines(5));
        $now = 1000.0;
        $transport = new class($now) implements \DevZone\LogMonitor\Transport\Transport {
            /** @var float */
            public $clock;

            public function __construct(float &$clock)
            {
                $this->clock = &$clock;
            }

            public function send(string $json): array
            {
                $this->clock += 20; // a slow server

                return ['ok' => true, 'status' => 202, 'retryable' => false, 'error' => null];
            }
        };
        $shipper = new Shipper($this->directory, $transport, [
            'shipping' => ['records_per_request' => 1, 'max_bytes_per_request' => 1048576, 'time_budget_seconds' => 50, 'quarantine_after' => 3],
            'spool' => ['max_total_bytes' => 0, 'max_age_days' => 0],
        ], [], function () use (&$now) {
            return $now;
        });

        $summary = $shipper->run();

        $this->assertSame('time budget reached', $summary['stopped']);
        $this->assertSame(3, $summary['requests'], 'stopped inside the batch, not after all five');
        $this->assertSame(['sent' => 3, 'failures' => 0, 'last_error' => null], $this->directory->progress($this->directory->batches()[0]));
    }

    public function testRotatesSendsAndDeletes(): void
    {
        file_put_contents($this->directory->currentPath(), $this->lines(3));

        $summary = $this->shipper()->run();

        $this->assertSame(3, $summary['records']);
        $this->assertSame(1, $summary['batches']);
        $this->assertSame([], $this->directory->batches());
        $this->assertFileDoesNotExist($this->directory->currentPath());

        $body = $this->transport->sent[0];
        $this->assertSame(2, $body['v']);
        $this->assertSame('Back Office', $body['app']);
        $this->assertSame(3, $body['count']);
        $this->assertSame('r0', $body['records'][0]['id']);
    }

    public function testServerDownKeepsEverythingForNextRun(): void
    {
        file_put_contents($this->directory->currentPath(), $this->lines(3));
        $this->transport->fail(true, 503);

        $summary = $this->shipper()->run();

        $this->assertSame(0, $summary['records']);
        $this->assertSame('HTTP 503', $summary['stopped']);
        $this->assertCount(1, $this->directory->batches(), 'batch kept');

        $again = $this->shipper()->run();
        $this->assertSame(3, $again['records']);
        $this->assertSame([], $this->directory->batches());
    }

    public function testProgressInsideABatchIsNotResent(): void
    {
        file_put_contents($this->directory->currentPath(), $this->lines(5));
        $this->transport->results = [
            ['ok' => true, 'status' => 202, 'retryable' => false, 'error' => null],
            ['ok' => false, 'status' => 500, 'retryable' => true, 'error' => 'HTTP 500'],
        ];

        $this->shipper(['records_per_request' => 2])->run();
        $this->assertSame(2, $this->directory->progress($this->directory->batches()[0])['sent']);

        $this->transport->sent = [];
        $this->shipper(['records_per_request' => 2])->run();
        $ids = [];
        foreach ($this->transport->sent as $body) {
            foreach ($body['records'] as $record) {
                $ids[] = $record['id'];
            }
        }
        $this->assertSame(['r2', 'r3', 'r4'], $ids, 'only the unsent lines go out on retry');
    }

    public function testRejectedBatchIsQuarantinedAfterRepeatedFailures(): void
    {
        file_put_contents($this->directory->currentPath(), $this->lines(1, 'bad'));
        for ($i = 0; $i < 3; $i++) {
            $this->transport->fail(false, 422);
            $this->shipper()->run();
        }

        $this->assertSame([], $this->directory->batches());
        $this->assertCount(1, $this->directory->failedBatches());
    }

    public function testMalformedLinesAreSkipped(): void
    {
        file_put_contents($this->directory->currentPath(), "{\"ok\":1}\n{\"cut off\n\n{\"ok\":2}\n");

        $summary = $this->shipper()->run();

        $this->assertSame(2, $summary['records']);
        $this->assertSame(1, $summary['malformed']);
    }

    public function testChunksRespectCountAndBytes(): void
    {
        $chunks = Shipper::chunks(['{"a":1}', '{"a":2}', '{"a":3}'], 2, 1000);
        $this->assertSame([['{"a":1}', '{"a":2}'], ['{"a":3}']], $chunks);

        $chunks = Shipper::chunks(['{"a":"' . str_repeat('x', 40) . '"}', '{"b":1}'], 100, 50);
        $this->assertCount(2, $chunks);
    }

    public function testDryRunSendsNothing(): void
    {
        file_put_contents($this->directory->currentPath(), $this->lines(2));
        $this->directory->rotateCurrent();

        $summary = $this->shipper()->run(true);

        $this->assertSame(2, $summary['records']);
        $this->assertSame([], $this->transport->sent);
        $this->assertCount(1, $this->directory->batches());
    }
}
