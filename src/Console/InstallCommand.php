<?php

namespace DevZone\LogMonitor\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'log-monitor:install {--force : Overwrite an already published config file}';

    /** @var string */
    protected $description = 'Publish the log-monitor config and print the logging.php and .env snippets';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'log-monitor-config',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->line('');
        $this->info('1. Point your log channel at the JSON tap in config/logging.php:');
        $this->line(self::loggingSnippet());

        $this->line('');
        $this->info('2. Add these lines to .env:');
        $this->line(self::envSnippet());

        $this->line('');
        $this->info('3. Make sure the scheduler runs (cron):');
        $this->line('    * * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1');

        $this->line('');
        $this->info('4. Optional: run "php artisan log-monitor:ship --dry-run" to see what would be shipped.');

        $this->line('');
        foreach ($this->warnings() as $warning) {
            $this->warn('WARNING: ' . $warning);
        }

        return 0;
    }

    public static function loggingSnippet(): string
    {
        return <<<'PHP'
    'daily' => [
        'driver' => 'daily',
        'path' => storage_path('logs/laravel.log'),
        'days' => 14,
        'tap' => [\DevZone\LogMonitor\Logging\AddAppContext::class],
    ],
PHP;
    }

    public static function envSnippet(): string
    {
        return <<<'ENV'
    LOG_CHANNEL=daily
    LOG_STACK=daily
    LOG_LEVEL=warning

    LOG_MONITOR_ENABLED=true
    LOG_MONITOR_ENDPOINT=https://monitor.example.com/api/ingest
    LOG_MONITOR_API_KEY=
    LOG_MONITOR_CLIENT=acme
    LOG_MONITOR_APP="${APP_NAME}"
    LOG_MONITOR_MIN_LEVEL=warning
ENV;
    }

    /**
     * @return array<int, string>
     */
    private function warnings(): array
    {
        $warnings = [];
        $default = config('logging.default');
        $channels = $this->resolveChannels(is_string($default) ? $default : 'stack');

        $hasTap = false;
        foreach ($channels as $name) {
            $channel = config('logging.channels.' . $name);
            if (!is_array($channel)) {
                continue;
            }
            if (($channel['driver'] ?? null) === 'single') {
                $warnings[] = sprintf(
                    'Channel "%s" uses the "single" driver. That file grows without bound and is not matched by the '
                    . 'laravel-*.log glob. Switch it to the "daily" driver.',
                    $name
                );
            }
            $taps = isset($channel['tap']) && is_array($channel['tap']) ? $channel['tap'] : [];
            if (in_array(\DevZone\LogMonitor\Logging\AddAppContext::class, $taps, true)) {
                $hasTap = true;
            }
        }

        if (!$hasTap) {
            $warnings[] = 'No active channel has the AddAppContext tap yet; the log file will not be JSON until it is added.';
        }

        $level = $this->effectiveLevel($channels);
        if ($level === 'debug' && $this->laravel->environment('production')) {
            $warnings[] = 'LOG_LEVEL is "debug" in production. Every query and event will be written to disk; set LOG_LEVEL=warning.';
        }

        $endpoint = config('log-monitor.endpoint');
        if (is_string($endpoint) && $endpoint !== '' && !\DevZone\LogMonitor\Transport\HttpTransport::isSecureEndpoint($endpoint)) {
            $warnings[] = 'LOG_MONITOR_ENDPOINT is not an https URL. The package refuses to ship to it.';
        }

        return $warnings;
    }

    /**
     * Expand a stack channel into its member channels.
     *
     * @return array<int, string>
     */
    private function resolveChannels(string $name, int $depth = 0): array
    {
        $channel = config('logging.channels.' . $name);
        if (!is_array($channel) || $depth > 5) {
            return [$name];
        }
        if (($channel['driver'] ?? null) !== 'stack') {
            return [$name];
        }

        $members = isset($channel['channels']) && is_array($channel['channels']) ? $channel['channels'] : [];
        $resolved = [];
        foreach ($members as $member) {
            if (is_string($member)) {
                $resolved = array_merge($resolved, $this->resolveChannels($member, $depth + 1));
            }
        }

        return $resolved === [] ? [$name] : array_values(array_unique($resolved));
    }

    /**
     * @param array<int, string> $channels
     */
    private function effectiveLevel(array $channels): string
    {
        $env = getenv('LOG_LEVEL');
        if (is_string($env) && $env !== '') {
            return strtolower($env);
        }
        foreach ($channels as $name) {
            $level = config('logging.channels.' . $name . '.level');
            if (is_string($level) && $level !== '') {
                return strtolower($level);
            }
        }

        return 'debug';
    }
}
