<?php

namespace App\Console\Commands;

use App\Domain\Ads\SpendGuard;
use Illuminate\Console\Command;

/** The spend guard's watch: every running ad's spend, and a pause for any that must stop. */
class AdsGuard extends Command
{
    protected $signature = 'factory:ads-guard';

    protected $description = 'Read what every running ad campaign has spent and stop the ones over their limits';

    public function handle(SpendGuard $guard): int
    {
        $out = $guard->watch();
        $this->line(sprintf('checked %d, stopped %s, unreadable %s', $out['checked'],
            $out['stopped'] === [] ? 'none' : '#'.implode(', #', $out['stopped']),
            $out['failed'] === [] ? 'none' : '#'.implode(', #', $out['failed'])));

        return self::SUCCESS;
    }
}
