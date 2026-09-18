<?php

namespace DevZone\LogMonitor\Console;

use DevZone\LogMonitor\Support\KillSwitch;
use DevZone\LogMonitor\Transport\HttpTransport;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'log-monitor:install {--force : Overwrite an already published config file}';

    /** @var string */
    protected $description = 'Publish the log-monitor config and print the .env lines and setup checks';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'log-monitor-config',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->line('');
        $this->info('1. Add these lines to .env (the package does nothing until ENABLED is true):');
        $this->line(self::envSnippet());

        $this->line('');
        $this->info('2. Make sure the scheduler runs every minute (cron):');
        $this->line('    * * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1');

        $this->line('');
        $this->info('3. After changing .env on a server with cached config:');
        $this->line('    php artisan config:cache');

        $this->line('');
        $this->info('4. Check it: php artisan log-monitor:status');

        $this->line('');
        foreach ($this->warnings() as $warning) {
            $this->warn('WARNING: ' . $warning);
        }

        return 0;
    }

    public static function envSnippet(): string
    {
        return <<<'ENV'
    LOG_MONITOR_ENABLED=true
    LOG_MONITOR_ENDPOINT=https://monitor.example.com/api/v2/ingest
    LOG_MONITOR_API_KEY=
    LOG_MONITOR_CLIENT=acme
    LOG_MONITOR_APP="${APP_NAME}"
    LOG_MONITOR_LOG_LEVEL=warning
ENV;
    }

    /**
     * @return array<int, string>
     */
    private function warnings(): array
    {
        $warnings = [];

        if (!config('log-monitor.enabled', false)) {
            $warnings[] = 'log-monitor is disabled. Nothing is captured, shipped or scheduled until LOG_MONITOR_ENABLED=true.';
        }
        if (KillSwitch::isOn((string) config('log-monitor.kill_switch_path', ''))) {
            $warnings[] = 'log-monitor is switched off with log-monitor:off. Run log-monitor:on to resume.';
        }

        $transport = $this->laravel->make(HttpTransport::class);
        if ($transport->isConfigured() && !$transport->endpointAllowed()) {
            $warnings[] = 'LOG_MONITOR_ENDPOINT is not an https URL, so nothing will be sent. For a local monitoring server set LOG_MONITOR_ALLOW_HTTP=true (honoured only when APP_ENV is local).';
        }

        $channels = (array) config('logging.channels', []);
        foreach ($channels as $name => $channel) {
            $taps = is_array($channel) && isset($channel['tap']) && is_array($channel['tap']) ? $channel['tap'] : [];
            if (in_array(\DevZone\LogMonitor\Logging\AddAppContext::class, $taps, true)) {
                $warnings[] = sprintf(
                    'The "%s" log channel still uses the v1 AddAppContext tap. v2 captures logs directly and does not read log files; remove the tap to get plain log lines back.',
                    (string) $name
                );
            }
        }

        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $spool = (string) config('log-monitor.spool.path', '');
            if ($spool !== '' && is_dir($spool) && @fileowner($spool) !== posix_geteuid()) {
                $owner = @posix_getpwuid((int) @fileowner($spool));
                $me = @posix_getpwuid(posix_geteuid());
                $warnings[] = sprintf(
                    'The spool folder is owned by "%s" but this command runs as "%s". PHP-FPM and the scheduler must be able to write, rename and delete files there (same user or a shared group).',
                    is_array($owner) ? $owner['name'] : '?',
                    is_array($me) ? $me['name'] : '?'
                );
            }
        }

        return $warnings;
    }
}
