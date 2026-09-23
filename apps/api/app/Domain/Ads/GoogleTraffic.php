<?php

namespace App\Domain\Ads;

use App\Models\AnalyticsEvent;
use App\Models\MarketingCampaign;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * What Google knows about a campaign's traffic, read through the API so it can be shown in the
 * console (2026-09-23): nobody should need a Google login to see how an ad is doing.
 *
 * Day by day, per keyword, the searches people actually typed, devices, countries and hours.
 * Read-only reports, cached for a quarter of an hour: the numbers on Google itself lag by about
 * that much, and the API has a daily operation limit on our access level.
 */
class GoogleTraffic
{
    public const PERIODS = [7, 30, 90];

    private const CACHE_MINUTES = 15;

    public function __construct(private readonly GoogleAdsPublisher $google) {}

    /** @return array<string,mixed> */
    public function for(MarketingCampaign $campaign, int $days, bool $fresh = false): array
    {
        $key = "ads.traffic.{$campaign->id}.{$days}";
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), fn () => $this->read($campaign, $days) + ['read_at' => now()->toIso8601String()]);
    }

    /** @return array<string,mixed> */
    private function read(MarketingCampaign $campaign, int $days): array
    {
        $to = now()->toDateString();
        $from = now()->subDays($days - 1)->toDateString();
        $during = " AND segments.date BETWEEN '{$from}' AND '{$to}'";
        $metrics = 'metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions';
        $q = fn (string $gaql) => $this->google->report($campaign, $gaql);
        // The day series is the report; the breakdowns are extras. One of them failing (a view
        // Google refuses on this account, say) empties that part and says so, not the whole screen.
        $warnings = [];
        $extra = function (string $part, string $gaql) use ($q, &$warnings): array {
            try {
                return $q($gaql);
            } catch (\RuntimeException $e) {
                $warnings[] = $part.': '.$e->getMessage();

                return [];
            }
        };

        $daily = collect($q("SELECT segments.date, {$metrics} FROM campaign WHERE campaign.resource_name = '{campaign}'{$during}"))
            ->keyBy(fn ($r) => $r['segments']['date'] ?? '');
        $visits = $this->visitsByDay($campaign, $from);
        $own = self::own($campaign);
        $series = [];
        for ($d = Carbon::parse($from); $d->toDateString() <= $to; $d->addDay()) {
            $day = $d->toDateString();
            $series[] = ['date' => $day] + self::numbers($daily[$day] ?? []) + ['visits' => $own ? ($visits[$day] ?? 0) : null];
        }

        $keywords = collect($extra('keywords', "SELECT ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, ad_group_criterion.status,
                ad_group_criterion.quality_info.quality_score, {$metrics}
            FROM keyword_view WHERE campaign.resource_name = '{campaign}'{$during}"))
            ->map(fn ($r) => [
                'text' => $r['adGroupCriterion']['keyword']['text'] ?? '',
                'match' => strtolower($r['adGroupCriterion']['keyword']['matchType'] ?? ''),
                'status' => strtolower($r['adGroupCriterion']['status'] ?? ''),
                'quality' => isset($r['adGroupCriterion']['qualityInfo']['qualityScore']) ? (int) $r['adGroupCriterion']['qualityInfo']['qualityScore'] : null,
            ] + self::numbers($r))
            ->sortByDesc('impressions')->values();

        $terms = collect($extra('search_terms', "SELECT search_term_view.search_term, search_term_view.status, {$metrics}
            FROM search_term_view WHERE campaign.resource_name = '{campaign}'{$during}
            ORDER BY metrics.impressions DESC LIMIT 200"))
            ->map(fn ($r) => [
                'term' => $r['searchTermView']['searchTerm'] ?? '',
                'status' => strtolower($r['searchTermView']['status'] ?? ''),
            ] + self::numbers($r))
            ->values();

        $devices = self::grouped($extra('devices', "SELECT segments.device, {$metrics} FROM campaign WHERE campaign.resource_name = '{campaign}'{$during}"),
            fn ($r) => strtolower($r['segments']['device'] ?? 'other'));

        $countries = array_flip(GoogleAdsPublisher::COUNTRIES);
        $places = self::grouped($extra('countries', "SELECT geographic_view.country_criterion_id, geographic_view.location_type, {$metrics}
            FROM geographic_view WHERE campaign.resource_name = '{campaign}'{$during}"),
            fn ($r) => $countries[(int) ($r['geographicView']['countryCriterionId'] ?? 0)] ?? ('#'.($r['geographicView']['countryCriterionId'] ?? '?')));

        $hours = self::grouped($extra('hours', "SELECT segments.hour, {$metrics} FROM campaign WHERE campaign.resource_name = '{campaign}'{$during}"),
            fn ($r) => (string) (int) ($r['segments']['hour'] ?? 0));

        $total = self::sum($series);

        return [
            'from' => $from,
            'to' => $to,
            'totals' => $total + ['visits' => self::own($campaign) ? array_sum($visits) : null],
            'daily' => $series,
            'keywords' => $keywords,
            'search_terms' => $terms,
            'devices' => $devices,
            'countries' => $places,
            'hours' => $hours,
            'warnings' => $warnings,
        ];
    }

    /** Page views that carried this campaign's tag, per day. Only our own campaigns carry one. */
    private function visitsByDay(MarketingCampaign $campaign, string $from): array
    {
        if (! self::own($campaign)) {
            return [];
        }

        return AnalyticsEvent::where('name', 'page_view')->where('utm_campaign', $campaign->trackingTag())
            ->where('created_at', '>=', $from)->whereNotNull('visitor')
            ->get(['visitor', 'created_at'])
            ->groupBy(fn ($e) => $e->created_at->toDateString())
            ->map(fn ($g) => $g->pluck('visitor')->unique()->count())
            ->all();
    }

    private static function own(MarketingCampaign $campaign): bool
    {
        return $campaign->project_id === null && $campaign->prototype_id === null;
    }

    /** @return array{impressions:int,clicks:int,cost_eur:float,conversions:float,ctr:?float,cpc_eur:?float} */
    private static function numbers(array $row): array
    {
        $m = $row['metrics'] ?? $row;
        $impressions = (int) ($m['impressions'] ?? 0);
        $clicks = (int) ($m['clicks'] ?? 0);
        $cost = isset($m['costMicros']) ? (int) $m['costMicros'] / 1_000_000 : (float) ($m['cost_eur'] ?? 0);

        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'cost_eur' => round($cost, 2),
            'conversions' => round((float) ($m['conversions'] ?? 0), 1),
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : null,
            'cpc_eur' => $clicks > 0 ? round($cost / $clicks, 2) : null,
        ];
    }

    /** @param iterable<array<string,mixed>> $rows */
    private static function sum(iterable $rows): array
    {
        $t = ['impressions' => 0, 'clicks' => 0, 'cost_eur' => 0.0, 'conversions' => 0.0];
        foreach ($rows as $r) {
            foreach ($t as $k => $_) {
                $t[$k] += $r[$k] ?? 0;
            }
        }

        return self::numbers($t);
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private static function grouped(array $rows, callable $key): array
    {
        return collect($rows)->groupBy($key)
            ->map(fn ($g, $k) => ['key' => (string) $k] + self::sum($g->map(fn ($r) => self::numbers($r))))
            ->sortByDesc('impressions')->values()->all();
    }
}
