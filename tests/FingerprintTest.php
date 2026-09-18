<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\Support\Fingerprint;
use DevZone\LogMonitor\Support\Levels;
use PHPUnit\Framework\TestCase;

final class FingerprintTest extends TestCase
{
    public function testRepeatOccurrencesGroupTogether(): void
    {
        $this->assertSame(
            Fingerprint::log('error', 'Order 1234 not found for user 9 at 0x7f3a1c'),
            Fingerprint::log('error', 'Order 99 not found for user 12345 at 0xdeadbeef')
        );
        $this->assertNotSame(
            Fingerprint::log('error', 'Order 1 not found'),
            Fingerprint::log('warning', 'Order 1 not found')
        );
        $this->assertSame('Order N not found for user N at HEX', Fingerprint::normalise('Order 1234 not found for user 9 at 0x7f3a1c'));
        $this->assertSame(200, strlen(Fingerprint::normalise(str_repeat('x', 500))));
    }

    public function testLevels(): void
    {
        $this->assertSame(400, Levels::severity('ERROR'));
        $this->assertTrue(Levels::passes('error', 'warning'));
        $this->assertFalse(Levels::passes('info', 'warning'));
        $this->assertTrue(Levels::passes('custom', 'warning'), 'unknown levels are never dropped');
    }
}
