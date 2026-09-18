<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\Logging\AddAppContext;
use DevZone\LogMonitor\Logging\AppContext;
use DevZone\LogMonitor\Logging\Monolog2Processor;
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

    public function testV1TapStillProducesJsonLines(): void
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

        $this->assertSame(400, $decoded['level']);
        $this->assertSame('ERROR', $decoded['level_name']);
    }
}
