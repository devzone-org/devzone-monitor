<?php

namespace DevZone\LogMonitor;

use DevZone\LogMonitor\Console\InstallCommand;
use DevZone\LogMonitor\Console\ShipLogsCommand;
use DevZone\LogMonitor\Support\EntryBuilder;
use DevZone\LogMonitor\Support\LogFileReader;
use DevZone\LogMonitor\Support\PathGuard;
use DevZone\LogMonitor\Support\Redactor;
use DevZone\LogMonitor\Support\StateStore;
use DevZone\LogMonitor\Transport\HttpTransport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class LogMonitorServiceProvider extends ServiceProvider
{
    const CONFIG_PATH = __DIR__ . '/../config/log-monitor.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'log-monitor');

        $this->app->singleton(Redactor::class, function ($app) {
            $config = $app['config']->get('log-monitor.redact', []);

            return Redactor::fromConfig(is_array($config) ? $config : []);
        });

        $this->app->singleton(EntryBuilder::class, function ($app) {
            return new EntryBuilder(
                $app->make(Redactor::class),
                (int) $app['config']->get('log-monitor.message_max_length', EntryBuilder::DEFAULT_MESSAGE_MAX_LENGTH)
            );
        });

        $this->app->singleton(LogFileReader::class, function ($app) {
            return new LogFileReader(
                (int) $app['config']->get('log-monitor.max_chunk_bytes', LogFileReader::DEFAULT_MAX_CHUNK_BYTES)
            );
        });

        $this->app->singleton(StateStore::class, function ($app) {
            $path = $app['config']->get('log-monitor.state_path');
            if (!is_string($path) || $path === '') {
                $path = $app->storagePath() . '/app/log-monitor/state.json';
            }

            return new StateStore(
                $path,
                (int) $app['config']->get('log-monitor.min_free_disk_bytes', StateStore::DEFAULT_MIN_FREE_BYTES)
            );
        });

        $this->app->singleton(PathGuard::class, function ($app) {
            return new PathGuard($app->storagePath() . '/logs');
        });

        $this->app->bind(HttpTransport::class, function ($app) {
            $config = $app['config']->get('log-monitor', []);

            return HttpTransport::fromConfig(is_array($config) ? $config : []);
        });
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            self::CONFIG_PATH => $this->app->configPath('log-monitor.php'),
        ], 'log-monitor-config');

        $this->commands([
            ShipLogsCommand::class,
            InstallCommand::class,
        ]);

        $this->registerSchedule();
    }

    private function registerSchedule(): void
    {
        $config = $this->app['config'];
        if (!$config->get('log-monitor.enabled', true) || !$config->get('log-monitor.schedule', true)) {
            return;
        }

        $this->app->booted(function () {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('log-monitor:ship')
                ->everyMinute()
                ->withoutOverlapping(10);
        });
    }
}
