<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\Support\BodySanitizer;
use DevZone\LogMonitor\Support\Redactor;
use PHPUnit\Framework\TestCase;

final class BodySanitizerTest extends TestCase
{
    private function sanitizer(array $formats = BodySanitizer::DEFAULT_FORMATS, int $maxValues = 5000): BodySanitizer
    {
        return new BodySanitizer(Redactor::fromConfig([]), 65536, $maxValues, $formats);
    }

    public function testFormatsAreDetected(): void
    {
        $this->assertSame('json', BodySanitizer::format('application/vnd.api+json', ''));
        $this->assertSame('form', BodySanitizer::format('application/x-www-form-urlencoded; charset=utf-8', ''));
        $this->assertSame('xml', BodySanitizer::format('application/soap+xml', ''));
        $this->assertSame('html', BodySanitizer::format('text/html', ''));
        $this->assertSame('binary', BodySanitizer::format('application/pdf', ''));
        $this->assertSame('json', BodySanitizer::format('text/plain', ' {"a":1}'));
        $this->assertSame('form', BodySanitizer::format('', 'a=1&b=two'));
        $this->assertSame('xml', BodySanitizer::format('', '<a>1</a>'));
        $this->assertSame('html', BodySanitizer::format('', '<!DOCTYPE html><html></html>'));
        $this->assertSame('text', BodySanitizer::format('', 'hello there'));
    }

    public function testJsonAndFormAreMaskedByKey(): void
    {
        $this->assertSame(
            ['text' => '{"cnic":"[REDACTED]","amount":100}', 'redacted' => true, 'cut' => null],
            $this->sanitizer()->sanitize('{"cnic":"3520212345671","amount":100}', 'application/json', 8192)
        );
        $this->assertSame(
            ['text' => '{"pin":"[REDACTED]","amount":"100"}', 'redacted' => true, 'cut' => null],
            $this->sanitizer()->sanitize('pin=4321&amount=100', 'application/x-www-form-urlencoded', 8192)
        );
    }

    public function testUnparseableAndUnsupportedBodiesAreNotKept(): void
    {
        $sanitizer = $this->sanitizer();
        $this->assertSame('[json body not kept, 12 B: could not be parsed]', $sanitizer->sanitize('{"a":"secret', 'application/json', 8192)['text']);
        $this->assertSame('[text body not kept, 11 B]', $sanitizer->sanitize('hello token', 'text/plain', 8192)['text']);
        $this->assertSame('[html body not kept, 13 B]', $sanitizer->sanitize('<html></html>', 'text/html', 8192)['text']);
        $this->assertSame('[xml body not kept, 49 B: DOCTYPE not accepted]', $sanitizer->sanitize('<!DOCTYPE a [<!ENTITY e SYSTEM "file:///x">]><a/>', 'text/xml', 8192)['text']);
        $this->assertSame('[xml body not kept, 9 B: could not be parsed]', $sanitizer->sanitize('<a><b></a', 'text/xml', 8192)['text']);
    }

    public function testPlainTextIsKeptWhenEnabled(): void
    {
        $out = $this->sanitizer(['json', 'text'])->sanitize('failed for token=abc123 retry', 'text/plain', 8192);
        $this->assertSame('failed for token=[REDACTED] retry', $out['text']);
    }

    public function testBodiesOverTheParseLimitOrOnlyPartlyReadAreNotKept(): void
    {
        $sanitizer = $this->sanitizer();
        $this->assertSame('[json body not kept, 64 KB: over the 64 KB parse limit]', $sanitizer->sanitize(str_repeat('a', 65537), 'application/json', 8192)['text']);
        $this->assertSame('[json body not kept, 2 MB: over the 64 KB parse limit]', $sanitizer->sanitize('{"a":1}', 'application/json', 8192, 2097152)['text']);
    }

    public function testTooManyValuesAreNotWalked(): void
    {
        $body = json_encode(array_fill(0, 200, ['a' => 1]));
        $this->assertSame('[json body not kept, 1.6 KB: more than 50 values]', $this->sanitizer(BodySanitizer::DEFAULT_FORMATS, 50)->sanitize($body, 'application/json', 8192)['text']);
    }

    public function testOnlyFieldsKeepsJustTheAllowedValues(): void
    {
        $out = $this->sanitizer()->sanitize('{"status":"failed","code":"E12","customer":{"name":"Bob","phone":"0300"},"items":[{"sku":"A1"}]}', 'application/json', 8192, null, ['status', 'code', 'items']);
        $this->assertSame('{"status":"failed","code":"E12","customer":{"name":"[not kept]","phone":"[not kept]"},"items":[{"sku":"A1"}]}', $out['text']);

        $xml = $this->sanitizer()->sanitize('<r><Status>failed</Status><Name>Bob</Name></r>', 'text/xml', 8192, null, ['status']);
        $this->assertSame('<r><Status>failed</Status><Name>[not kept]</Name></r>', $xml['text']);
    }

    public function testXmlKeepsStructureAndMasksSubtrees(): void
    {
        $out = $this->sanitizer()->sanitize(
            '<?xml version="1.0"?><a:Env xmlns:a="urn:x"><a:Auth token="t1"><a:User>bob</a:User></a:Auth><a:Note>mail bob@example.com<!-- secret --></a:Note></a:Env>',
            'text/xml',
            8192
        );
        $this->assertSame('<a:Env xmlns:a="urn:x"><a:Auth token="[REDACTED]">[REDACTED]</a:Auth><a:Note>mail [REDACTED]</a:Note></a:Env>', $out['text']);
        $this->assertTrue($out['redacted']);
    }
}
