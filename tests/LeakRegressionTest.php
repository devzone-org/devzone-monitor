<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\Capture\Location;
use DevZone\LogMonitor\Capture\Recorder;
use DevZone\LogMonitor\Http\Middleware\CaptureRequests;
use DevZone\LogMonitor\Spool\SpoolDirectory;
use DevZone\LogMonitor\Spool\SpoolWriter;
use DevZone\LogMonitor\Support\Redactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\TestCase;

/**
 * Release gate for the v2.0.5 audit: every leak reproduced there is fed
 * through the real recorder and spool writer, with capture switched fully
 * on, and the bytes that end up on disk are searched for the secrets.
 *
 * Secrets are letters only where they pass through SQL, so masking of
 * numbers can never hide a leak by accident.
 */
final class LeakRegressionTest extends TestCase
{
    /** @var string */
    private $dir;

    /** @var float */
    private $now = 1789900000.0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lm-leak-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->dir . '/*') ?: [], glob($this->dir . '/failed/*') ?: []) as $file) {
            @unlink($file);
        }
        @rmdir($this->dir . '/failed');
        @rmdir($this->dir);
    }

    private function recorder(array $overrides = []): Recorder
    {
        $config = Recorder::applyOverrides([
            'enabled' => true,
            'requests' => ['enabled' => true, 'bodies' => ['on_status' => 500, 'max_bytes' => 8192]],
            'queries' => ['enabled' => true, 'mode' => 'all', 'slow_ms' => 100, 'repeated_threshold' => 2],
            'outgoing' => ['enabled' => true, 'bodies' => 'always', 'headers' => true, 'max_bytes' => 8192],
            'logs' => ['enabled' => true, 'level' => 'debug'],
            'exceptions' => ['enabled' => true],
            'jobs' => ['enabled' => true],
            'spool' => ['max_record_bytes' => 32768],
        ], $overrides);

        return new Recorder(
            $config,
            new SpoolWriter(new SpoolDirectory($this->dir), 0, 0, 0, 0),
            Redactor::fromConfig([]),
            new Location(dirname(__DIR__)),
            function () {
                return $this->now;
            }
        );
    }

    private function spoolBytes(): string
    {
        $bytes = '';
        foreach (glob($this->dir . '/*.ndjson') ?: [] as $file) {
            $bytes .= file_get_contents($file);
        }
        $this->assertNotSame('', $bytes, 'something was written');

        return $bytes;
    }

    /**
     * @param array<int, string> $secrets
     */
    private function assertNoLeak(array $secrets): void
    {
        $bytes = $this->spoolBytes();
        // Also as JSON would escape them.
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $bytes, "leaked: {$secret}");
            $this->assertStringNotContainsStringIgnoringCase(trim((string) json_encode($secret), '"'), $bytes, "leaked (escaped): {$secret}");
        }
    }

    public function testOutgoingBodiesHeadersAndUrls(): void
    {
        $recorder = $this->recorder();
        $execution = $recorder->startRequest();

        $bigRows = implode(',', array_fill(0, 1600, '{"id":1,"name":"row"}'));
        $calls = [
            // Complete JSON with keys the v2.0.5 defaults missed.
            ['body' => '{"api_key":"LEAKAPIKEY","otp":"LEAKOTP","session_id":"LEAKSESSIONID","clientSecret":"LEAKCLIENTSECRET","data":{"accessToken":"LEAKACCESSTOKEN","privateKey":"LEAKPRIVATEKEY"},"cvc":"LEAKCVC","passcode":"LEAKPASSCODE"}', 'type' => 'application/json'],
            // A secret well past what v2.0.5 read, and a value with spaces.
            ['body' => '{"rows":[' . $bigRows . '],"password":"LEAK WITH SPACES"}', 'type' => 'application/json'],
            // camelCase and URL-encoded form keys.
            ['body' => 'user%5Bpassword%5D=LEAKFORMPASS&userPin=LEAKFORMPIN&amount=5', 'type' => 'application/x-www-form-urlencoded'],
            // XML: CDATA, a value nested under a sensitive element, an attribute.
            ['body' => '<Envelope><Body><Login mode="x" password="LEAKATTR"><Credentials><User>bob</User><Pass>LEAKXMLNESTED</Pass></Credentials><Pin><![CDATA[LEAKCDATA]]></Pin></Login></Body></Envelope>', 'type' => 'text/xml'],
            ['body' => '<soap:Envelope><soap:Body><ns:Verify><ns:CardPin>LEAKSOAPPIN</ns:CardPin></ns:Verify></soap:Body></soap:Envelope>', 'type' => 'application/soap+xml'],
            // DOCTYPE, broken JSON, plain text, HTML: not kept at all.
            ['body' => '<?xml version="1.0"?><!DOCTYPE a [<!ENTITY e "LEAKDOCTYPE">]><a>&e;</a>', 'type' => 'text/xml'],
            ['body' => '{"note":"LEAKBROKENJSON", "password": "LEAKBROKENPASS"', 'type' => 'application/json'],
            ['body' => 'token LEAKPLAINTEXT sent', 'type' => 'text/plain'],
            ['body' => '<html><body>LEAKHTML</body></html>', 'type' => 'text/html'],
        ];
        foreach ($calls as $call) {
            $recorder->recordOutgoing([
                'method' => 'POST',
                'url' => 'https://api.bank.example/v1/verify',
                'status' => 500,
                'request_body' => $call['body'],
                'request_body_size' => strlen($call['body']),
                'request_body_type' => $call['type'],
            ]);
        }

        // Only the first 64 KB + 1 of a larger body is read: never kept.
        $huge = '{"rows":[' . str_repeat('{"id":1},', 9000) . '],"password":"LEAKHUGE"}';
        $recorder->recordOutgoing(['method' => 'GET', 'url' => 'https://api.bank.example/x', 'status' => 500,
            'response_body' => substr($huge, 0, 65537), 'response_body_size' => strlen($huge), 'response_body_type' => 'application/json']);

        // Tokens in paths, signed URLs, query strings and URL-bearing headers.
        $recorder->recordOutgoing([
            'method' => 'GET',
            'url' => 'https://api.bank.example/v1/reset/LEAKPATHTOKEN8f3a9c2e7b1d/files/9f86d081884c7d659a2feaa0c55ad015/download?signature=LEAKSIGQUERY&otp=LEAKQOTP&filter[password]=LEAKNESTEDQ&page=2',
            'status' => 200,
            'request_headers' => [
                'X-Api-Key' => 'LEAKHEADERKEY',
                'X-Session-Id' => 'LEAKSESSHDR',
                'Referer' => 'https://app.test/password/reset/LEAKREFERERPATH4b1c9d2e?token=LEAKREFERERQ',
                'X-Callback' => 'https://user:LEAKURLPASS@hooks.example/cb',
                'Accept' => 'application/json',
            ],
            'response_headers' => ['Location' => 'https://app.test/verify?code=LEAKLOCATIONCODE', 'Set-Cookie' => 'laravel_session=LEAKCOOKIE'],
        ]);
        $recorder->finishRequest($execution, ['method' => 'GET', 'url' => 'https://app.test/password/reset/9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08', 'status' => 200]);

        $this->assertNoLeak([
            'LEAKAPIKEY', 'LEAKOTP', 'LEAKSESSIONID', 'LEAKCLIENTSECRET', 'LEAKACCESSTOKEN', 'LEAKPRIVATEKEY', 'LEAKCVC', 'LEAKPASSCODE',
            'LEAK WITH SPACES', 'SPACES',
            'LEAKFORMPASS', 'LEAKFORMPIN',
            'LEAKATTR', 'LEAKXMLNESTED', 'LEAKCDATA', 'LEAKSOAPPIN',
            'LEAKDOCTYPE', 'LEAKBROKENJSON', 'LEAKBROKENPASS', 'LEAKPLAINTEXT', 'LEAKHTML', 'LEAKHUGE',
            'LEAKPATHTOKEN', '9f86d081884c7d659a2feaa0c55ad015', 'LEAKSIGQUERY', 'LEAKQOTP', 'LEAKNESTEDQ',
            'LEAKHEADERKEY', 'LEAKSESSHDR', 'LEAKREFERERPATH', 'LEAKREFERERQ', 'LEAKURLPASS', 'LEAKLOCATIONCODE', 'LEAKCOOKIE',
        ]);

        // And the useful parts are still there.
        $bytes = $this->spoolBytes();
        $this->assertStringContainsString('https://app.test/password/reset/{token}', $bytes);
        $this->assertStringContainsString('/v1/reset/{token}/files/{token}/download', $bytes);
        $this->assertStringContainsString('"page":"2"', $bytes);
    }

    public function testLogsContextAndExceptions(): void
    {
        $recorder = $this->recorder();
        $execution = $recorder->startRequest();

        // A secret straddling the 4,000 character cut.
        $recorder->recordLog('error', str_repeat('x', 3990) . ' password=LEAKCUTLOG', []);
        $recorder->recordLog('error', str_repeat('y', 3993) . ' card 4111 1111 1111 1111 declined', []);
        $recorder->recordLog('warning', 'Login failed userPassword: "LEAK LOG SPACES" and \"otp\":\"LEAKESCAPEDOTP\"', [
            'userPassword' => 'LEAKCTXPASS',
            'payload' => '{"otp":"LEAKCTXJSON","ok":true}',
            'callback' => 'https://x.example/cb?code=LEAKCTXCODE',
            'long' => str_repeat('z', 3995) . ' token=LEAKCTXCUT',
        ]);
        $recorder->recordException(new \RuntimeException('Failed calling https://api.x.example/cb?access_token=LEAKEXCTOKEN with {"apiKey":"LEAKEXCAPIKEY"} for mysql://root:LEAKDSNPASS@db/app'));
        $recorder->finishRequest($execution, ['status' => 500]);

        $this->assertNoLeak([
            'LEAKCUTLOG', '4111 1111', '4111111', 'LEAK LOG SPACES', 'SPACES', 'LEAKESCAPEDOTP',
            'LEAKCTXPASS', 'LEAKCTXJSON', 'LEAKCTXCODE', 'LEAKCTXCUT',
            'LEAKEXCTOKEN', 'LEAKEXCAPIKEY', 'LEAKDSNPASS',
        ]);
    }

    public function testSqlLiteralsAndCommentsNeverReachTheSpool(): void
    {
        $recorder = $this->recorder();
        $execution = $recorder->startRequest();
        $queries = [
            ['pgsql', 'select $$LEAKDOLLAR$$ as a'],
            ['pgsql', 'select $body$LEAKTAGGED it\'s$body$ as a'],
            ['pgsql', "select E'LEAKESTRING\\' still' as a"],
            ['pgsql', "select 1 -- LEAKLINECOMMENT"],
            ['pgsql', "/* LEAKBLOCKCOMMENT */ select 1"],
            ['mysql', 'select 1 # LEAKHASHCOMMENT'],
            ['mysql', 'select * from users where name like "%LEAKDOUBLELIKE%" and note in ("LEAKDOUBLEIN", \'LEAKSINGLEIN\')'],
            ['mysql', "select * from t where a = _utf8mb4'LEAKINTRODUCER' and b = X'4C45414B'"],
            ['mysql', "select * from t where a = 'LEAKUNCLOSED"],
            ['mysql', "select '" . str_repeat('a', 9990) . "LEAKLONGLITERAL' as a"],
            ['', "select 'LEAKNODRIVER' as a, \"LEAKNODRIVERDOUBLE\" as b"],
            ['sqlsrv', "select N'LEAKNSTRING' as a"],
        ];
        foreach ($queries as $query) {
            $recorder->recordQuery($query[1], 150.0, $query[0] === '' ? 'default' : $query[0], $query[0]);
            $recorder->recordQuery($query[1], 150.0, $query[0] === '' ? 'default' : $query[0], $query[0]);
        }
        $recorder->finishRequest($execution, ['status' => 200]);

        $this->assertNoLeak([
            'LEAKDOLLAR', 'LEAKTAGGED', 'LEAKESTRING', 'still', 'LEAKLINECOMMENT', 'LEAKBLOCKCOMMENT', 'LEAKHASHCOMMENT',
            'LEAKDOUBLELIKE', 'LEAKDOUBLEIN', 'LEAKSINGLEIN', 'LEAKINTRODUCER', '4C45414B', 'LEAKUNCLOSED', 'LEAKLONGLITERAL',
            'LEAKNODRIVER', 'LEAKNSTRING',
        ]);
        $bytes = $this->spoolBytes();
        $this->assertStringContainsString('"t":"slow-query"', $bytes);
        $this->assertStringContainsString('"t":"repeated-query"', $bytes);
        $this->assertStringContainsString('[sql not kept: could not be parsed safely]', $bytes);
    }

    public function testNoSqlTextAtAllWhenCaptureSqlIsOff(): void
    {
        $recorder = $this->recorder(['queries.capture_sql' => false]);
        $execution = $recorder->startRequest();
        $recorder->recordQuery('select * from payroll_secretive_table where id = ?', 150.0, 'mysql', 'mysql');
        $recorder->recordQuery('select * from payroll_secretive_table where id = ?', 150.0, 'mysql', 'mysql');
        $recorder->finishRequest($execution, ['status' => 200]);

        $this->assertNoLeak(['payroll_secretive_table']);
        $this->assertStringContainsString('[sql not captured]', $this->spoolBytes());
    }

    public function testIncomingRequestsThroughTheMiddleware(): void
    {
        $recorder = $this->recorder();
        $middleware = new CaptureRequests($recorder, Redactor::fromConfig([]));

        $token = 'LEAKRESETTOKENab12cd34ef56';
        $request = Request::create('/password/reset/' . $token . '?email=bob@example.com&session_id=LEAKQSESSION', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_API_KEY' => 'LEAKINHEADER',
            'HTTP_REFERER' => 'https://app.test/reset?token=LEAKINREFERER',
            'HTTP_COOKIE' => 'laravel_session=LEAKINCOOKIE',
        ], '{"password":"LEAKINPASS","password_confirmation":"LEAKINCONFIRM","api_key":"LEAKINAPIKEY","profile":{"otp":"LEAKINOTP","name":"Bob"}}');
        $route = new Route(['POST'], 'password/reset/{token}', function () {
        });
        $route->bind($request);
        $request->setRouteResolver(function () use ($route) {
            return $route;
        });

        $middleware->handle($request, function () {
        });
        $middleware->terminate($request, new JsonResponse(['error' => 'x', 'token' => 'LEAKOUTTOKEN', 'debug' => 'secret=LEAKOUTDEBUG'], 500));

        $this->assertNoLeak([
            $token, 'LEAKRESETTOKEN', 'LEAKQSESSION', 'bob@example.com',
            'LEAKINHEADER', 'LEAKINREFERER', 'LEAKINCOOKIE',
            'LEAKINPASS', 'LEAKINCONFIRM', 'LEAKINAPIKEY', 'LEAKINOTP', 'LEAKOUTTOKEN', 'LEAKOUTDEBUG',
        ]);
        $request = json_decode(explode("\n", $this->spoolBytes())[0], true);
        $this->assertSame('http://localhost/password/reset/{token}', $request['url']);
        $this->assertSame('Bob', json_decode($request['request_body'], true)['profile']['name']);
    }

    public function testRawBodiesThroughTheMiddleware(): void
    {
        $recorder = $this->recorder();
        $middleware = new CaptureRequests($recorder, Redactor::fromConfig([]));

        $request = Request::create('/soap', 'POST', [], [], [], ['CONTENT_TYPE' => 'text/xml'],
            '<Envelope><Body><Auth><Username>bob</Username><Password><![CDATA[LEAKRAWXML]]></Password></Auth></Body></Envelope>');
        $middleware->handle($request, function () {
        });
        $middleware->terminate($request, new \Illuminate\Http\Response('<html>LEAKRAWHTML</html>', 500, ['Content-Type' => 'text/html']));

        $this->assertNoLeak(['LEAKRAWXML', 'LEAKRAWHTML']);
        $this->assertStringContainsString('html body not kept', $this->spoolBytes());
    }
}
