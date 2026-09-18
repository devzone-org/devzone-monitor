<?php

namespace DevZone\LogMonitor\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

/**
 * POSTs one batch to the monitoring server.
 *
 * - https only; plain http only with allow_http AND a development APP_ENV
 * - TLS verification is never disabled, redirects are never followed
 * - the API key travels in the Authorization header only
 * - never throws; the result says whether to retry
 *
 * Uses Guzzle directly rather than Laravel's Http:: client so the package's
 * own uploads are never captured as outgoing calls of the host app.
 */
class HttpTransport implements Transport
{
    const DEFAULT_TIMEOUT = 10;
    const USER_AGENT = 'devzone-log-monitor/2';
    const HTTP_ALLOWED_ENVIRONMENTS = ['local', 'development', 'dev', 'testing'];

    /** @var string */
    private $endpoint;

    /** @var string */
    private $apiKey;

    /** @var int */
    private $timeout;

    /** @var bool */
    private $allowHttp;

    /** @var bool */
    private $gzip;

    /** @var HandlerStack|callable|null */
    private $handler;

    /**
     * @param HandlerStack|callable|null $handler Guzzle handler (tests)
     */
    public function __construct(string $endpoint, string $apiKey, int $timeout = self::DEFAULT_TIMEOUT, bool $allowHttp = false, bool $gzip = true, $handler = null)
    {
        $this->endpoint = trim($endpoint);
        $this->apiKey = trim($apiKey);
        $this->timeout = max(1, $timeout);
        $this->allowHttp = $allowHttp;
        $this->gzip = $gzip && function_exists('gzencode');
        $this->handler = $handler;
    }

    /**
     * @param array<string, mixed> $config Full log-monitor config.
     * @param HandlerStack|callable|null $handler
     */
    public static function fromConfig(array $config, ?string $environment = null, $handler = null): self
    {
        return new self(
            (string) ($config['endpoint'] ?? ''),
            (string) ($config['api_key'] ?? ''),
            (int) ($config['timeout'] ?? self::DEFAULT_TIMEOUT),
            self::httpAllowedFor(!empty($config['allow_http']), $environment),
            (bool) ($config['shipping']['gzip'] ?? true),
            $handler
        );
    }

    public static function httpAllowedFor(bool $flag, ?string $environment): bool
    {
        return $flag
            && is_string($environment)
            && in_array(strtolower($environment), self::HTTP_ALLOWED_ENVIRONMENTS, true);
    }

    /**
     * Absolute https URL with a host and no embedded credentials; plain http
     * too when $allowHttp.
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

    public function endpointAllowed(): bool
    {
        return self::isSecureEndpoint($this->endpoint, $this->allowHttp);
    }

    public function host(): ?string
    {
        $host = parse_url($this->endpoint, PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }

    public function send(string $json): array
    {
        if (!$this->isConfigured()) {
            return self::result(false, null, false, 'endpoint or api key not configured');
        }
        if (!$this->endpointAllowed()) {
            return self::result(false, null, false, 'endpoint rejected: must be an absolute https URL');
        }
        if (strpos($this->endpoint, $this->apiKey) !== false) {
            return self::result(false, null, false, 'the API key must not appear in the endpoint URL');
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => self::USER_AGENT,
        ];
        $body = $json;
        if ($this->gzip) {
            $compressed = gzencode($json, 6);
            if (is_string($compressed)) {
                $body = $compressed;
                $headers['Content-Encoding'] = 'gzip';
            }
        }

        try {
            $options = [];
            if ($this->handler !== null) {
                $options['handler'] = $this->handler;
            }
            $client = new Client($options);
            $response = $client->request('POST', $this->endpoint, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => $this->timeout,
                'connect_timeout' => min(5, $this->timeout),
                'verify' => true,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return self::result(true, $status, false, null);
            }

            // 408 timeout, 425/429 throttling, 5xx: the server may recover.
            // Other 4xx mean this batch or this configuration is rejected.
            $retryable = $status === 408 || $status === 425 || $status === 429 || $status >= 500;

            return self::result(false, $status, $retryable, 'HTTP ' . $status);
        } catch (\Throwable $e) {
            return self::result(false, null, true, $this->sanitize(get_class($e) . ': ' . $e->getMessage()));
        }
    }

    public function sanitize(string $text): string
    {
        return $this->apiKey === '' ? $text : str_replace($this->apiKey, '[REDACTED]', $text);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo()
    {
        return [
            'endpoint' => $this->endpoint,
            'api_key' => $this->apiKey === '' ? '' : '***',
            'timeout' => $this->timeout,
            'allow_http' => $this->allowHttp,
            'gzip' => $this->gzip,
        ];
    }

    /**
     * @return array{ok: bool, status: int|null, retryable: bool, error: string|null}
     */
    private static function result(bool $ok, ?int $status, bool $retryable, ?string $error): array
    {
        return ['ok' => $ok, 'status' => $status, 'retryable' => $retryable, 'error' => $error];
    }
}
