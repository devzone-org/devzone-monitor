<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\Transport\HttpTransport;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class HttpTransportTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private $history = [];

    private function transport(array $responses, string $endpoint = 'https://monitor.test/api/v2/ingest', bool $allowHttp = false): HttpTransport
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new HttpTransport($endpoint, 'dzm_key.secret', 10, $allowHttp, true, $stack);
    }

    public function testSendsGzippedJsonWithBearerHeader(): void
    {
        $result = $this->transport([new Response(202)])->send('{"v":2,"records":[]}');

        $this->assertTrue($result['ok']);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('Bearer dzm_key.secret', $request->getHeaderLine('Authorization'));
        $this->assertSame('gzip', $request->getHeaderLine('Content-Encoding'));
        $this->assertSame('{"v":2,"records":[]}', gzdecode((string) $request->getBody()));
        $this->assertStringNotContainsString('secret', (string) $request->getUri(), 'key never in the URL');
        $this->assertFalse($this->history[0]['options']['allow_redirects']);
        $this->assertTrue($this->history[0]['options']['verify']);
    }

    public function testRetryableAndPermanentFailuresAreDistinguished(): void
    {
        $this->assertTrue($this->transport([new Response(503)])->send('{}')['retryable']);
        $this->assertTrue($this->transport([new Response(429)])->send('{}')['retryable']);
        $this->assertFalse($this->transport([new Response(422)])->send('{}')['retryable']);
        $this->assertFalse($this->transport([new Response(401)])->send('{}')['retryable']);

        $failed = $this->transport([new ConnectException('Connection refused for dzm_key.secret', new Request('POST', 'https://monitor.test'))])->send('{}');
        $this->assertTrue($failed['retryable']);
        $this->assertStringNotContainsString('dzm_key.secret', (string) $failed['error'], 'key scrubbed from errors');
    }

    public function testOnlyHttpsUnlessExplicitlyAllowedInDevelopment(): void
    {
        $this->assertFalse($this->transport([], 'http://monitor.test/api')->send('{}')['ok']);
        $this->assertSame([], $this->history, 'refused before any request');
        $this->assertTrue($this->transport([new Response(202)], 'http://monitor.test/api', true)->send('{}')['ok']);

        $this->assertTrue(HttpTransport::httpAllowedFor(true, 'local'));
        $this->assertFalse(HttpTransport::httpAllowedFor(true, 'production'));
        $this->assertFalse(HttpTransport::httpAllowedFor(false, 'local'));
        $this->assertFalse(HttpTransport::isSecureEndpoint('https://user:pass@monitor.test/'));
    }

    public function testDebugOutputHidesTheKey(): void
    {
        $this->assertStringNotContainsString('secret', print_r($this->transport([]), true));
    }
}
