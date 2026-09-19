<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\Capture\BodyReader;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\TestCase;

final class BodyReaderTest extends TestCase
{
    public function testAHugeBodyIsReadOnlyUpToTheLimitAndLeftIntactForTheApp(): void
    {
        $body = str_repeat('{"row":"abcdefghij"},', 250000); // ~5.2 MB
        $response = new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], $body));

        $read = BodyReader::read($response, 32768);

        $this->assertSame(32768, strlen($read['body']));
        $this->assertSame(strlen($body), $read['size']);
        $this->assertSame($body, $response->body(), 'the application still gets the whole body');
    }

    public function testTheStreamPositionIsRestored(): void
    {
        $stream = Utils::streamFor('hello world');
        $stream->seek(6);
        $read = BodyReader::read(new PsrResponse(200, [], $stream), 100);

        $this->assertSame('hello world', $read['body']);
        $this->assertSame(6, $stream->tell());
        $this->assertSame('world', $stream->getContents());
    }

    public function testAStreamedBodyIsNeverRead(): void
    {
        $stream = new NoSeekStream(Utils::streamFor(str_repeat('x', 5000)));
        $read = BodyReader::read(new PsrResponse(200, ['Content-Type' => 'text/csv'], $stream), 100);

        $this->assertSame('[streamed body not kept, 4.9 KB]', $read['body']);
        $this->assertSame(0, $stream->tell(), 'nothing was consumed');
        $this->assertSame(5000, strlen($stream->getContents()));
    }

    public function testBinaryBodiesAreNotedNotKept(): void
    {
        $pdf = BodyReader::read(new PsrResponse(200, ['Content-Type' => 'application/pdf; charset=binary'], str_repeat('%', 3 * 1048576)), 100);
        $this->assertSame('[application/pdf body not kept, 3 MB]', $pdf['body']);

        $untyped = BodyReader::read(new PsrResponse(200, [], "PK\x03\x04\0\0zip"), 100);
        $this->assertSame('[binary body not kept, 9 B]', $untyped['body']);

        $upload = new Request(new PsrRequest('POST', 'https://api.example/upload', ['Content-Type' => 'multipart/form-data; boundary=x'], '--x...'));
        $this->assertSame('[multipart/form-data body not kept, 6 B]', BodyReader::read($upload, 100)['body']);
    }

    public function testEmptyBodiesGiveNothing(): void
    {
        $this->assertNull(BodyReader::read(new PsrResponse(204), 100));
        $this->assertNull(BodyReader::read(new Request(new PsrRequest('GET', 'https://api.example/rates')), 100));
        $this->assertNull(BodyReader::read(null, 100));
    }

    public function testThePositionIsRestoredEvenWhenReadingFails(): void
    {
        $inner = Utils::streamFor('hello world');
        $inner->seek(3);
        $stream = new class($inner) implements \Psr\Http\Message\StreamInterface {
            use \GuzzleHttp\Psr7\StreamDecoratorTrait;

            /** @var \Psr\Http\Message\StreamInterface */
            private $stream;

            public function read($length): string
            {
                throw new \RuntimeException('disk gone');
            }
        };
        $response = new PsrResponse(200, ['Content-Type' => 'application/json'], $stream);

        $this->assertNull(BodyReader::read($response, 100));
        $this->assertSame(3, $inner->tell());
    }

    public function testRequestBodiesAreReadToo(): void
    {
        $request = new Request(new PsrRequest('POST', 'https://api.example/verify', ['Content-Type' => 'application/json'], '{"iban":"PK00"}'));

        $this->assertSame(['body' => '{"iban":"PK00"}', 'size' => 15, 'type' => 'application/json'], BodyReader::read($request, 100));
        $this->assertSame('{"iban":"PK00"}', $request->body());
    }
}
