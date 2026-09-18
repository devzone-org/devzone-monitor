<?php

namespace DevZone\LogMonitor\Console;

use DevZone\LogMonitor\Spool\SpoolWriter;
use DevZone\LogMonitor\Support\KillSwitch;
use Illuminate\Console\Command;

class OffCommand extends Command
{
    /** @var string */
    protected $signature = 'log-monitor:off {--purge : Also delete everything waiting in the spool}';

    /** @var string */
    protected $description = 'Emergency stop: switch capture and shipping off without changing .env';

    public function handle(): int
    {
        $path = (string) config('log-monitor.kill_switch_path', '');
        if (!KillSwitch::turnOn($path)) {
            $this->error('Could not create the switch file at ' . $path);

            return 1;
        }
        $this->info('log-monitor switched off. Capture stops with the next request; run log-monitor:on to resume.');

        if ($this->option('purge')) {
            $directory = $this->laravel->make(SpoolWriter::class)->directory();
            $removed = 0;
            foreach (array_merge($directory->batches(), [$directory->currentPath()]) as $file) {
                if (is_file($file)) {
                    $directory->delete($file);
                    $removed++;
                }
            }
            $this->line(sprintf('Deleted %d spool file(s).', $removed));
        }

        return 0;
    }
}
