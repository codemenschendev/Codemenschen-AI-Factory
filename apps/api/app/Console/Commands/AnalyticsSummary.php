<?php

namespace App\Console\Commands;

use App\Domain\Analytics\AnalyticsDigest;
use App\Services\Notify;
use Illuminate\Console\Command;

/**
 * The analytics digest, printed or posted to #appwerk-agents.
 *
 *   factory:analytics --days=30          print it
 *   factory:analytics --post             post the weekly digest (scheduler, Monday morning)
 *   factory:analytics --requests         answer "!stats appwerk [days]" requests the Buzz bot dropped
 *
 * The bot writes requests/stats-<event id>.json into the shared alert folder; this answers each
 * with a message file the bot posts. No shell, no database access for the bot or the agent.
 */
class AnalyticsSummary extends Command
{
    protected $signature = 'factory:analytics {--days=7} {--post} {--requests}';

    protected $description = 'Analytics digest for Buzz: print, post weekly, or answer !stats requests';

    public function handle(AnalyticsDigest $digest, Notify $notify): int
    {
        if ($this->option('requests')) {
            return $this->answerRequests($digest, $notify);
        }
        $days = $this->days($this->option('days'));
        $text = $digest->text($days);
        if ($this->option('post')) {
            $notify->buzz($text, 'agents');
        }
        $this->line($text);

        return self::SUCCESS;
    }

    private function answerRequests(AnalyticsDigest $digest, Notify $notify): int
    {
        $dir = rtrim((string) config('services.buzz.alert_dir'), '/').'/requests';
        if (! is_dir($dir)) {
            return self::SUCCESS;
        }
        foreach (glob($dir.'/stats-*.json') ?: [] as $file) {
            $req = json_decode((string) @file_get_contents($file), true);
            @unlink($file); // one answer per request, even when the file is unreadable
            if (! is_array($req)) {
                continue;
            }
            $notify->buzz($digest->text($this->days($req['days'] ?? 7)), 'agents');
        }

        return self::SUCCESS;
    }

    private function days(mixed $value): int
    {
        $days = (int) $value;

        return $days >= 1 && $days <= 90 ? $days : 7;
    }
}
