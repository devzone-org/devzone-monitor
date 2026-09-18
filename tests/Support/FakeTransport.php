<?php

namespace DevZone\LogMonitor\Tests\Support;

use DevZone\LogMonitor\Transport\Transport;

final class FakeTransport implements Transport
{
    /** @var array<int, array{ok: bool, status: int|null, retryable: bool, error: string|null}> queued results; default ok */
    public $results = [];

    /** @var array<int, array<string, mixed>> decoded bodies received */
    public $sent = [];

    public function send(string $json): array
    {
        $this->sent[] = json_decode($json, true);

        return array_shift($this->results) ?: ['ok' => true, 'status' => 202, 'retryable' => false, 'error' => null];
    }

    public function fail(bool $retryable, ?int $status = 500): void
    {
        $this->results[] = ['ok' => false, 'status' => $status, 'retryable' => $retryable, 'error' => 'HTTP ' . $status];
    }
}
