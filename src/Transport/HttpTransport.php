<?php

namespace DevZone\LogMonitor\Transport;

use Illuminate\Support\Facades\Http;

/**
 * POSTs a batch of entries to the monitoring server.
 *
 * - https only; plain http endpoints are rejected before any request is made
 * - TLS verification is never disabled
 * - the API key travels in the Authorization header only
 * - 10 second timeout, no in-request retries
 * - never throws: failures are reported via error_log() and return false
 */
final class HttpTransport
{
    const DEFAULT_TIMEOUT = 10;
    const USER_AGENT = 'devzone-log-monitor/1.0';

    /** @var string */
    private $endpoint;

    /** @var string */
    private $apiKey;

    /** @var int */
    private $timeout;

    /** @var string|null */
    private $app;

    public function __construct(string $endpoint, string $apiKey, int $timeout = self::DEFAULT_TIMEOUT, ?string $app = null)
    {
        $this->endpoint = trim($endpoint);
        $this->apiKey = trim($apiKey);
        $this->timeout = max(1, $timeout);
        $this->app = $app;
    }

    /**
     * @param array<string, mixed> $config The full log-monitor config array.
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            (string) ($config['endpoint'] ?? ''),
            (string) ($config['api_key'] ?? ''),
            (int) ($config['timeout'] ?? self::DEFAULT_TIMEOUT),
            isset($config['app']) && is_string($config['app']) ? $config['app'] : null
        );
    }

    /**
     * Only absolute https URLs with a host and no embedded credentials pass.
     */
    public static function isSecureEndpoint(string $url): bool
    {
        $parts = @parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return $parts['host'] !== '';
    }

    public function isConfigured(): bool
    {
        return $this->endpoint !== '' && $this->apiKey !== '';
    }

    /**
     * @param array<int, array<string, mixed>> $entries Already-redacted entries.
     */
    public function send(array $entries): bool
    {
        if ($entries === []) {
            return true;
        }
        if (!$this->isConfigured()) {
            error_log('[log-monitor] transport not configured: endpoint or api key missing');

            return false;
        }
        if (!self::isSecureEndpoint($this->endpoint)) {
            error_log('[log-monitor] refusing to ship to a non-https endpoint');

            return false;
        }
        if (strpos($this->endpoint, $this->apiKey) !== false) {
            error_log('[log-monitor] refusing to ship: the API key must not appear in the endpoint URL');

            return false;
        }

        $payload = [
            'app' => $this->app,
            'sent_at' => (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:s.vP'),
            'count' => count($entries),
            'entries' => array_values($entries),
        ];

        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->withOptions(['verify' => true, 'allow_redirects' => false])
                ->timeout($this->timeout)
                ->acceptJson()
                ->post($this->endpoint, $payload);

            if ($response->successful()) {
                return true;
            }

            error_log(sprintf(
                '[log-monitor] monitoring server responded with HTTP %d for a batch of %d entries',
                $response->status(),
                count($entries)
            ));

            return false;
        } catch (\Throwable $e) {
            error_log('[log-monitor] transport error: ' . get_class($e) . ': ' . $this->sanitize($e->getMessage()));

            return false;
        }
    }

    /**
     * Remove the API key from any text that might be logged or displayed.
     */
    public function sanitize(string $text): string
    {
        if ($this->apiKey === '') {
            return $text;
        }

        return str_replace($this->apiKey, '[REDACTED]', $text);
    }

    /**
     * Keep the key out of var_dump() / dd() output.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo()
    {
        return [
            'endpoint' => $this->endpoint,
            'api_key' => $this->apiKey === '' ? '' : '***',
            'timeout' => $this->timeout,
            'app' => $this->app,
        ];
    }
}
