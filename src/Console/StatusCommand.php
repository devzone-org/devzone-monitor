<?php

namespace DevZone\LogMonitor\Console;

use DevZone\LogMonitor\LogMonitorServiceProvider;
use DevZone\LogMonitor\Spool\SpoolWriter;
use DevZone\LogMonitor\Support\KillSwitch;
use DevZone\LogMonitor\Transport\HttpTransport;
use Illuminate\Console\Command;

class StatusCommand extends Command
{
    /** @var string */
    protected $signature = 'log-monitor:status';

    /** @var string */
    protected $description = 'Show whether log-monitor is capturing, what is waiting in the spool and how the last ship went';

    public function handle(): int
    {
        $config = (array) config('log-monitor', []);
        $directory = $this->laravel->make(SpoolWriter::class)->directory();
        /** @var HttpTransport $transport */
        $transport = $this->laravel->make(HttpTransport::class);

        $current = $directory->currentPath();
        $batches = $directory->batches();
        $batchBytes = 0;
        foreach ($batches as $batch) {
            $batchBytes += (int) @filesize($batch);
        }
        $oldest = $batches !== [] ? basename($batches[0]) : '-';

        $rows = [
            ['Enabled (LOG_MONITOR_ENABLED)', empty($config['enabled']) ? 'no' : 'yes'],
            ['Switched off (log-monitor:off)', KillSwitch::isOn((string) ($config['kill_switch_path'] ?? '')) ? 'yes' : 'no'],
            ['Capturing now', LogMonitorServiceProvider::active($config) ? 'yes' : 'no'],
            ['Endpoint host', (string) ($transport->host() ?? '-')],
            ['Endpoint accepted', $transport->isConfigured() ? ($transport->endpointAllowed() ? 'yes' : 'no (https required)') : 'not configured'],
            ['Spool folder', $directory->path()],
            ['current.ndjson', is_file($current) ? self::bytes((int) @filesize($current)) : 'empty'],
            ['Batches waiting', count($batches) . ' (' . self::bytes($batchBytes) . ')'],
            ['Oldest waiting', $oldest],
            ['Quarantined (failed/)', (string) count($directory->failedBatches())],
        ];

        $last = $directory->lastRun();
        if ($last !== null) {
            $rows[] = ['Last ship run', (string) ($last['at'] ?? '-')];
            $rows[] = ['Last run sent', (string) ($last['records'] ?? 0) . ' record(s)'];
            $rows[] = ['Last run problem', (string) ($last['stopped'] ?? 'none')];
        } else {
            $rows[] = ['Last ship run', 'never'];
        }

        $this->table(['', ''], $rows);

        return 0;
    }

    private static function bytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.1f MB', $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return $bytes . ' B';
    }
}
