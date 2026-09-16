<?php

namespace DevZone\LogMonitor\Logging;

use Illuminate\Container\Container;

/**
 * Builds the "extra" fields injected into every log record.
 *
 * Shared by the Monolog 2 and Monolog 3 processors so the logic lives in one
 * place. Every lookup is wrapped defensively: a logging processor must never
 * throw, whatever state the container is in.
 */
final class AppContext
{
    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        return [
            'client' => self::config('log-monitor.client'),
            'app' => self::config('log-monitor.app'),
            'env' => self::environment(),
            'hostname' => self::hostname(),
            'url' => self::url(),
            'user_id' => self::userId(),
        ];
    }

    /**
     * @return Container|null
     */
    private static function container()
    {
        try {
            return Container::getInstance();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return string|int|float|bool|null
     */
    private static function config(string $key)
    {
        try {
            $app = self::container();
            if ($app === null || !$app->bound('config')) {
                return null;
            }
            $value = $app->make('config')->get($key);

            return is_scalar($value) ? $value : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function environment(): ?string
    {
        try {
            $app = self::container();
            if ($app === null || !method_exists($app, 'environment')) {
                return null;
            }
            $env = $app->environment();

            return is_string($env) ? $env : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function hostname(): ?string
    {
        try {
            $host = gethostname();

            return is_string($host) && $host !== '' ? $host : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Request URL without the query string (query strings routinely carry
     * tokens). Null when running in the console.
     */
    private static function url(): ?string
    {
        try {
            $app = self::container();
            if ($app === null || !method_exists($app, 'runningInConsole') || $app->runningInConsole()) {
                return null;
            }
            if (!$app->bound('request')) {
                return null;
            }
            $request = $app->make('request');
            if (!is_object($request) || !method_exists($request, 'url')) {
                return null;
            }
            $url = $request->url();

            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Only reports a user that has already been resolved for this request.
     * hasUser() never touches the session or the database.
     *
     * @return int|string|null
     */
    private static function userId()
    {
        try {
            $app = self::container();
            if ($app === null || !$app->bound('auth')) {
                return null;
            }
            $auth = $app->make('auth');
            if (!is_object($auth) || !method_exists($auth, 'hasUser') || !$auth->hasUser()) {
                return null;
            }
            $id = $auth->id();

            return is_int($id) || is_string($id) ? $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
