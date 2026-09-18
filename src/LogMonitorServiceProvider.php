<?php

namespace DevZone\LogMonitor;

use DevZone\LogMonitor\Capture\Location;
use DevZone\LogMonitor\Capture\Recorder;
use DevZone\LogMonitor\Console\InstallCommand;
use DevZone\LogMonitor\Console\OffCommand;
use DevZone\LogMonitor\Console\OnCommand;
use DevZone\LogMonitor\Console\ShipCommand;
use DevZone\LogMonitor\Console\StatusCommand;
use DevZone\LogMonitor\Hooks\EventHooks;
use DevZone\LogMonitor\Http\Middleware\CaptureRequests;
use DevZone\LogMonitor\Shipping\Shipper;
use DevZone\LogMonitor\Spool\SpoolWriter;
use DevZone\LogMonitor\Support\KillSwitch;
use DevZone\LogMonitor\Support\Redactor;
use DevZone\LogMonitor\Support\Report;
use DevZone\LogMonitor\Transport\HttpTransport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;

class LogMonitorServiceProvider extends ServiceProvider
{
    const VERSION = '2.0.0';
    const CONFIG_PATH = __DIR__ . '/../config/log-monitor.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'log-monitor');

        $this->app->singleton(Redactor::class, function ($app) {
            $redact = $app['config']->get('log-monitor.redact', []);

            return Redactor::fromConfig(is_array($redact) ? $redact : []);
        });

        $this->app->singleton(SpoolWriter::class, function ($app) {
            $spool = $app['config']->get('log-monitor.spool', []);

            return SpoolWriter::fromConfig(is_array($spool) ? $spool : []);
        });

        $this->app->singleton(Recorder::class, function ($app) {
            return new Recorder(
                (array) $app['config']->get('log-monitor', []),
                $app->make(SpoolWriter::class),
                $app->make(Redactor::class),
                new Location($app->basePath())
            );
        });

        $this->app->singleton(CaptureRequests::class);

        $this->app->bind(HttpTransport::class, function ($app) {
            return HttpTransport::fromConfig((array) $app['config']->get('log-monitor', []), (string) $app->environment());
        });

        $this->app->bind(Shipper::class, function ($app) {
            $config = (array) $app['config']->get('log-monitor', []);

            return new Shipper(
                $app->make(SpoolWriter::class)->directory(),
                $app->make(HttpTransport::class),
                $config,
                [
                    'app' => $config['app'] ?? null,
                    'env' => (string) $app->environment(),
                    'host' => (string) gethostname(),
                    'client' => $config['client'] ?? null,
                    'package' => self::VERSION,
                ]
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::CONFIG_PATH => $this->app->configPath('log-monitor.php'),
            ], 'log-monitor-config');

            $this->commands([
                ShipCommand::class,
                StatusCommand::class,
                OffCommand::class,
                OnCommand::class,
                InstallCommand::class,
            ]);
        }

        if (!self::active($this->app['config']->get('log-monitor', []))) {
            return;
        }

        try {
            $this->registerCapture();
            $this->registerSchedule();
        } catch (\Throwable $e) {
            Report::error('could not start log-monitor', $e);
        }
    }

    /**
     * Enabled in config and not switched off with log-monitor:off.
     *
     * @param mixed $config
     */
    public static function active($config): bool
    {
        if (!is_array($config) || empty($config['enabled'])) {
            return false;
        }

        return !KillSwitch::isOn((string) ($config['kill_switch_path'] ?? ''));
    }

    private function registerCapture(): void
    {
        $hooks = new EventHooks($this->app->make(Recorder::class), $this->queueTables());
        $hooks->register($this->app['events']);

        $prepend = function ($kernel) {
            if (is_object($kernel) && method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(CaptureRequests::class);
            }
        };
        // The HTTP kernel is normally resolved (public/index.php) before the
        // providers boot, and its middleware pipeline is built afterwards.
        if ($this->app->resolved(HttpKernel::class)) {
            $prepend($this->app->make(HttpKernel::class));
        } else {
            $this->app->afterResolving(HttpKernel::class, $prepend);
        }
    }

    /**
     * Tables used by database queue connections and the failed-jobs store.
     *
     * @return array<int, string>
     */
    private function queueTables(): array
    {
        $config = $this->app['config'];
        $tables = [];
        foreach ((array) $config->get('queue.connections', []) as $connection) {
            if (is_array($connection) && ($connection['driver'] ?? null) === 'database') {
                $tables[] = (string) ($connection['table'] ?? 'jobs');
            }
        }
        $failed = $config->get('queue.failed.table');
        if (is_string($failed) && $failed !== '') {
            $tables[] = $failed;
        }
        $batching = $config->get('queue.batching.table');
        if (is_string($batching) && $batching !== '') {
            $tables[] = $batching;
        }

        return $tables;
    }

    private function registerSchedule(): void
    {
        if (!$this->app['config']->get('log-monitor.shipping.schedule', true)) {
            return;
        }

        $register = function (Schedule $schedule) {
            $schedule->command('log-monitor:ship')
                ->everyMinute()
                ->withoutOverlapping(10);
        };

        $this->app->afterResolving(Schedule::class, $register);
        if ($this->app->resolved(Schedule::class)) {
            $register($this->app->make(Schedule::class));
        }
    }
}
