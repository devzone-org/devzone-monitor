<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\LogMonitorServiceProvider;
use PHPUnit\Framework\TestCase;

final class ConfigMergeTest extends TestCase
{
    public function testAnOlderPublishedConfigStillGetsNewSettings(): void
    {
        $defaults = [
            'outgoing' => ['enabled' => true, 'bodies' => 'always', 'headers' => true, 'max_bytes' => 8192],
            'redact' => ['keys' => ['password', 'token', 'pin'], 'query_keys' => ['key'], 'replacement' => '[REDACTED]'],
            'requests' => ['bodies' => ['on_status' => 500, 'routes' => ['integrations/*']]],
        ];
        // What log-monitor 2.0.1 published.
        $published = [
            'outgoing' => ['enabled' => true, 'bodies_on_error' => true, 'max_bytes' => 4096],
            'redact' => ['keys' => ['password', 'cnic'], 'replacement' => '***'],
            'requests' => ['bodies' => ['on_status' => 500, 'routes' => []]],
            'custom' => 'kept',
        ];

        $merged = LogMonitorServiceProvider::mergeDefaults($defaults, $published);

        $this->assertSame('always', $merged['outgoing']['bodies'], 'new setting arrives');
        $this->assertTrue($merged['outgoing']['headers']);
        $this->assertSame(4096, $merged['outgoing']['max_bytes'], 'the app still wins');
        $this->assertTrue($merged['outgoing']['bodies_on_error']);
        $this->assertSame(['password', 'cnic'], $merged['redact']['keys'], 'lists are replaced, not mixed');
        $this->assertSame(['key'], $merged['redact']['query_keys']);
        $this->assertSame('***', $merged['redact']['replacement']);
        $this->assertSame([], $merged['requests']['bodies']['routes'], 'an explicit empty list stays empty');
        $this->assertSame('kept', $merged['custom']);
    }
}
