<?php

namespace DevZone\LogMonitor\Console;

use DevZone\LogMonitor\LogMonitorServiceProvider;
use DevZone\LogMonitor\Shipping\Shipper;
use DevZone\LogMonitor\Transport\HttpTransport;
use Illuminate\Console\Command;

/**
 * Ships the spool. Scheduled every minute; safe to run by hand.
 * Never writes through Log:: and never throws out of handle().
 */
class ShipCommand extends Command
{
    /** @var string */
    protected $signature = 'log-monitor:ship {--dry-run : Count what would be sent without sending or rotating}';

    /** @var string */
    protected $description = 'Send spooled monitoring records to the monitoring server';

    public function handle(): int
    {
        try {
            $config = (array) config('log-monitor', []);
            if (empty($config['enabled'])) {
                $this->line('log-monitor is disabled. Set LOG_MONITOR_ENABLED=true to enable it.');

                return 0;
            }
            if (!LogMonitorServiceProvider::active($config)) {
                $this->line('log-monitor is switched off (log-monitor:off). Run log-monitor:on to resume.');

                return 0;
            }

            /** @var HttpTransport $transport */
            $transport = $this->laravel->make(HttpTransport::class);
            if (!$transport->isConfigured()) {
                $this->warn('log-monitor is not configured: set LOG_MONITOR_ENDPOINT and LOG_MONITOR_API_KEY.');

                return 0;
            }
            if (!$transport->endpointAllowed()) {
                $this->error('log-monitor: endpoint rejected, it must be an absolute https URL. Plain http needs LOG_MONITOR_ALLOW_HTTP=true and a local APP_ENV.');

                return 1;
            }

            $dryRun = (bool) $this->option('dry-run');
            $summary = $this->laravel->make(Shipper::class)->run($dryRun);

            $this->line(sprintf(
                'log-monitor: %s %d record%s from %d batch%s%s%s%s.',
                $dryRun ? 'would send' : 'sent',
                $summary['records'],
                $summary['records'] === 1 ? '' : 's',
                $summary['batches'],
                $summary['batches'] === 1 ? '' : 'es',
                $summary['pending_batches'] > 0 && !$dryRun ? sprintf(', %d batch%s waiting', $summary['pending_batches'], $summary['pending_batches'] === 1 ? '' : 'es') : '',
                $summary['malformed'] > 0 ? sprintf(', %d malformed line%s skipped', $summary['malformed'], $summary['malformed'] === 1 ? '' : 's') : '',
                $summary['stopped'] !== null ? ' (stopped: ' . $summary['stopped'] . '; will retry next run)' : ''
            ));
            if ($summary['quarantined'] > 0) {
                $this->warn(sprintf('%d batch file(s) were rejected repeatedly and moved to the spool failed/ folder.', $summary['quarantined']));
            }

            return $summary['stopped'] !== null && $summary['stopped'] !== 'time budget reached' ? 1 : 0;
        } catch (\Throwable $e) {
            $message = get_class($e) . ': ' . $e->getMessage();
            $key = (string) config('log-monitor.api_key');
            if ($key !== '') {
                $message = str_replace($key, '[REDACTED]', $message);
            }
            error_log('[log-monitor] ship aborted: ' . $message);
            $this->error('log-monitor: ' . $message);

            return 1;
        }
    }
}
