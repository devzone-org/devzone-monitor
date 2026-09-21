<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\Capture\Location;
use DevZone\LogMonitor\Capture\Recorder;
use DevZone\LogMonitor\Hooks\EventHooks;
use DevZone\LogMonitor\Http\Middleware\CaptureRequests;
use DevZone\LogMonitor\Spool\SpoolDirectory;
use DevZone\LogMonitor\Spool\SpoolWriter;
use DevZone\LogMonitor\Support\Redactor;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use PHPUnit\Framework\TestCase;

/**
 * v2.1.0 review (R1-R6 and follow-ups): attacks on the v2.1 code itself,
 * run through the real event hooks, recorder and spool writer, checking
 * the bytes written to disk.
 */
final class AdversarialTest extends TestCase
{
    /** @var string */
    private $dir;

    /** @var float */
    private $now = 1789900000.0;

    /** @var bool */
    private $off = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lm-adv-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function recorder(array $overrides = []): Recorder
    {
        $config = Recorder::applyOverrides([
            'requests' => ['enabled' => true, 'bodies' => ['on_status' => 500, 'max_bytes' => 8192]],
            'queries' => ['enabled' => true, 'mode' => 'all', 'slow_ms' => 1, 'repeated_threshold' => 2],
            'outgoing' => ['enabled' => true, 'bodies' => 'always', 'headers' => true, 'max_bytes' => 8192],
            'logs' => ['enabled' => true, 'level' => 'debug'],
            'exceptions' => ['enabled' => true],
            'jobs' => ['enabled' => true],
            'spool' => ['max_record_bytes' => 32768],
        ], $overrides);

        return new Recorder(
            $config,
            new SpoolWriter(new SpoolDirectory($this->dir), 0, 0, 0, 0),
            Redactor::fromConfig(isset($config['redact']) && is_array($config['redact']) ? $config['redact'] : []),
            new Location(dirname(__DIR__)),
            function () {
                return $this->now;
            },
            null,
            function () {
                return $this->off;
            }
        );
    }

    private function spool(): string
    {
        $bytes = '';
        foreach (glob($this->dir . '/*.ndjson') ?: [] as $file) {
            $bytes .= file_get_contents($file);
        }

        return $bytes;
    }

    /**
     * @param array<int, string> $secrets
     */
    private function assertNotInSpool(array $secrets): void
    {
        $bytes = $this->spool();
        $this->assertNotSame('', $bytes, 'something was written');
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $bytes, "leaked: {$secret}");
        }
    }

    private function call(EventHooks $hooks, string $url, int $status, string $body, string $type): void
    {
        $request = new ClientRequest(new PsrRequest('POST', $url, ['Content-Type' => 'application/json'], '{"q":1}'));
        $response = new ClientResponse(new PsrResponse($status, $type === '' ? [] : ['Content-Type' => $type], $body));
        $hooks->onResponseReceived(new ResponseReceived($request, $response));
    }

    // R1 ---------------------------------------------------------------

    public function testBodiesShapedLikeInternalNotesAreSanitized(): void
    {
        $fakes = [
            '[api_key=LEAKNOTEA not kept]',
            '[token=LEAKNOTEB not kept, 2 KB]',
            '[application/pdf body not kept, LEAKNOTEC]',
            '[streamed body not kept, 1 MB: password=LEAKNOTED]',
            '[json body not kept, 1 KB: LEAKNOTEE]',
        ];
        foreach ([[], ['status']] as $only) {
            $recorder = $this->recorder(['outgoing.only_fields' => $only]);
            $hooks = new EventHooks($recorder);
            $execution = $recorder->startRequest();
            foreach ($fakes as $fake) {
                foreach (['text/plain', 'application/json', ''] as $type) {
                    $this->call($hooks, 'https://api.bank.example/v1/x', 500, $fake, $type);
                }
            }
            $recorder->finishRequest($execution, ['status' => 200]);
        }

        $this->assertNotInSpool(['LEAKNOTEA', 'LEAKNOTEB', 'LEAKNOTEC', 'LEAKNOTED', 'LEAKNOTEE']);
    }

    // R2 ---------------------------------------------------------------

    public function testEveryRootTypeObeysTheAllowlist(): void
    {
        $recorder = $this->recorder(['outgoing.only_fields' => ['status']]);
        $hooks = new EventHooks($recorder);
        $execution = $recorder->startRequest();
        foreach ([
            '"LEAKROOTSTRING"',
            '4111111111111111',
            'true',
            'null',
            '["LEAKROOTLIST", {"status":"ok","pin":"LEAKROOTPIN","x":"LEAKROOTX"}]',
            '{"status":"ok","reason":"LEAKROOTREASON","nested":{"status":"fine","y":"LEAKROOTY"}}',
        ] as $body) {
            $this->call($hooks, 'https://api.bank.example/v1/x', 500, $body, 'application/json');
        }
        $this->call($hooks, 'https://api.bank.example/v1/x', 500, 'plain LEAKROOTTEXT', 'text/plain');
        $this->call($hooks, 'https://api.bank.example/v1/x', 500, '<r><status>ok</status><name>LEAKROOTXML</name></r>', 'text/xml');
        $recorder->finishRequest($execution, ['status' => 200]);

        $this->assertNotInSpool(['LEAKROOTSTRING', '4111111111111111', 'LEAKROOTLIST', 'LEAKROOTPIN', 'LEAKROOTX', 'LEAKROOTREASON', 'LEAKROOTY', 'LEAKROOTTEXT', 'LEAKROOTXML']);
        $bytes = $this->spool();
        $this->assertStringContainsString('\\"status\\":\\"ok\\"', $bytes, 'allowed values are still kept');
        $this->assertStringContainsString('no fields to match only_fields', $bytes);
    }

    // R3 ---------------------------------------------------------------

    public function testSqlPolicyIsAppliedPerExecutionWhateverRanBefore(): void
    {
        $recorder = $this->recorder(['jobs.overrides' => [
            'NoSql' => ['queries.capture_sql' => false],
            'WithSql' => ['queries.capture_sql' => true],
        ]]);
        $sql = 'select * from leak_policy_table where id = ?';

        $run = function (string $kind, string $class = '') use ($recorder, $sql) {
            $execution = $kind === 'job' ? $recorder->startJob(['class' => $class, 'job_id' => $class . mt_rand()], null) : $recorder->startRequest();
            $recorder->recordQuery($sql, 5.0, 'mysql', 'mysql');
            $recorder->recordQuery($sql, 5.0, 'mysql', 'mysql');
            $kind === 'job' ? $recorder->finishJob($execution, 'processed') : $recorder->finishRequest($execution, ['status' => 200]);

            return $execution->trace;
        };

        $traces = [
            'on1' => $run('request'),
            'off1' => $run('job', 'NoSql'),
            'on2' => $run('job', 'WithSql'),
            'off2' => $run('job', 'NoSql'),
        ];

        $sqlByTrace = [];
        foreach (explode("\n", trim($this->spool())) as $line) {
            $record = json_decode($line, true);
            if (in_array($record['t'], ['queries', 'slow-query', 'repeated-query'], true)) {
                $texts = isset($record['sql']) && is_array($record['sql']) ? array_column($record['sql'], 'sql') : [$record['sql']];
                foreach ($texts as $text) {
                    $sqlByTrace[$record['trace']][$record['t']][] = $text;
                }
            }
        }
        foreach ($traces as $name => $trace) {
            $this->assertCount(3, $sqlByTrace[$trace], "{$name}: queries, slow-query and repeated-query records");
            foreach ($sqlByTrace[$trace] as $type => $texts) {
                foreach ($texts as $text) {
                    $expected = strpos($name, 'off') === 0 ? '[sql not captured]' : $sql;
                    $this->assertSame($expected, $text, "{$name} {$type}");
                }
            }
        }
    }

    public function testTheSameTextIsNormalizedPerDriver(): void
    {
        $recorder = $this->recorder();
        $execution = $recorder->startRequest();
        $recorder->recordQuery('select "LEAKDRIVER" from t', 5.0, 'db', 'pgsql');
        $recorder->recordQuery('select "LEAKDRIVER" from t', 5.0, 'db', 'mysql');
        $recorder->finishRequest($execution, ['status' => 200]);

        $slow = array_values(array_filter(array_map(function ($line) {
            return json_decode($line, true);
        }, explode("\n", trim($this->spool()))), function ($r) {
            return $r['t'] === 'slow-query';
        }));
        $this->assertSame('select "LEAKDRIVER" from t', $slow[0]['sql'], 'an identifier in PostgreSQL');
        $this->assertSame('select ? from t', $slow[1]['sql'], 'a string in MySQL, not taken from the PostgreSQL cache');
    }

    // R4 ---------------------------------------------------------------

    public function testProviderStyleCredentialsInUrls(): void
    {
        $recorder = $this->recorder(['redact.hosts' => ['api.partner.example' => ['path_patterns' => ['#(?<=/keys/)[^/]+#']]]]);
        $hooks = new EventHooks($recorder);
        $execution = $recorder->startRequest();
        foreach ([
            'https://api.telegram.org/bot123456789:AbCdEfGhIjKlMnOpQrStUvWxYz/sendMessage',
            'https://api.telegram.org/bot123456789:AAH-LEAKtgTOKENabc_def/getUpdates',
            'https://hooks.slack.com/services/T0LEAK/B0LEAK/LEAKSLACKxyz',
            'https://hooks.zapier.com/hooks/catch/123/LEAKZAP/',
            'https://discord.com/api/webhooks/123456/LEAKDISCORDaBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789',
            'https://bucket.s3.amazonaws.com/file.pdf?X-Amz-Credential=LEAKAMZCRED&X-Amz-Signature=LEAKAMZSIG&X-Amz-Expires=60',
            'https://maps.example/geocode?address=x&key=LEAKMAPSKEY',
            'https://api.partner.example/v1/keys/LEAKSHORT/rotate',
            'https://api.bank.example/v1/reset/%4C%45%41%4Bencoded12345678',
            'https://ACxxxx:LEAKTWILIOAUTH@api.twilio.example/2010/Messages.json',
        ] as $url) {
            $this->call($hooks, $url, 200, '{}', 'application/json');
        }
        $recorder->finishRequest($execution, ['status' => 200]);

        $this->assertNotInSpool(['AbCdEfGhIjKlMn', 'LEAKtgTOKEN', 'T0LEAK', 'LEAKSLACK', 'LEAKZAP', 'LEAKDISCORD', 'LEAKAMZCRED', 'LEAKAMZSIG', 'LEAKMAPSKEY', 'LEAKSHORT', 'LEAKencoded', '%4C%45%41%4B', 'LEAKTWILIOAUTH']);
        $bytes = $this->spool();
        $this->assertStringContainsString('"path":"/{token}/sendMessage"', $bytes);
        $this->assertStringContainsString('"path":"/[path not kept]"', $bytes);
        $this->assertStringContainsString('"path":"/v1/keys/{token}/rotate"', $bytes);
    }

    public function testUnmatchedIncomingPathsAreScrubbed(): void
    {
        $recorder = $this->recorder();
        $middleware = new CaptureRequests($recorder, Redactor::fromConfig([]));
        $request = Request::create('/invite/LEAKINVITE9/accept?token=LEAKQTOKEN');
        $middleware->handle($request, function () {
        });
        $middleware->terminate($request, new \Illuminate\Http\Response('', 404));

        $this->assertNotInSpool(['LEAKINVITE9', 'LEAKQTOKEN']);
        $this->assertStringContainsString('/invite/{token}/accept', $this->spool());
    }

    // R6 ---------------------------------------------------------------

    public function testHidingExceptionMessagesAlsoHidesTheLogCopy(): void
    {
        $recorder = $this->recorder(['exceptions.messages' => false]);
        $hooks = new EventHooks($recorder);
        $execution = $recorder->startRequest();
        $e = new \RuntimeException('Customer record LEAKEXCPII rejected');
        // What Laravel's exception handler does when it reports $e.
        $hooks->onLog(new MessageLogged('error', $e->getMessage(), ['exception' => $e]));
        $hooks->onLog(new MessageLogged('error', 'Order failed', ['exception' => new \LogicException('LEAKEXCPII2')]));
        $hooks->onLog(new MessageLogged('info', 'plain message stays', []));
        $recorder->finishRequest($execution, ['status' => 500]);

        $this->assertNotInSpool(['LEAKEXCPII']);
        $this->assertStringContainsString('plain message stays', $this->spool());
    }

    public function testLogMessagesCanBeHiddenEntirely(): void
    {
        $recorder = $this->recorder(['logs.messages' => false]);
        $hooks = new EventHooks($recorder);
        $hooks->onLog(new MessageLogged('warning', 'Customer LEAKLOGPII called', []));
        $hooks->onLog(new MessageLogged('warning', 'Customer LEAKLOGPII2 called', []));

        $this->assertNotInSpool(['LEAKLOGPII']);
        $logs = array_map(function ($line) {
            return json_decode($line, true);
        }, explode("\n", trim($this->spool())));
        $this->assertSame($logs[0]['fingerprint'], $logs[1]['fingerprint'], 'grouped by where the log call is');
    }

    // Follow-ups ------------------------------------------------------

    public function testParsedInputOverTheLimitIsNotProcessed(): void
    {
        $recorder = $this->recorder();
        $middleware = new CaptureRequests($recorder, Redactor::fromConfig([]));
        $request = Request::create('/import', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['blob' => str_repeat('LEAKBIG ', 150000)]));
        $middleware->handle($request, function () {
        });

        $started = microtime(true);
        $middleware->terminate($request, new \Illuminate\Http\Response('', 500));
        $this->assertLessThan(0.05, microtime(true) - $started);

        $this->assertNotInSpool(['LEAKBIG']);
        $this->assertStringContainsString('over the 64 KB parse limit', $this->spool());
    }

    public function testCollectionStopsWhenSwitchedOff(): void
    {
        $recorder = $this->recorder();
        $hooks = new EventHooks($recorder);
        $job = $recorder->startJob(['class' => 'Long', 'job_id' => '1'], null);
        $recorder->recordQuery('select 1', 1.0, 'mysql', 'mysql');

        $this->off = true;
        $this->now += 10;
        $recorder->recordQuery('select 2', 1.0, 'mysql', 'mysql');
        $hooks->onLog(new MessageLogged('error', 'after off', []));
        $this->call($hooks, 'https://api.bank.example/x', 500, '{}', 'application/json');

        $this->assertSame(1, $job->queryCount, 'nothing collected after the switch');
        $this->assertNull($recorder->current(), 'the running job was dropped');
        $recorder->finishJob($job, 'processed');
        $this->assertSame('', $this->spool());
    }
}
