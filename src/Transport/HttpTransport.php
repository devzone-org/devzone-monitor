<?php

namespace DevZone\LogMonitor\Transport;

use Illuminate\Support\Facades\Http;

/**
 * POSTs a batch of entries to the monitoring server.
 *
 * - https only; plain http endpoints are rejected before any request is made,
 *   unless allow_http is set and the application is not in production
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

    /** @var bool Plain http accepted (local development only, decided by the caller). */
    private $allowHttp;

    public function __construct(
        string $endpoint,
        string $apiKey,
        int $timeout = self::DEFAULT_TIMEOUT,
        ?string $app = null,
        bool $allowHttp = false
    ) {
        $this->endpoint = trim($endpoint);
        $this->apiKey = trim($apiKey);
        $this->timeout = max(1, $timeout);
        $this->app = $app;
        $this->allowHttp = $allowHttp;
    }

    /**
     * Environments in which the allow_http flag is honoured at all.
     */
    const HTTP_ALLOWED_ENVIRONMENTS = ['local', 'development', 'dev', 'testing'];

    /**
     * @param array<string, mixed> $config The full log-monitor config array.
     * @param string|null $environment The application environment (APP_ENV).
     */
    public static function fromConfig(array $config, ?string $environment = null): self
    {
        return new self(
            (string) ($config['endpoint'] ?? ''),
            (string) ($config['api_key'] ?? ''),
            (int) ($config['timeout'] ?? self::DEFAULT_TIMEOUT),
            isset($config['app']) && is_string($config['app']) ? $config['app'] : null,
            self::httpAllowedFor(!empty($config['allow_http']), $environment)
        );
    }

    /**
     * http is only ever allowed when explicitly requested AND the environment
     * is a development one. Production can never be downgraded by the flag.
     */
    public static function httpAllowedFor(bool $flag, ?string $environment): bool
    {
        return $flag
            && is_string($environment)
            && in_array(strtolower($environment), self::HTTP_ALLOWED_ENVIRONMENTS, true);
    }

    /**
     * Whether this transport will send to its configured endpoint.
     */
    public function endpointAllowed(): bool
    {
        return self::isSecureEndpoint($this->endpoint, $this->allowHttp);
    }

    /**
     * Only absolute https URLs with a host and no embedded credentials pass.
     * With $allowHttp, plain http is accepted as well.
     */
    public static function isSecureEndpoint(string $url, bool $allowHttp = false): bool
    {
        $parts = @parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && !($allowHttp && $scheme === 'http')) {
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
        if (!$this->endpointAllowed()) {
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
            'allow_http' => $this->allowHttp,
        ];
    }
}
