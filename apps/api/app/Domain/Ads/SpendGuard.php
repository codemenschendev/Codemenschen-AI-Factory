<?php

namespace App\Domain\Ads;

use App\Models\MarketingCampaign;
use App\Models\Setting;
use App\Services\Notify;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Three walls between an ad and a bill nobody agreed to (step 3 of the funnel, 2026-09-19).
 *
 * 1. The platform's own limits, set when the campaign is published: a test gets a lifetime
 *    budget and an end date, so Meta stops by itself; a campaign also gets a spend cap.
 * 2. This class, before anything starts: the kill switch, the most one campaign may spend, and
 *    the most all running campaigns together may spend in a day.
 * 3. The watch, every 15 minutes (factory:ads-guard): what each running campaign has spent,
 *    and a pause the moment it is over its total, over its day, or past its end.
 *
 * The kill switch pauses everything that runs and keeps anything from starting until an
 * operator turns it off again.
 */
class SpendGuard
{
    public const KILL = 'ads.kill';

    public const MAX_CAMPAIGN = 'ads.max_campaign_eur';

    public const MAX_DAILY_TOTAL = 'ads.max_daily_total_eur';

    /** Defaults until the owner sets them: a validation test is a few hundred euros. */
    public const DEFAULT_MAX_CAMPAIGN = 500;

    public const DEFAULT_MAX_DAILY_TOTAL = 100;

    /** A day may run over its share by this much before the watch stops it: platforms pace unevenly. */
    private const DAY_SLACK = 1.5;

    /** Failed looks in a row before the operators hear about it. */
    private const FAILS_BEFORE_ALERT = 3;

    public function __construct(private readonly PublisherRegistry $registry, private readonly Notify $notify) {}

    public static function killed(): bool
    {
        return (bool) Setting::read(self::KILL, false);
    }

    /** @return array{killed:bool,max_campaign_eur:int,max_daily_total_eur:int,running_daily_eur:float} */
    public function limits(): array
    {
        return [
            'killed' => self::killed(),
            'max_campaign_eur' => (int) Setting::read(self::MAX_CAMPAIGN, self::DEFAULT_MAX_CAMPAIGN),
            'max_daily_total_eur' => (int) Setting::read(self::MAX_DAILY_TOTAL, self::DEFAULT_MAX_DAILY_TOTAL),
            'running_daily_eur' => round(MarketingCampaign::where('platform_status', 'active')->get()->sum(fn ($c) => $c->dailyEur()), 2),
        ];
    }

    /**
     * Why this campaign may not start now. Empty means it may.
     *
     * @return list<string>
     */
    public function blockers(MarketingCampaign $campaign): array
    {
        $limits = $this->limits();
        $out = [];
        if ($limits['killed']) {
            $out[] = 'The kill switch is on: no ad may start until an operator turns it off.';
        }
        if ((int) $campaign->ad_budget_monthly_eur <= 0 && ! $campaign->spend_cap_eur) {
            $out[] = 'There is no budget: nobody has paid for this spend.';
        }
        if ($campaign->spend_cap_eur !== null && $campaign->spend_cap_eur > $limits['max_campaign_eur']) {
            $out[] = "The budget of {$campaign->spend_cap_eur} EUR is over the limit of {$limits['max_campaign_eur']} EUR for one campaign.";
        }
        if ($campaign->ends_at !== null && $campaign->ends_at->isPast()) {
            $out[] = 'The campaign has ended.';
        }
        $after = $limits['running_daily_eur'] + $campaign->dailyEur();
        if ($after > $limits['max_daily_total_eur']) {
            $out[] = sprintf('All running ads together would spend %.2f EUR a day, over the limit of %d EUR.', $after, $limits['max_daily_total_eur']);
        }

        return $out;
    }

    /**
     * The watch. Reads what every running campaign has spent and stops the ones that must stop.
     *
     * @return array{checked:int,stopped:list<int>,failed:list<int>}
     */
    public function watch(): array
    {
        $out = ['checked' => 0, 'stopped' => [], 'failed' => []];
        foreach (MarketingCampaign::where('platform_status', 'active')->get() as $campaign) {
            try {
                $spend = $this->registry->for($campaign->platform)->spend($campaign);
                Cache::forget("ads.guard.fails.{$campaign->id}");
            } catch (\Throwable $e) {
                $out['failed'][] = $campaign->id;
                $fails = Cache::increment("ads.guard.fails.{$campaign->id}");
                Log::warning('ads guard: spend unreadable', ['campaign' => $campaign->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
                if ($fails === self::FAILS_BEFORE_ALERT) {
                    $this->notify->money("Ad #{$campaign->id}: spend unreadable",
                        "The spend guard could not read what ad campaign #{$campaign->id} ({$campaign->platform}) has spent, {$fails} times in a row. "
                        .'The platform limits still hold, but look at it: '.mb_substr($e->getMessage(), 0, 200));
                }

                continue;
            }
            $out['checked']++;
            $campaign->update(['spent_eur' => $spend['total'], 'spent_today_eur' => $spend['today'], 'spend_checked_at' => now(),
                'impressions' => $spend['impressions'] ?? $campaign->impressions, 'link_clicks' => $spend['clicks'] ?? $campaign->link_clicks]);

            $reason = match (true) {
                $campaign->spend_cap_eur !== null && $spend['total'] >= $campaign->spend_cap_eur => 'cap_reached',
                $campaign->ends_at !== null && $campaign->ends_at->isPast() => 'ended',
                $spend['today'] > $campaign->dailyEur() * self::DAY_SLACK && $spend['today'] > 5 => 'day_overspent',
                default => null,
            };
            if ($reason !== null) {
                $this->stop($campaign, $reason);
                $out['stopped'][] = $campaign->id;
            }
        }

        return $out;
    }

    /** Stops one campaign. The row says stopped even if the platform call fails; the alert says which. */
    public function stop(MarketingCampaign $campaign, string $reason): void
    {
        $error = null;
        try {
            $this->registry->for($campaign->platform)->pause($campaign);
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 200);
        }
        $campaign->update(['platform_status' => $error === null ? 'paused' : 'failed', 'stopped_reason' => $reason,
            'publish_error' => $error === null ? $campaign->publish_error : "Pause failed: $error"]);
        $this->notify->money("Ad #{$campaign->id} stopped: $reason", sprintf(
            'The spend guard stopped ad campaign #%d (%s): %s. Spent %.2f EUR of %s, %.2f EUR today.%s',
            $campaign->id, $campaign->platform, $reason, $campaign->spent_eur,
            $campaign->spend_cap_eur !== null ? $campaign->spend_cap_eur.' EUR' : 'no total cap', $campaign->spent_today_eur,
            $error === null ? '' : " THE PAUSE FAILED ON THE PLATFORM, stop it by hand: $error"));
    }

    /**
     * The kill switch. On: every running campaign is paused and nothing may start. Off: things
     * may start again, one by one, by a person; nothing is resumed automatically.
     *
     * @return list<int> the campaigns it stopped
     */
    public function kill(bool $on, string $by): array
    {
        Setting::write(self::KILL, $on, $by);
        if (! $on) {
            $this->notify->system("Ads kill switch turned off by $by. Nothing was restarted.");

            return [];
        }
        $stopped = [];
        foreach (MarketingCampaign::where('platform_status', 'active')->get() as $campaign) {
            $this->stop($campaign, 'kill_switch');
            $stopped[] = $campaign->id;
        }
        $this->notify->money('Ads kill switch ON', "Kill switch turned on by $by. Stopped: ".($stopped === [] ? 'nothing was running' : '#'.implode(', #', $stopped)).'.');

        return $stopped;
    }
}
