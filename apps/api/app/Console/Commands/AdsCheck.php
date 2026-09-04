<?php

namespace App\Console\Commands;

use App\Domain\Ads\PublisherRegistry;
use Illuminate\Console\Command;

/**
 * The answer to "is Facebook connected yet": one line per platform, from the platform itself.
 *
 * Reads only. It exchanges the refresh token, opens the ad account, and reports what it found.
 * Nothing is created and nothing spends. Safe to run any time, including from the boss's chair.
 */
class AdsCheck extends Command
{
    protected $signature = 'factory:ads-check {--no-verify : only report configuration, make no API call}';

    protected $description = 'Report which ad platforms are configured and whether their credentials actually work';

    public function handle(PublisherRegistry $registry): int
    {
        $rows = [];
        $allOk = true;

        foreach ($registry->all() as $p) {
            $missing = $p->missing();
            if ($missing !== []) {
                $rows[] = [$p->key(), 'not configured', '', 'missing: '.implode(', ', $missing)];
                $allOk = false;

                continue;
            }
            if ($this->option('no-verify')) {
                $rows[] = [$p->key(), 'configured', '', 'not verified (--no-verify)'];

                continue;
            }
            $v = $p->verify();
            $rows[] = [$p->key(), $v['ok'] ? 'OK' : 'FAILED', (string) $v['account'], (string) $v['detail']];
            $allOk = $allOk && $v['ok'];
        }

        $this->table(['platform', 'state', 'account', 'detail'], $rows);

        return $allOk ? self::SUCCESS : self::FAILURE;
    }
}
