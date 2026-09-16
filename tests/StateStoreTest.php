<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\Support\StateStore;
use PHPUnit\Framework\TestCase;

final class StateStoreTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lm-state-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dir);
        }
    }

    public function testRoundTripKeyedByFilename(): void
    {
        $store = new StateStore($this->dir . '/state.json', 0);
        $store->set('laravel-2026-09-16.log', 48213, 48213);

        $this->assertTrue($store->save());
        $this->assertFileExists($this->dir . '/state.json');
        $this->assertSame([], glob($this->dir . '/state.json.*') ?: [], 'temp file must not linger');

        $decoded = json_decode(file_get_contents($this->dir . '/state.json'), true);
        $this->assertSame(48213, $decoded['laravel-2026-09-16.log']['offset']);
        $this->assertSame(48213, $decoded['laravel-2026-09-16.log']['size']);
        $this->assertNotEmpty($decoded['laravel-2026-09-16.log']['updated_at']);

        $reloaded = new StateStore($this->dir . '/state.json', 0);
        $this->assertSame(48213, $reloaded->offset('laravel-2026-09-16.log'));
        $this->assertSame(0, $reloaded->offset('laravel-2026-09-17.log'));
    }

    public function testPruneDropsFilesThatNoLongerExist(): void
    {
        $store = new StateStore($this->dir . '/state.json', 0);
        $store->set('laravel-2026-09-01.log', 10, 10);
        $store->set('laravel-2026-09-16.log', 20, 20);
        $store->prune(['laravel-2026-09-16.log']);

        $this->assertFalse($store->has('laravel-2026-09-01.log'));
        $this->assertTrue($store->has('laravel-2026-09-16.log'));
    }

    public function testCorruptStateStartsFresh(): void
    {
        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir . '/state.json', '{not json');

        $store = new StateStore($this->dir . '/state.json', 0);
        $this->assertSame([], @$store->load());
        $this->assertSame(0, $store->offset('anything.log'));
    }

    public function testRefusesToWriteWhenDiskSpaceIsBelowFloor(): void
    {
        $store = new StateStore($this->dir . '/state.json', PHP_INT_MAX);
        $store->set('laravel-2026-09-16.log', 1, 1);

        $this->assertFalse(@$store->save());
        $this->assertFileDoesNotExist($this->dir . '/state.json');
        $this->assertFalse($store->canWrite());
    }
}
