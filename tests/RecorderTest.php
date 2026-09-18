<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\Capture\Location;
use DevZone\LogMonitor\Capture\Recorder;
use DevZone\LogMonitor\Support\Redactor;
use DevZone\LogMonitor\Tests\Support\MemorySink;
use PHPUnit\Framework\TestCase;

final class RecorderTest extends TestCase
{
    /** @var MemorySink */
    private $sink;

    /** @var float */
    private $now = 1789900000.0;

    /** @var float */
    private $random = 0.99;

    private function recorder(array $overrides = []): Recorder
    {
        $this->sink = new MemorySink();
        $config = Recorder::applyOverrides([
            'enabled' => true,
            'requests' => ['enabled' => true],
            'queries' => [
                'enabled' => true,
                'mode' => 'sampled',
                'sample_rate' => 0.10,
                'max_per_request' => 2000,
                'capture_location' => 'slow',
                'slow_ms' => 100,
                'repeated_threshold' => 10,
                'max_slow_per_request' => 100,
            ],
            'outgoing' => ['enabled' => true, 'bodies_on_error' => true, 'max_bytes' => 8192, 'max_per_request' => 500],
            'logs' => ['enabled' => true, 'level' => 'warning', 'max_per_request' => 200],
            'exceptions' => ['enabled' => true, 'max_frames' => 50],
            'jobs' => ['enabled' => true, 'flush_records' => 500, 'flush_seconds' => 30, 'overrides' => []],
            'spool' => ['max_record_bytes' => 32768],
        ], $overrides);

        return new Recorder(
            $config,
            $this->sink,
            new Redactor(),
            new Location(dirname(__DIR__)),
            function () {
                return $this->now;
            },
            function () {
                return $this->random;
            }
        );
    }

    public function testRequestWithThirtyQueriesInSummaryIsOneWriteWithCounters(): void
    {
        $recorder = $this->recorder();
        $execution = $recorder->startRequest();
        for ($i = 0; $i < 30; $i++) {
            $recorder->recordQuery('select * from `transfers` where `id` = ?', 2.0, 'mysql');
        }
        $this->now += 0.25;
        $recorder->finishRequest($execution, ['method' => 'GET', 'url' => 'https://app.test/transfers?token=abc', 'route' => '/transfers', 'status' => 200]);

        $this->assertCount(1, $this->sink->writes, 'one append per request');
        $request = $this->sink->records('request')[0];
        $this->assertSame(30, $request['queries']);
        $this->assertSame(60.0, $request['query_ms']);
        $this->assertSame(250.0, $request['ms']);
        $this->assertSame('https://app.test/transfers', $request['url'], 'query string is stripped');
        $this->assertFalse($request['queries_detail']);
        $this->assertSame([], $this->sink->records('queries'), 'sample not hit: no per-query line');

        $repeated = $this->sink->records('repeated-query');
        $this->assertCount(1, $repeated);
        $this->assertSame(30, $repeated[0]['count']);
        $this->assertSame('tests/RecorderTest.php', $repeated[0]['file'], 'location of the 10th run is the caller');
    }

    public function testSampledRequestWritesCompactQueryLineWithEachStatementOnce(): void
    {
        $this->random = 0.01; // inside the 10% sample
        $recorder = $this->recorder();
        $execution = $recorder->startRequest();
        $recorder->recordQuery('select * from users where email = \'bob@example.com\'', 1.5, 'mysql');
        $recorder->recordQuery('select * from users where email = \'amy@example.com\'', 1.0, 'mysql');
        $recorder->recordQuery('select * from rates where id in (?, ?, ?)', 3.0, 'mysql');
        $recorder->finishRequest($execution, ['status' => 200]);

        $lines = $this->sink->records('queries');
        $this->assertCount(1, $lines);
        $this->assertCount(2, $lines[0]['sql'], 'same normalized statement stored once');
        $this->assertCount(3, $lines[0]['q']);
        $texts = array_column($lines[0]['sql'], 'sql');
        $this->assertContains('select * from users where email = ?', $texts, 'literal values are masked');
        $this->assertContains('select * from rates where id in (...)', $texts);
        $this->assertStringNotContainsString('example.com', json_encode($lines));
    }

    public function testUnsampledRequestStillGetsFullQueriesWhenItErrors(): void
    {
        $recorder = $this->recorder();
        $execution = $recorder->startRequest();
        $recorder->recordQuery('select 1', 1.0, 'mysql');
        $recorder->finishRequest($execution, ['status' => 500]);

        $this->assertCount(1, $this->sink->records('queries'));
        $this->assertTrue($this->sink->records('request')[0]['queries_detail']);
    }

    public function testSlowQueryGetsItsOwnRecordWithLocation(): void
    {
        $recorder = $this->recorder();
        $execution = $recorder->startRequest();
        $recorder->recordQuery('select * from big where x = 5', 140.0, 'mysql');
        $recorder->finishRequest($execution, ['status' => 200]);

        $slow = $this->sink->records('slow-query');
        $this->assertCount(1, $slow);
        $this->assertSame(140.0, $slow[0]['ms']);
        $this->assertSame('select * from big where x = ?', $slow[0]['sql']);
        $this->assertSame('tests/RecorderTest.php', $slow[0]['file']);
        $this->assertIsInt($slow[0]['line']);
    }

    public function testQueryCapIsEnforcedAndCounted(): void
    {
        $recorder = $this->recorder(['queries.mode' => 'all', 'queries.max_per_request' => 5, 'queries.repeated_threshold' => 0]);
        $execution = $recorder->startRequest();
        for ($i = 0; $i < 8; $i++) {
            $recorder->recordQuery('select ' . $i, 1.0, 'mysql');
        }
        $recorder->finishRequest($execution, ['status' => 200]);

        $request = $this->sink->records('request')[0];
        $this->assertSame(8, $request['queries'], 'totals still count everything');
        $this->assertSame(['queries' => 3], $request['dropped']);
        $this->assertCount(5, $this->sink->records('queries')[0]['q']);
    }

    public function testLargeQueryListIsSplitSoNoLineExceedsTheRecordLimit(): void
    {
        $recorder = $this->recorder(['queries.mode' => 'all', 'queries.repeated_threshold' => 0, 'spool.max_record_bytes' => 4096]);
        $execution = $recorder->startRequest();
        for ($i = 0; $i < 400; $i++) {
            $recorder->recordQuery('select * from table_' . ($i % 40) . ' where id = ?', 1.0, 'mysql');
        }
        $recorder->finishRequest($execution, ['status' => 200]);

        $lines = $this->sink->writes[0];
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(4096, strlen($line));
        }
        $total = 0;
        foreach ($this->sink->records('queries') as $chunk) {
            $total += count($chunk['q']);
        }
        $this->assertSame(400, $total);
        $this->assertGreaterThan(1, count($this->sink->records('queries')));
    }

    public function testOutgoingCallsKeepBodiesOnlyOnErrorAndRedacted(): void
    {
        $recorder = $this->recorder();
        $execution = $recorder->startRequest();
        $recorder->recordOutgoing(['method' => 'post', 'url' => 'https://api.bank.example/verify?key=secret', 'status' => 200, 'ms' => 950.0, 'request_body' => '{"pin":"4321"}']);
        $recorder->recordOutgoing([
            'method' => 'POST',
            'url' => 'https://api.bank.example/title',
            'status' => 500,
            'ms' => 120.0,
            'request_body' => '<soap:Body><AccountNo>0011223344</AccountNo></soap:Body>',
            'response_body' => '{"error":"bad","cnic":"3520212345671"}',
        ]);
        $recorder->finishRequest($execution, ['status' => 200]);

        $calls = $this->sink->records('outgoing');
        $this->assertCount(2, $calls);
        $this->assertSame('api.bank.example', $calls[0]['host']);
        $this->assertSame('/verify', $calls[0]['path'], 'no query string');
        $this->assertArrayNotHasKey('request_body', $calls[0], 'successful call: no bodies');
        $this->assertSame('<soap:Body><AccountNo>[REDACTED]</AccountNo></soap:Body>', $calls[1]['request_body']);
        $this->assertSame('{"error":"bad","cnic":"[REDACTED]"}', $calls[1]['response_body']);

        $request = $this->sink->records('request')[0];
        $this->assertSame(2, $request['outgoing']);
        $this->assertSame(1070.0, $request['outgoing_ms']);
    }

    public function testLogsFollowTheirOwnLevelAndCarryTheTrace(): void
    {
        $recorder = $this->recorder(['logs.level' => 'info']);
        $execution = $recorder->startRequest();
        $recorder->recordLog('debug', 'noise', []);
        $recorder->recordLog('info', 'User 42 logged in with password=hunter2', ['password' => 'x', 'nested' => ['token' => 't']]);
        $recorder->finishRequest($execution, ['status' => 200]);

        $logs = $this->sink->records('log');
        $this->assertCount(1, $logs);
        $this->assertSame($execution->trace, $logs[0]['trace']);
        $this->assertSame('User 42 logged in with password=[REDACTED]', $logs[0]['message']);
        $this->assertSame(['password' => '[REDACTED]', 'nested' => ['token' => '[REDACTED]']], $logs[0]['context']);
        $this->assertSame(1, $this->sink->records('request')[0]['logs']);
    }

    public function testLogOutsideAnyExecutionIsWrittenImmediately(): void
    {
        $recorder = $this->recorder();
        $recorder->recordLog('error', 'scheduler broke', []);

        $this->assertCount(1, $this->sink->writes);
        $this->assertNull($this->sink->records('log')[0]['trace']);
    }

    public function testExceptionInLogContextBecomesOneExceptionRecord(): void
    {
        $recorder = $this->recorder();
        $execution = $recorder->startRequest();
        $e = new \RuntimeException('Login failed for bob@example.com', 0, new \LogicException('inner'));
        $recorder->recordLog('error', $e->getMessage(), ['exception' => $e]);
        $recorder->recordException($e); // reported twice: recorded once
        $recorder->finishRequest($execution, ['status' => 500]);

        $exceptions = $this->sink->records('exception');
        $this->assertCount(1, $exceptions);
        $this->assertSame('RuntimeException', $exceptions[0]['class']);
        $this->assertSame('Login failed for [REDACTED]', $exceptions[0]['message']);
        $this->assertSame('tests/RecorderTest.php', $exceptions[0]['file']);
        $this->assertSame('LogicException', $exceptions[0]['previous'][0]['class']);
        $this->assertSame(32, strlen($exceptions[0]['fingerprint']));
        $this->assertSame(['class' => 'RuntimeException', 'message' => 'Login failed for [REDACTED]', 'file' => 'tests/RecorderTest.php', 'line' => $exceptions[0]['line']], $this->sink->records('log')[0]['context']['exception']);
    }

    public function testSyncJobInsideRequestIsLinkedAndSeparated(): void
    {
        $recorder = $this->recorder();
        $request = $recorder->startRequest();
        $recorder->recordQuery('select 1', 1.0, 'mysql');

        $job = $recorder->startJob(['class' => 'App\\Jobs\\RunSanctions', 'queue' => 'default', 'connection' => 'sync', 'job_id' => 'object:1', 'attempt' => 1], null);
        $recorder->recordQuery('select 2', 1.0, 'mysql');
        $recorder->recordQuery('select 3', 1.0, 'mysql');
        $recorder->finishJob($job, 'processed');

        $recorder->recordQuery('select 4', 1.0, 'mysql');
        $recorder->finishRequest($request, ['status' => 200]);

        $jobRecord = $this->sink->records('job')[0];
        $this->assertSame($request->trace, $jobRecord['parent']);
        $this->assertSame(2, $jobRecord['queries']);
        $this->assertSame('processed', $jobRecord['status']);
        $this->assertSame(2, $this->sink->records('request')[0]['queries']);
    }

    public function testJobOverridesAndPartialFlushes(): void
    {
        $recorder = $this->recorder([
            'jobs.flush_records' => 50,
            'jobs.overrides' => ['App\\Jobs\\BatchScreening' => ['queries.mode' => 'all', 'queries.max_per_request' => 20000, 'queries.repeated_threshold' => 0]],
        ]);
        $job = $recorder->startJob(['class' => 'App\\Jobs\\BatchScreening', 'job_id' => '7'], 'parent-trace');
        for ($i = 0; $i < 120; $i++) {
            $recorder->recordQuery('select * from customers where id = ?', 1.0, 'mysql');
        }
        $this->assertCount(2, $this->sink->writes, 'two partial flushes before the job ends');
        $recorder->finishJob($job, 'processed');

        $total = 0;
        foreach ($this->sink->records('queries') as $chunk) {
            $total += count($chunk['q']);
        }
        $this->assertSame(120, $total, 'override captured every query across flushes');
        $jobRecord = $this->sink->records('job')[0];
        $this->assertSame(2, $jobRecord['partial_flushes']);
        $this->assertSame('parent-trace', $jobRecord['parent']);
        $this->assertSame(120, $jobRecord['queries']);
    }

    public function testFailedJobCarriesTheException(): void
    {
        $recorder = $this->recorder();
        $job = $recorder->startJob(['class' => 'App\\Jobs\\RunSanctions', 'job_id' => '9'], null);
        $recorder->finishJob($job, 'failed', new \RuntimeException('Onfido timeout'));

        $jobRecord = $this->sink->records('job')[0];
        $this->assertSame('failed', $jobRecord['status']);
        $this->assertSame('RuntimeException', $jobRecord['exception']['class']);
        $this->assertCount(1, $this->sink->records('exception'));
    }

    public function testOversizedRecordIsShrunkNotDropped(): void
    {
        $recorder = $this->recorder(['spool.max_record_bytes' => 2048]);
        $execution = $recorder->startRequest();
        $recorder->recordLog('error', str_repeat('x', 3000), ['big' => str_repeat('y', 5000)]);
        $recorder->finishRequest($execution, ['status' => 200]);

        $log = $this->sink->records('log')[0];
        $this->assertTrue($log['_truncated']);
        $this->assertSame(['_truncated' => true], $log['context']);
    }

    public function testShutdownFlushesUnfinishedExecutions(): void
    {
        $recorder = $this->recorder();
        $recorder->startRequest();
        $recorder->recordQuery('select 1', 1.0, 'mysql');
        $recorder->shutdown();

        $request = $this->sink->records('request')[0];
        $this->assertTrue($request['interrupted']);
        $this->assertNull($request['status']);
    }

    public function testDisabledQueriesAreIgnored(): void
    {
        $recorder = $this->recorder(['queries.enabled' => false]);
        $execution = $recorder->startRequest();
        $recorder->recordQuery('select 1', 500.0, 'mysql');
        $recorder->finishRequest($execution, ['status' => 200]);

        $this->assertSame(0, $this->sink->records('request')[0]['queries']);
        $this->assertSame([], $this->sink->records('slow-query'));
    }
}
