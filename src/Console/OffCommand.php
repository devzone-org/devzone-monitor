<?php

namespace DevZone\LogMonitor\Console;

use DevZone\LogMonitor\Spool\SpoolWriter;
use DevZone\LogMonitor\Support\KillSwitch;
use Illuminate\Console\Command;

class OffCommand extends Command
{
    /** @var string */
    protected $signature = 'log-monitor:off {--purge : Also delete everything waiting in the spool, quarantined batches included}';

    /** @var string */
    protected $description = 'Emergency stop: switch capture and shipping off without changing .env';

    public function handle(): int
    {
        $path = (string) config('log-monitor.kill_switch_path', '');
        if (!KillSwitch::turnOn($path)) {
            $this->error('Could not create the switch file at ' . $path);

            return 1;
        }
        $this->info('log-monitor switched off. Web requests stop capturing at once; running queue workers within a few seconds, after the job in hand. Run log-monitor:on to resume.');

        if ($this->option('purge')) {
            $removed = $this->laravel->make(SpoolWriter::class)->directory()->purge();
            $this->line(sprintf('Deleted %d spool file(s), including quarantined batches.', $removed));
        }

        return 0;
    }
}
