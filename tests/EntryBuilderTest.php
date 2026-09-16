<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\Support\EntryBuilder;
use DevZone\LogMonitor\Support\Redactor;
use PHPUnit\Framework\TestCase;

final class EntryBuilderTest extends TestCase
{
    /** @var EntryBuilder */
    private $builder;

    protected function setUp(): void
    {
        $this->builder = new EntryBuilder(new Redactor());
    }

    public function testParsesMonolog3Line(): void
    {
        $line = json_encode([
            'message' => 'Payment failed for order 8812',
            'context' => ['order_id' => 8812, 'password' => 'x'],
            'level' => 400,
            'level_name' => 'ERROR',
            'channel' => 'production',
            'datetime' => '2026-09-16T10:15:30.123456+05:00',
            'extra' => ['client' => 'acme', 'user_id' => 7],
        ]);

        $entry = $this->builder->fromLine($line . "\n");

        $this->assertNotNull($entry);
        $this->assertSame('2026-09-16T10:15:30.123+05:00', $entry['logged_at']);
        $this->assertSame('error', $entry['level']);
        $this->assertSame(400, $entry['severity']);
        $this->assertSame('Payment failed for order 8812', $entry['message']);
        $this->assertSame(['order_id' => 8812, 'password' => '[REDACTED]'], $entry['context']);
        $this->assertSame(['client' => 'acme', 'user_id' => 7], $entry['extra']);
        $this->assertSame(32, strlen($entry['fingerprint']));
    }

    public function testParsesMonolog2LineWithObjectDatetime(): void
    {
        $line = json_encode([
            'message' => 'Disk almost full',
            'context' => [],
            'level' => 300,
            'level_name' => 'WARNING',
            'channel' => 'local',
            'datetime' => [
                'date' => '2026-09-16 10:15:30.500000',
                'timezone_type' => 3,
                'timezone' => 'Asia/Karachi',
            ],
            'extra' => [],
        ]);

        $entry = $this->builder->fromLine($line);

        $this->assertNotNull($entry);
        $this->assertSame('2026-09-16T10:15:30.500+05:00', $entry['logged_at']);
        $this->assertSame('warning', $entry['level']);
        $this->assertSame(300, $entry['severity']);
    }

    public function testMissingSeverityIsDerivedFromLevelName(): void
    {
        $entry = $this->builder->fromLine('{"message":"x","level_name":"critical","datetime":"2026-01-01T00:00:00+00:00"}');

        $this->assertSame(500, $entry['severity']);
        $this->assertSame('critical', $entry['level']);
    }

    public function testMalformedLinesReturnNull(): void
    {
        $this->assertNull($this->builder->fromLine(''));
        $this->assertNull($this->builder->fromLine('[2026-09-16 10:00:00] local.ERROR: plain text line'));
        $this->assertNull($this->builder->fromLine('{"message":"truncated","level":4'));
        $this->assertNull($this->builder->fromLine('{"no_message":true,"level":400}'));
        $this->assertNull($this->builder->fromLine('{"message":"no level at all"}'));
    }

    public function testMessageIsRedactedThenCapped(): void
    {
        $builder = new EntryBuilder(new Redactor(), 60);
        $secret = str_repeat('a', 40) . ' john@example.com ' . str_repeat('b', 40);
        $entry = $builder->fromLine(json_encode(['message' => $secret, 'level' => 400, 'level_name' => 'ERROR']));

        $this->assertSame(60, strlen($entry['message']));
        $this->assertStringNotContainsString('example.com', $entry['message']);
        $this->assertStringContainsString('[REDACTED]', $entry['message']);
    }

    public function testFingerprintGroupsRepeatOccurrences(): void
    {
        $a = EntryBuilder::fingerprint('error', 'Order 1234 not found for user 9 at 0x7f3a1c');
        $b = EntryBuilder::fingerprint('error', 'Order 99 not found for user 12345 at 0xdeadbeef');
        $c = EntryBuilder::fingerprint('warning', 'Order 99 not found for user 12345 at 0xdeadbeef');

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertSame('Order N not found for user N at HEX', EntryBuilder::normalise('Order 1234 not found for user 9 at 0x7f3a1c'));
        $this->assertSame('hash HEX', EntryBuilder::normalise('hash 3f2a9c1e8b'));
        $this->assertSame(200, strlen(EntryBuilder::normalise(str_repeat('x', 500))));
    }

    public function testSeverityLookup(): void
    {
        $this->assertSame(300, EntryBuilder::severity('warning'));
        $this->assertSame(400, EntryBuilder::severity('ERROR'));
        $this->assertNull(EntryBuilder::severity('verbose'));
        $this->assertSame('error', EntryBuilder::levelName(450));
    }
}
