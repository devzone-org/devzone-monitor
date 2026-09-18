<?php

namespace DevZone\LogMonitor\Http\Middleware;

use Closure;
use DevZone\LogMonitor\Capture\Execution;
use DevZone\LogMonitor\Capture\Recorder;
use DevZone\LogMonitor\Support\Redactor;
use DevZone\LogMonitor\Support\Report;
use DevZone\LogMonitor\Support\Text;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Global middleware (prepended, so it wraps everything else).
 *
 * handle():    starts the in-memory execution, nothing else.
 * terminate(): runs after the response has been sent to the user
 *              (fastcgi_finish_request), builds the records and writes
 *              them to the spool in one append.
 */
class CaptureRequests
{
    /** @var Recorder */
    private $recorder;

    /** @var Redactor */
    private $redactor;

    public function __construct(Recorder $recorder, Redactor $redactor)
    {
        $this->recorder = $recorder;
        $this->redactor = $redactor;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        try {
            if (!$this->ignored($request)) {
                $this->recorder->startRequest();
            }
        } catch (\Throwable $e) {
            Report::error('request capture failed to start', $e);
        }

        return $next($request);
    }

    /**
     * @param Request $request
     * @param mixed   $response
     */
    public function terminate($request, $response): void
    {
        try {
            $execution = $this->recorder->currentRequest();
            if ($execution === null) {
                return;
            }
            $this->recorder->finishRequest($execution, $this->info($request, $response, $execution));
        } catch (\Throwable $e) {
            Report::error('request capture failed to finish', $e);
        }
    }

    /**
     * @param Request $request
     */
    private function ignored($request): bool
    {
        $patterns = $this->recorder->setting('requests.ignore_paths', []);

        return is_array($patterns) && $patterns !== [] && $request->is(...array_values($patterns));
    }

    /**
     * @param Request $request
     * @param mixed   $response
     * @return array<string, mixed>
     */
    private function info($request, $response, Execution $execution): array
    {
        $route = $request->route();
        $routeUri = null;
        $routeName = null;
        $action = null;
        if (is_object($route) && method_exists($route, 'uri')) {
            $routeUri = '/' . ltrim((string) $route->uri(), '/');
            $routeName = method_exists($route, 'getName') ? $route->getName() : null;
            $action = method_exists($route, 'getActionName') ? $route->getActionName() : null;
        }

        $status = $response instanceof Response ? $response->getStatusCode() : null;

        $info = [
            'method' => $request->getMethod(),
            'url' => $request->url(),
            'route' => $routeUri,
            'route_name' => $routeName,
            'action' => $action,
            'status' => $status,
            'ip' => $request->ip(),
            'user' => $this->userId(),
            'request_bytes' => $this->requestBytes($request),
            'response_bytes' => $this->responseBytes($response),
            'bootstrap_ms' => defined('LARAVEL_START') ? round(($execution->startedAt - (float) LARAVEL_START) * 1000, 2) : null,
        ];

        if ($this->shouldCaptureBodies($request, $status, $execution)) {
            $max = (int) $this->recorder->setting('requests.bodies.max_bytes', 8192);
            $info['request_headers'] = $this->headers($request);
            $info['request_body'] = $this->requestBody($request, $max);
            $body = $this->responseBody($response, $max);
            if ($body !== null) {
                $info['response_body'] = $body;
            }
        }

        return $info;
    }

    /**
     * @param Request $request
     */
    private function shouldCaptureBodies($request, ?int $status, Execution $execution): bool
    {
        $onStatus = $this->recorder->setting('requests.bodies.on_status', 500);
        if ($onStatus !== null && $status !== null && $status >= (int) $onStatus) {
            return true;
        }
        $slow = $this->recorder->setting('requests.bodies.slow_ms');
        if ($slow !== null && $this->recorder->elapsedMsFor($execution) >= (float) $slow) {
            return true;
        }
        $routes = $this->recorder->setting('requests.bodies.routes', []);
        if (is_array($routes) && $routes !== []) {
            $routes = array_values($routes);
            if ($request->is(...$routes)) {
                return true;
            }
            $route = $request->route();
            if (is_object($route) && method_exists($route, 'named') && $route->named(...$routes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Request $request
     */
    private function requestBody($request, int $max): ?string
    {
        $files = $request->allFiles();
        $input = $files === [] ? $request->input() : $request->except(array_keys($files));
        if (is_array($input) && $input !== []) {
            $json = json_encode($this->redactor->redact($input), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            return is_string($json) ? Text::limitBytes($json, $max) : null;
        }
        $raw = (string) $request->getContent();

        return $raw === '' ? null : Text::limitBytes($this->redactor->redactBody(Text::limitBytes($raw, $max * 4)), $max);
    }

    /**
     * @param mixed $response
     */
    private function responseBody($response, int $max): ?string
    {
        if (!$response instanceof Response || $response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return null;
        }
        $type = (string) $response->headers->get('Content-Type', '');
        if ($type !== '' && !preg_match('#json|text|xml|html|javascript#i', $type)) {
            return null;
        }
        $content = $response->getContent();
        if (!is_string($content) || $content === '') {
            return null;
        }

        return Text::limitBytes($this->redactor->redactBody(Text::limitBytes($content, $max * 4)), $max);
    }

    /**
     * @param Request $request
     * @return array<string, string>
     */
    private function headers($request): array
    {
        $sensitive = array_map('strtolower', (array) $this->recorder->setting('redact.headers', []));
        $out = [];
        foreach ($request->headers->all() as $name => $values) {
            $name = strtolower((string) $name);
            $value = is_array($values) ? implode(', ', $values) : (string) $values;
            $out[$name] = in_array($name, $sensitive, true)
                ? (string) $this->recorder->setting('redact.replacement', '[REDACTED]')
                : Text::limit($this->redactor->redactString($value), 500);
        }

        return $out;
    }

    /**
     * @param Request $request
     */
    private function requestBytes($request): ?int
    {
        $length = $request->headers->get('Content-Length');

        return is_numeric($length) ? (int) $length : null;
    }

    /**
     * @param mixed $response
     */
    private function responseBytes($response): ?int
    {
        if (!$response instanceof Response) {
            return null;
        }
        $length = $response->headers->get('Content-Length');
        if (is_numeric($length)) {
            return (int) $length;
        }
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return null;
        }
        $content = $response->getContent();

        return is_string($content) ? strlen($content) : null;
    }

    /**
     * @return int|string|null
     */
    private function userId()
    {
        try {
            $auth = app('auth');
            if (!method_exists($auth, 'hasUser') || !$auth->hasUser()) {
                return null;
            }
            $id = $auth->id();

            return is_int($id) || is_string($id) ? $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
