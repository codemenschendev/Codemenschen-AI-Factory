<?php

namespace App\Console\Commands;

use App\Domain\Ads\PublisherRegistry;
use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * The answer to "is Facebook connected yet": one line per platform, from the platform itself.
 *
 * Reads only. It exchanges the refresh token, opens the ad account, and reports what it found.
 * Nothing is created and nothing spends. Safe to run any time, including from the boss's chair.
 *
 * The answer is also kept, so the admin tile says "connected" only after the platform said so.
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
            // A platform the owner switched off is not a fault: it is not checked and not counted.
            if (PublisherRegistry::paused($p->key())) {
                $rows[] = [$p->key(), 'paused', '', 'switched off in the admin panel'];

                continue;
            }
            $missing = $p->missing();
            if ($missing !== []) {
                Setting::write(PublisherRegistry::verifiedKey($p->key()), null, 'factory:ads-check');
                $rows[] = [$p->key(), 'not configured', '', 'missing: '.implode(', ', $missing)];
                $allOk = false;

                continue;
            }
            if ($this->option('no-verify')) {
                $rows[] = [$p->key(), 'configured', '', 'not verified (--no-verify)'];

                continue;
            }
            $v = $p->verify();
            Setting::write(PublisherRegistry::verifiedKey($p->key()), [
                'ok' => (bool) $v['ok'],
                'at' => now()->toIso8601String(),
                'account' => $v['account'] ?: null,
                'detail' => $v['detail'] ?: null,
            ], 'factory:ads-check');
            $rows[] = [$p->key(), $v['ok'] ? 'OK' : 'FAILED', (string) $v['account'], (string) $v['detail']];
            $allOk = $allOk && $v['ok'];
        }

        $this->table(['platform', 'state', 'account', 'detail'], $rows);

        return $allOk ? self::SUCCESS : self::FAILURE;
    }
}
