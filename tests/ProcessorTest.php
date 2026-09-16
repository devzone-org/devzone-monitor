<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\Logging\AddAppContext;
use DevZone\LogMonitor\Logging\AppContext;
use DevZone\LogMonitor\Logging\Monolog2Processor;
use DevZone\LogMonitor\Support\EntryBuilder;
use DevZone\LogMonitor\Support\Redactor;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class ProcessorTest extends TestCase
{
    const EXPECTED_KEYS = ['client', 'app', 'env', 'hostname', 'url', 'user_id'];

    public function testAppContextExposesAllFieldsWithoutAContainer(): void
    {
        $context = AppContext::get();

        $this->assertSame(self::EXPECTED_KEYS, array_keys($context));
        $this->assertNull($context['url']);
        $this->assertNull($context['user_id']);
    }

    public function testMonolog2ProcessorMergesExtraOnArrayRecords(): void
    {
        $record = ['message' => 'hi', 'context' => [], 'level' => 400, 'extra' => ['existing' => 1]];

        $out = (new Monolog2Processor())($record);

        $this->assertSame(1, $out['extra']['existing']);
        foreach (self::EXPECTED_KEYS as $key) {
            $this->assertArrayHasKey($key, $out['extra']);
        }
    }

    public function testProcessorIsChosenForInstalledMonolog(): void
    {
        $expected = class_exists(\Monolog\LogRecord::class)
            ? \DevZone\LogMonitor\Logging\Monolog3Processor::class
            : Monolog2Processor::class;

        $this->assertSame($expected, AddAppContext::processorClass());
        $this->assertInstanceOf($expected, AddAppContext::makeProcessor());
    }

    public function testTapProducesJsonLinesTheBuilderCanParse(): void
    {
        $stream = fopen('php://memory', 'w+');
        $logger = new Logger('test');
        $logger->pushHandler(new StreamHandler($stream, Logger::DEBUG));

        (new AddAppContext())($logger);

        try {
            throw new \RuntimeException('boom for john@example.com');
        } catch (\RuntimeException $e) {
            $logger->error('Something broke', ['exception' => $e, 'password' => 'hunter2']);
        }

        rewind($stream);
        $line = trim((string) stream_get_contents($stream));
        $decoded = json_decode($line, true);

        $this->assertIsArray($decoded, 'tap must switch the handler to JSON');
        foreach (self::EXPECTED_KEYS as $key) {
            $this->assertArrayHasKey($key, $decoded['extra']);
        }
        $this->assertArrayHasKey('trace', $decoded['context']['exception'], 'stack traces must be included');

        $entry = (new EntryBuilder(new Redactor()))->fromLine($line);

        $this->assertSame('error', $entry['level']);
        $this->assertSame(400, $entry['severity']);
        $this->assertSame('[REDACTED]', $entry['context']['password']);
        $this->assertStringNotContainsString('example.com', $entry['context']['exception']['message']);
        $this->assertStringNotContainsString('example.com', json_encode($entry));
    }
}
