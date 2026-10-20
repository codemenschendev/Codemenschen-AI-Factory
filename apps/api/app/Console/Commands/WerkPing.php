<?php

namespace App\Console\Commands;

use App\Domain\Werk\Probe;
use Illuminate\Console\Command;

/**
 * The hourly ping to Werk (Werk docs/API.md 7.2): keeps appmitki.com's status fresh there, says
 * which werk-connect routes the site offers, and brings back the analytics beacon key the
 * storefront loads (7.6). Silent without WERK_TOKEN; a failure is logged and never fails the run.
 */
class WerkPing extends Command
{
    protected $signature = 'factory:werk-ping';

    protected $description = 'Ping Werk with the site probe token and keep the analytics beacon key it answers';

    public function handle(Probe $probe): int
    {
        if (! Probe::configured()) {
            $this->line('WERK_TOKEN is not set: no ping.');

            return self::SUCCESS;
        }
        $result = $probe->ping();
        $this->line($result['ok']
            ? 'Werk answered '.$result['status'].'; beacon key '.(Probe::analytics() !== null ? 'stored' : 'none').'.'
            : 'Werk ping failed ('.$result['status'].'): '.($result['message'] ?: 'no answer'));

        return self::SUCCESS;
    }
}
