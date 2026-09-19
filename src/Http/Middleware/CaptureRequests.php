<?php

namespace DevZone\LogMonitor\Http\Middleware;

use Closure;
use DevZone\LogMonitor\Capture\BodyReader;
use DevZone\LogMonitor\Capture\Execution;
use DevZone\LogMonitor\Capture\Recorder;
use DevZone\LogMonitor\Support\CurrentUser;
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

        $params = $request->query->all();
        if ($params !== []) {
            $info['query'] = $this->recorder->queryParams($params, $meta);
            Recorder::mark($info, 'query', $meta['redacted'], $meta['cut']);
        }

        if ($this->shouldCaptureBodies($request, $status, $execution)) {
            $max = (int) $this->recorder->setting('requests.bodies.max_bytes', 8192);
            $info['request_headers'] = $this->recorder->headers($request->headers->all(), $meta);
            Recorder::mark($info, 'request_headers', $meta['redacted'], $meta['cut']);
            foreach (['request_body' => $this->requestBody($request, $max), 'response_body' => $this->responseBody($response, $max)] as $key => $body) {
                if ($body !== null) {
                    $info[$key] = $body['text'];
                    Recorder::mark($info, $key, $body['redacted'], $body['cut']);
                }
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
    /**
     * @return array{text: string, redacted: bool, cut: ?string}|null
     */
    private function requestBody($request, int $max): ?array
    {
        $files = $request->allFiles();
        $input = $files === [] ? $request->input() : $request->except(array_keys($files));
        if (is_array($input) && $input !== []) {
            $clean = $this->redactor->redact($input);
            $json = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (!is_string($json)) {
                return null;
            }
            $note = $files !== [] ? count($files) . ' uploaded file(s) not kept' : null;

            return $this->bounded($json, $max, $clean != $input, $note);
        }
        $raw = (string) $request->getContent();
        if ($raw === '') {
            return null;
        }
        $read = Text::limitBytes($raw, $max * 4);
        $clean = $this->redactor->redactBody($read);

        return $this->bounded($clean, $max, Recorder::masked($read, $clean), null, strlen($raw));
    }

    /**
     * @return array{text: string, redacted: bool, cut: ?string}
     */
    private function bounded(string $text, int $max, bool $redacted, ?string $note = null, ?int $fullSize = null): array
    {
        $kept = Text::limitBytes($text, $max);
        $full = max($fullSize ?? 0, strlen($text));
        $cut = strlen($kept) < $full ? 'kept ' . BodyReader::formatBytes(strlen($kept)) . ' of ' . BodyReader::formatBytes($full) : null;
        if ($cut !== null) {
            $kept .= "\n…[cut: {$cut}]";
        }
        if ($note !== null) {
            $cut = $cut !== null ? "{$cut}; {$note}" : $note;
        }

        return ['text' => $kept, 'redacted' => $redacted, 'cut' => $cut];
    }

    /**
     * @param mixed $response
     * @return array{text: string, redacted: bool, cut: ?string}|null
     */
    private function responseBody($response, int $max): ?array
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
        $read = Text::limitBytes($content, $max * 4);
        $clean = $this->redactor->redactBody($read);

        return $this->bounded($clean, $max, Recorder::masked($read, $clean), null, strlen($content));
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
            return CurrentUser::id(app('auth'));
        } catch (\Throwable $e) {
            return null;
        }
    }
}
