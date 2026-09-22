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
            'outgoing' => ['enabled' => true, 'bodies_on_error' => true, 'headers' => true, 'max_bytes' => 8192, 'max_per_request' => 500],
            'logs' => ['enabled' => true, 'level' => 'warning', 'max_per_request' => 200],
            'exceptions' => ['enabled' => true, 'max_frames' => 50],
            'jobs' => ['enabled' => true, 'flush_records' => 500, 'flush_seconds' => 30, 'overrides' => []],
            'spool' => ['max_record_bytes' => 32768],
        ], $overrides);

        return new Recorder(
            $config,
            $this->sink,
            Redactor::fromConfig(isset($config['redact']) && is_array($config['redact']) ? $config['redact'] : []),
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

    public function testOutgoingHeadersAreSentForEveryCallWithSecretsMasked(): void
    {
        $recorder = $this->recorder(['redact' => ['headers' => ['authorization', 'cookie', 'set-cookie'], 'replacement' => '[REDACTED]']]);
        $execution = $recorder->startRequest();
        $recorder->recordOutgoing([
            'method' => 'GET',
            'url' => 'https://api.bank.example/rates',
            'status' => 200,
            'ms' => 80.0,
            'request_headers' => ['Authorization' => ['Bearer abc123'], 'Accept' => ['application/json'], 'X-Request-Id' => ['r-1']],
            'response_headers' => ['Content-Type' => ['application/json'], 'Set-Cookie' => ['session=xyz', 'b=2']],
        ]);
        $recorder->finishRequest($execution, ['status' => 200]);

        $call = $this->sink->records('outgoing')[0];
        $this->assertSame(['authorization' => '[REDACTED]', 'accept' => 'application/json', 'x-request-id' => 'r-1'], $call['request_headers']);
        $this->assertSame(['content-type' => 'application/json', 'set-cookie' => '[REDACTED]'], $call['response_headers']);
        $this->assertArrayNotHasKey('response_body', $call);
    }

    public function testHeadersNamedLikeSecretsAreMasked(): void
    {
        $recorder = $this->recorder(['redact' => [
            'headers' => ['authorization'],
            'query_keys' => ['key', 'apikey', 'signature', 'auth', 'passwd', 'session'],
            'replacement' => '[REDACTED]',
        ]]);

        $out = $recorder->headers([
            'key' => 'Riz-Remit',
            'host' => 'back-office.frontier-pay.com',
            'email' => 'ops@rizremit.com',
            'password' => 'Riz@Fpay~2!2!3#',
            'X-Api-Key' => 'k-1',
            'X-Auth-Token' => 't',
            'X-Signature' => 's',
            'Api-Secret' => 's',
            'X-Session-Id' => 'abc',
            'User-Agent' => 'GuzzleHttp/7',
            'Accept' => 'application/json',
        ], $meta);

        $this->assertSame([
            'key' => '[REDACTED]',
            'host' => 'back-office.frontier-pay.com',
            'email' => '[REDACTED]',
            'password' => '[REDACTED]',
            'x-api-key' => '[REDACTED]',
            'x-auth-token' => '[REDACTED]',
            'x-signature' => '[REDACTED]',
            'api-secret' => '[REDACTED]',
            'x-session-id' => '[REDACTED]',
            'user-agent' => 'GuzzleHttp/7',
            'accept' => 'application/json',
        ], $out);
        $this->assertTrue($meta['redacted']);
    }

    public function testOutgoingHeadersCanBeTurnedOff(): void
    {
        $recorder = $this->recorder(['outgoing.headers' => false]);
        $execution = $recorder->startRequest();
        $recorder->recordOutgoing(['method' => 'GET', 'url' => 'https://api.bank.example/rates', 'status' => 200, 'request_headers' => ['Accept' => 'json']]);
        $recorder->finishRequest($execution, ['status' => 200]);

        $this->assertArrayNotHasKey('request_headers', $this->sink->records('outgoing')[0]);
    }

    public function testOutgoingBodyModes(): void
    {
        $ok = ['method' => 'POST', 'url' => 'https://api.bank.example/verify', 'status' => 200, 'request_body' => '{"a":1}', 'response_body' => '{"ok":true}'];
        $failed = ['status' => 502] + $ok;

        foreach ([
            'errors' => [false, true],
            'always' => [true, true],
            'never' => [false, false],
        ] as $mode => [$okKept, $failedKept]) {
            $recorder = $this->recorder(['outgoing.bodies' => $mode]);
            $execution = $recorder->startRequest();
            $recorder->recordOutgoing($ok);
            $recorder->recordOutgoing($failed);
            $recorder->finishRequest($execution, ['status' => 200]);
            [$first, $second] = $this->sink->records('outgoing');

            $this->assertSame($okKept, isset($first['response_body']), "{$mode}: successful call");
            $this->assertSame($failedKept, isset($second['response_body']), "{$mode}: failed call");
            $this->assertSame($okKept, $recorder->keepsOutgoingBodies(false));
        }

        // The v2.0.0 flag still works when bodies is not set.
        $this->assertFalse($this->recorder(['outgoing' => ['enabled' => true, 'bodies_on_error' => false]])->keepsOutgoingBodies(true));
        $this->assertTrue($this->recorder(['outgoing' => ['enabled' => true, 'bodies_on_error' => true]])->keepsOutgoingBodies(true));
    }

    public function testABodyIsMaskedWholeAndOnlyThenCut(): void
    {
        $recorder = $this->recorder(['outgoing.bodies' => 'always']);
        $execution = $recorder->startRequest();
        $rows = implode(',', array_fill(0, 1500, '{"id":1,"name":"x"}'));
        $json = '{"rows":[' . $rows . '],"card_number":"4111111111111111","otp":"SYNTHOTP"}';
        $recorder->recordOutgoing([
            'method' => 'GET', 'url' => 'https://api.bank.example/statement', 'status' => 200,
            'response_body' => $json, 'response_body_size' => strlen($json), 'response_body_type' => 'application/json',
            'request_body' => '{"from":"2026-09-01"}', 'request_body_size' => 21,
        ]);
        $recorder->finishRequest($execution, ['status' => 200]);

        $call = $this->sink->records('outgoing')[0];
        [$kept, $note] = explode("\n…", $call['response_body']);
        $this->assertLessThanOrEqual(8192, strlen($kept));
        $this->assertSame('[cut: kept 8 KB of 29.4 KB]', $note);
        $this->assertStringNotContainsString('SYNTHOTP', json_encode($call));
        $this->assertSame('{"from":"2026-09-01"}', $call['request_body'], 'a small body is kept whole, unmarked');
    }

    public function testABodyOverTheParseLimitIsNotKept(): void
    {
        $recorder = $this->recorder(['outgoing.bodies' => 'always']);
        $execution = $recorder->startRequest();
        $json = '{"card_number":"4111111111111111","rows":[' . implode(',', array_fill(0, 60000, '{"id":1,"name":"x"}')) . ']}';
        $recorder->recordOutgoing([
            'method' => 'GET', 'url' => 'https://api.bank.example/statement', 'status' => 200,
            // What BodyReader hands over: the first 64 KB + 1 byte and the full size.
            'response_body' => substr($json, 0, 65537), 'response_body_size' => strlen($json),
        ]);
        $recorder->finishRequest($execution, ['status' => 200]);

        $call = $this->sink->records('outgoing')[0];
        $this->assertSame('[json body not kept, 1.1 MB: over the 64 KB parse limit]', $call['response_body']);
        $this->assertSame(['response_body' => 'json body not kept, 1.1 MB: over the 64 KB parse limit'], $call['_cut']);
    }

    public function testNotesFromTheBodyReaderPassThrough(): void
    {
        $recorder = $this->recorder(['outgoing.bodies' => 'always']);
        $execution = $recorder->startRequest();
        $recorder->recordOutgoing(['method' => 'GET', 'url' => 'https://files.example/a.pdf', 'status' => 200, 'response_body_note' => '[application/pdf body not kept, 3 MB]', 'response_body_size' => 3145728]);
        $recorder->finishRequest($execution, ['status' => 200]);

        $this->assertSame('[application/pdf body not kept, 3 MB]', $this->sink->records('outgoing')[0]['response_body']);
    }

    public function testAnOversizedCallKeepsItsHeadersAndShortensItsBodies(): void
    {
        $recorder = $this->recorder(['outgoing.bodies' => 'always', 'spool.max_record_bytes' => 16384]);
        $execution = $recorder->startRequest();
        // Quotes and newlines double in JSON: 8 KB each becomes ~16 KB each.
        $escaped = json_encode(array_fill(0, 1500, "\"\n\"\n"));
        $recorder->recordOutgoing([
            'method' => 'POST', 'url' => 'https://api.bank.example/soap', 'status' => 500,
            'request_headers' => ['Accept' => 'text/xml', 'X-Trace' => 'abc'],
            'request_body' => $escaped, 'response_body' => $escaped,
        ]);
        $recorder->finishRequest($execution, ['status' => 200]);

        $call = $this->sink->records('outgoing')[0];
        $this->assertTrue($call['_truncated']);
        $this->assertSame(['accept' => 'text/xml', 'x-trace' => 'abc'], $call['request_headers']);
        $this->assertStringEndsWith('…[cut to fit the record size limit]', $call['response_body']);
        $this->assertLessThan(3000, strlen($call['response_body']));
    }

    public function testHugeHeadersAreDroppedBeforeTheRecordIsLost(): void
    {
        $recorder = $this->recorder(['spool.max_record_bytes' => 32768]);
        $execution = $recorder->startRequest();
        $headers = [];
        for ($i = 0; $i < 150; $i++) {
            $headers["x-h{$i}"] = str_repeat('v', 900);
        }
        $recorder->recordOutgoing(['method' => 'GET', 'url' => 'https://api.example/x', 'status' => 200, 'ms' => 12.0, 'response_headers' => $headers]);
        $recorder->finishRequest($execution, ['status' => 200]);

        $line = $this->sink->records('outgoing')[0];
        $this->assertArrayNotHasKey('response_headers', $line);
        $this->assertSame('api.example', $line['host'], 'the call itself is still recorded');
        $this->assertSame(12.0, $line['ms']);
    }

    private function queryRecorder(array $overrides = []): Recorder
    {
        return $this->recorder($overrides + [
            'redact' => [
                'replacement' => '[REDACTED]',
                'keys' => ['password', 'token', 'secret', 'card', 'pin'],
                'patterns' => ['card' => '/\b(?:\d[ -]?){15}\d\b/', 'email' => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/'],
                'query_keys' => ['key', 'api_key', 'apikey', 'signature', 'otp'],
                'headers' => ['authorization', 'cookie'],
            ],
        ]);
    }

    public function testRequestQueryParametersAreKeptMasked(): void
    {
        $recorder = $this->queryRecorder();
        $params = [
            'query' => 'asd',
            'dsa' => 'ks',
            'access_token' => 'eyJabc',
            'api_key' => 'k-123',
            'email' => 'ali@example.com',
            'ids' => ['1', '2'],
            'note' => 'card 4111 1111 1111 1111',
        ];
        $this->assertSame([
            'query' => 'asd',
            'dsa' => 'ks',
            'access_token' => '[REDACTED]',
            'api_key' => '[REDACTED]',
            'email' => '[REDACTED]',
            'ids' => '["1","2"]',
            'note' => 'card [REDACTED]',
        ], $recorder->queryParams($params));

        $execution = $recorder->startRequest();
        $recorder->finishRequest($execution, ['method' => 'GET', 'url' => 'https://app.test/debug-db', 'status' => 200, 'query' => $recorder->queryParams(['query' => 'asd', 'dsa' => 'ks'])]);
        $request = $this->sink->records('request')[0];
        $this->assertSame('https://app.test/debug-db', $request['url']);
        $this->assertSame(['query' => 'asd', 'dsa' => 'ks'], $request['query']);
    }

    public function testAHugeQueryStringIsBounded(): void
    {
        $recorder = $this->queryRecorder();

        // 40 KB in one value, then 200 parameters.
        $params = ['blob' => str_repeat('x', 40000)];
        for ($i = 0; $i < 200; $i++) {
            $params["p{$i}"] = str_repeat('y', 100);
        }
        $out = $recorder->queryParams($params);

        $this->assertSame(500, strlen($out['blob']));
        $this->assertLessThanOrEqual(51, count($out));
        $this->assertLessThanOrEqual(8192 + 100, strlen(implode('', array_keys($out)) . implode('', $out)));
        $this->assertMatchesRegularExpression('/^\[\d+ more parameters not kept\]$/', $out['…']);
    }

    public function testOutgoingCallsCarryTheirQueryParameters(): void
    {
        $recorder = $this->queryRecorder();
        $execution = $recorder->startRequest();
        $recorder->recordOutgoing(['method' => 'GET', 'url' => 'https://api.bank.example/rates?from=USD&to=PKR&apikey=s3cr3t', 'status' => 200]);
        $recorder->recordOutgoing(['method' => 'GET', 'url' => 'https://api.bank.example/rates', 'status' => 200]);
        $recorder->finishRequest($execution, ['status' => 200]);

        [$with, $without] = $this->sink->records('outgoing');
        $this->assertSame('/rates', $with['path']);
        $this->assertSame(['from' => 'USD', 'to' => 'PKR', 'apikey' => '[REDACTED]'], $with['query']);
        $this->assertArrayNotHasKey('query', $without);
    }

    public function testMaskedAndCutFieldsAreTagged(): void
    {
        $recorder = $this->queryRecorder(['outgoing.bodies' => 'always']);
        $execution = $recorder->startRequest();
        $recorder->recordOutgoing([
            'method' => 'POST', 'url' => 'https://api.bank.example/pay?apikey=s&from=USD', 'status' => 200,
            'request_headers' => ['Authorization' => 'Bearer x', 'Accept' => 'json'],
            'response_headers' => ['Content-Type' => 'json'],
            'request_body' => '{"card_number":"4111111111111111","amount":5}',
            'response_body' => str_repeat('a', 40000), 'response_body_size' => 1048576,
        ]);
        $recorder->finishRequest($execution, ['status' => 200]);

        $call = $this->sink->records('outgoing')[0];
        $this->assertEqualsCanonicalizing(['query', 'request_headers', 'request_body'], $call['_redacted']);
        $this->assertSame(['response_body' => 'text body not kept, 1 MB'], $call['_cut']);
    }

    public function testCleanFieldsCarryNoTags(): void
    {
        $recorder = $this->queryRecorder(['outgoing.bodies' => 'always']);
        $execution = $recorder->startRequest();
        $recorder->recordOutgoing(['method' => 'GET', 'url' => 'https://api.example/rates?from=USD', 'status' => 200, 'request_headers' => ['Accept' => 'json'], 'response_body' => '{"rate":1}']);
        $recorder->finishRequest($execution, ['status' => 200]);

        $call = $this->sink->records('outgoing')[0];
        $this->assertArrayNotHasKey('_redacted', $call);
        $this->assertArrayNotHasKey('_cut', $call);
    }

    public function testLogTagsSayWhatWasMaskedOrCut(): void
    {
        $recorder = $this->recorder(['logs.level' => 'info']);
        $recorder->recordLog('error', 'Charge failed for ali@example.com ' . str_repeat('x', 5000), ['password' => 'p', 'order' => 7]);
        $recorder->recordLog('info', 'All good', ['order' => 7]);

        [$masked, $clean] = $this->sink->records('log');
        $this->assertEqualsCanonicalizing(['message', 'context'], $masked['_redacted']);
        $this->assertMatchesRegularExpression('/^kept [\d,]+ of 5,0\d\d characters$/', $masked['_cut']['message']);
        $this->assertArrayNotHasKey('_redacted', $clean);
        $this->assertArrayNotHasKey('_cut', $clean);
    }

    public function testRecordsShrunkToFitSayWhatWasDropped(): void
    {
        $recorder = $this->recorder(['outgoing.bodies' => 'always', 'spool.max_record_bytes' => 16384]);
        $execution = $recorder->startRequest();
        $escaped = json_encode(array_fill(0, 1500, "\"\n\"\n"));
        $recorder->recordOutgoing(['method' => 'POST', 'url' => 'https://api.example/soap', 'status' => 500, 'request_body' => $escaped, 'response_body' => $escaped]);
        $recorder->finishRequest($execution, ['status' => 200]);

        $call = $this->sink->records('outgoing')[0];
        $this->assertSame('shortened to 2 KB to fit the 16 KB record limit', $call['_cut']['response_body']);
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

    public function testAnExceptionCarriesTheCodeAroundTheLineThatThrew(): void
    {
        $recorder = $this->recorder(['exceptions.snippet_context' => 2]);
        $line = __LINE__ + 1;
        $recorder->recordException(new \RuntimeException('x'));

        $snippet = $this->sink->records('exception')[0]['snippet'];
        $this->assertSame($line - 2, $snippet['start']);
        $this->assertSame($line, $snippet['line']);
        $this->assertCount(5, $snippet['lines']);
        $this->assertStringContainsString(
            'recordException(new \RuntimeException',
            $snippet['lines'][$snippet['line'] - $snippet['start']]
        );
    }

    public function testSnippetsCanBeTurnedOffSoNoCodeLeavesTheServer(): void
    {
        $recorder = $this->recorder(['exceptions.snippets' => false]);
        $recorder->recordException(new \RuntimeException('x'));

        $this->assertArrayNotHasKey('snippet', $this->sink->records('exception')[0]);
    }

    /**
     * Source lines quote the messages they throw, so hiding messages hides
     * the code as well.
     */
    public function testHidingExceptionMessagesAlsoHidesTheCode(): void
    {
        $recorder = $this->recorder(['exceptions.messages' => false]);
        $recorder->recordException(new \RuntimeException('Customer 3520212345671 rejected'));

        $this->assertArrayNotHasKey('snippet', $this->sink->records('exception')[0]);
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
        $this->assertSame($jobRecord['trace'], $this->sink->records('exception')[0]['trace']);
    }

    /**
     * The queue worker reports the exception again once the job has ended.
     * That copy knows nothing about the job, so keeping it would leave the
     * job page without a stack trace and count the failure twice.
     */
    public function testTheWorkerReportingTheSameExceptionAfterTheJobIsNotRecordedTwice(): void
    {
        $recorder = $this->recorder();
        $exception = new \RuntimeException('Onfido timeout');

        $job = $recorder->startJob(['class' => 'App\\Jobs\\RunSanctions', 'job_id' => '9'], null);
        $recorder->finishJob($job, 'failed', $exception);
        $recorder->recordLog('error', 'Onfido timeout', ['exception' => $exception]);

        $exceptions = $this->sink->records('exception');
        $this->assertCount(1, $exceptions);
        $this->assertSame($this->sink->records('job')[0]['trace'], $exceptions[0]['trace']);
        $this->assertSame([], $this->sink->records('log'));

        // A different exception outside any execution is still recorded.
        $recorder->recordLog('error', 'other', ['exception' => new \LogicException('other')]);
        $this->assertCount(2, $this->sink->records('exception'));
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

    public function testARequestStoppedByPhpStillSaysWhichRequestItWas(): void
    {
        $recorder = $this->recorder();
        $execution = $recorder->startRequest();
        // What the middleware sets when the request starts.
        $execution->describe = function (): array {
            return [
                'method' => 'GET',
                'url' => 'https://back-office.test/rates/auto',
                'route' => '/rates/auto',
                'action' => 'App\\Http\\Livewire\\Rate\\AutoRates',
                'ip' => '203.0.113.9',
                'user' => 12,
                'status' => 200, // never trusted: the request did not answer
            ];
        };
        $recorder->shutdown();

        $request = $this->sink->records('request')[0];
        $this->assertTrue($request['interrupted']);
        $this->assertNull($request['status']);
        $this->assertSame('GET', $request['method']);
        $this->assertSame('/rates/auto', $request['route']);
        $this->assertSame('https://back-office.test/rates/auto', $request['url']);
        $this->assertSame('App\\Http\\Livewire\\Rate\\AutoRates', $request['action']);
        $this->assertSame(12, $request['user']);
    }

    public function testAFailingDescriptionDoesNotLoseTheRequest(): void
    {
        $recorder = $this->recorder();
        $execution = $recorder->startRequest();
        $execution->describe = function (): array {
            throw new \RuntimeException('container already gone');
        };
        $recorder->shutdown();

        $this->assertTrue($this->sink->records('request')[0]['interrupted']);
    }

    public function testTheKillSwitchIsCheckedWhileRunning(): void
    {
        $off = false;
        $this->sink = new MemorySink();
        $recorder = new Recorder(['queries' => ['enabled' => true], 'logs' => ['enabled' => true, 'level' => 'debug']], $this->sink, new Redactor(), new Location(dirname(__DIR__)),
            function () {
                return $this->now;
            },
            null,
            function () use (&$off) {
                return $off;
            }
        );

        $running = $recorder->startJob(['class' => 'App\\Jobs\\Long', 'job_id' => '1'], null);
        $off = true;
        $this->now += 10; // past the check interval
        $recorder->recordLog('info', 'still running', []);
        $recorder->finishJob($running, 'processed');
        $this->assertSame([], $this->sink->writes, 'an execution in progress when switched off writes nothing');
        $this->assertNull($recorder->startJob(['class' => 'App\\Jobs\\Next', 'job_id' => '2'], null));
        $this->assertNull($recorder->startRequest());

        $off = false;
        $this->now += 10;
        $this->assertNotNull($recorder->startRequest(), 'log-monitor:on resumes without a restart');
    }

    public function testDistinctStatementsAreCapped(): void
    {
        $recorder = $this->recorder(['queries.mode' => 'summary', 'queries.max_statements' => 50, 'queries.slow_ms' => 0, 'queries.repeated_threshold' => 0]);
        $execution = $recorder->startRequest();
        for ($i = 0; $i < 20000; $i++) {
            $recorder->recordQuery("select * from t where note = 'value {$i}' /* " . str_repeat('x', 200) . ' */', 1.0, 'mysql', 'mysql');
        }
        $this->assertCount(50, $execution->statements);
        $this->assertLessThan(64 * 1024, $execution->statementBytes);
        $recorder->finishRequest($execution, ['status' => 200]);

        $request = $this->sink->records('request')[0];
        $this->assertSame(20000, $request['queries'], 'every query is still counted');
        $this->assertSame(19950, $request['dropped']['statements']);
    }

    public function testARequestStopsBufferingAtTheMemoryBudget(): void
    {
        $recorder = $this->recorder(['logs.level' => 'debug', 'logs.max_per_request' => 10000, 'memory.max_buffer_bytes' => 50000]);
        $execution = $recorder->startRequest();
        for ($i = 0; $i < 200; $i++) {
            $recorder->recordLog('info', str_repeat('m', 900), []);
        }
        $this->assertLessThanOrEqual(50000, $execution->bufferedBytes);
        $recorder->finishRequest($execution, ['status' => 200]);

        $request = $this->sink->records('request')[0];
        $this->assertSame(200, $request['logs']);
        $this->assertGreaterThan(100, $request['dropped']['memory']);
        $this->assertSame(200 - $request['dropped']['memory'], count($this->sink->records('log')));
    }

    public function testAJobFlushesAtTheMemoryBudget(): void
    {
        $recorder = $this->recorder(['logs.level' => 'debug', 'logs.max_per_request' => 10000, 'memory.max_buffer_bytes' => 50000, 'jobs.flush_records' => 0, 'jobs.flush_seconds' => 0]);
        $job = $recorder->startJob(['class' => 'App\\Jobs\\Import', 'job_id' => '9'], null);
        for ($i = 0; $i < 200; $i++) {
            $recorder->recordLog('info', str_repeat('m', 900), []);
            $this->assertLessThanOrEqual(50000, $job->bufferedBytes);
        }
        $recorder->finishJob($job, 'processed');

        $this->assertCount(200, $this->sink->records('log'), 'nothing lost');
        $this->assertGreaterThan(1, $this->sink->records('job')[0]['partial_flushes']);
    }

    public function testBodiesAreKeptOnlyForListedHosts(): void
    {
        $recorder = $this->recorder(['outgoing.bodies' => 'always', 'outgoing.body_hosts' => ['api.bank.example', '*.partner.example']]);
        $this->assertTrue($recorder->keepsOutgoingBodies(false, 'api.bank.example'));
        $this->assertTrue($recorder->keepsOutgoingBodies(false, 'eu.partner.example'));
        $this->assertFalse($recorder->keepsOutgoingBodies(false, 'partner.example.evil.test'));
        $this->assertFalse($recorder->keepsOutgoingBodies(false, 'other.example'));
    }

    public function testMessagesAndContextCanBeLeftOut(): void
    {
        $recorder = $this->recorder(['logs.level' => 'debug', 'logs.context' => false, 'exceptions.messages' => false]);
        $recorder->recordLog('info', 'hello', ['customer' => 'Bob']);
        $recorder->recordException(new \RuntimeException('Customer Bob Khan not found'));

        $log = $this->sink->records('log')[0];
        $this->assertSame([], $log['context']);
        $this->assertSame('not captured (logs.context is off)', $log['_cut']['context']);
        $this->assertSame('[message not captured]', $this->sink->records('exception')[0]['message']);
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
