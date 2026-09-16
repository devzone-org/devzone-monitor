<?php

namespace DevZone\LogMonitor\Tests;

use DevZone\LogMonitor\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

class HttpTransportTest extends TestCase
{
    public function testHttpsEndpointsPass(): void
    {
        $this->assertTrue(HttpTransport::isSecureEndpoint('https://monitor.example.com/api/ingest'));
        $this->assertTrue(HttpTransport::isSecureEndpoint('HTTPS://monitor.example.com/api/ingest', false));
    }

    public function testHttpIsRejectedByDefault(): void
    {
        $this->assertFalse(HttpTransport::isSecureEndpoint('http://monitor.test/api/ingest'));
        $this->assertFalse(HttpTransport::isSecureEndpoint('http://monitor.test/api/ingest', false));
    }

    public function testHttpIsAcceptedOnlyWhenExplicitlyAllowed(): void
    {
        $this->assertTrue(HttpTransport::isSecureEndpoint('http://monitor.test/api/ingest', true));
        $this->assertTrue(HttpTransport::isSecureEndpoint('http://localhost:8000/api/ingest', true));
    }

    public function testOtherSchemesAndCredentialsAreAlwaysRejected(): void
    {
        $this->assertFalse(HttpTransport::isSecureEndpoint('ftp://monitor.test/api/ingest', true));
        $this->assertFalse(HttpTransport::isSecureEndpoint('monitor.test/api/ingest', true));
        $this->assertFalse(HttpTransport::isSecureEndpoint('http://user:pass@monitor.test/api/ingest', true));
        $this->assertFalse(HttpTransport::isSecureEndpoint('https://user:pass@monitor.test/api/ingest'));
    }

    public function testAllowsHttpFollowsTheConfigFlagOutsideLaravel(): void
    {
        $this->assertFalse(HttpTransport::allowsHttp([]));
        $this->assertFalse(HttpTransport::allowsHttp(['allow_http' => false]));
        $this->assertTrue(HttpTransport::allowsHttp(['allow_http' => true]));
    }

    public function testFromConfigCarriesTheFlagIntoSend(): void
    {
        $transport = HttpTransport::fromConfig([
            'endpoint' => 'http://monitor.test/api/ingest',
            'api_key' => 'dzm_abc.def',
            'allow_http' => false,
        ]);

        // Refused before any request is attempted, so no HTTP client is needed.
        $this->assertFalse(@$transport->send([['message' => 'x']]));
    }
}
