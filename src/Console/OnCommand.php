<?php

namespace DevZone\LogMonitor\Console;

use DevZone\LogMonitor\Support\KillSwitch;
use Illuminate\Console\Command;

class OnCommand extends Command
{
    /** @var string */
    protected $signature = 'log-monitor:on';

    /** @var string */
    protected $description = 'Undo log-monitor:off';

    public function handle(): int
    {
        $path = (string) config('log-monitor.kill_switch_path', '');
        if (!KillSwitch::turnOff($path)) {
            $this->error('Could not remove the switch file at ' . $path);

            return 1;
        }
        if (!config('log-monitor.enabled')) {
            $this->warn('Switch removed, but LOG_MONITOR_ENABLED is not true, so the package stays off.');

            return 0;
        }
        $this->info('log-monitor switched on.');

        return 0;
    }
}
