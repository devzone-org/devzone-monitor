<?php

namespace DevZone\LogMonitor\Transport;

interface Transport
{
    /**
     * Send one JSON body. Never throws.
     *
     * @return array{ok: bool, status: int|null, retryable: bool, error: string|null}
     */
    public function send(string $json): array;
}
