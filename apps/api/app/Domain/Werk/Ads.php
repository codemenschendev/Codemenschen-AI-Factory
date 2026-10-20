<?php

namespace App\Domain\Werk;

use App\Domain\Ads\GoogleAdsPublisher;
use App\Domain\Ads\MetaAdsPublisher;
use App\Domain\Ads\PublisherRegistry;
use App\Models\MarketingCampaign;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Appwerk's own advertising, for Werk and werk.ai: the campaigns Appwerk runs for itself on its own
 * accounts (MarketingCampaign::own(), the admin's "own campaigns" screen), never a customer's.
 *
 * Read only. Each published campaign is read from its platform with the same calls the console's
 * traffic screen and the spend guard use (GoogleAdsPublisher::report, MetaAdsPublisher::daily).
 * Nothing is created, changed or started. A platform's answer is cached ten minutes; a platform
 * that fails gets its row with the error, the other one still answers.
 */
class Ads
{
    public const PLATFORMS = ['google', 'meta'];

    private const CACHE_MINUTES = 10;

    public function __construct(private readonly PublisherRegistry $registry) {}

    /** @return array<string,mixed> */
    public function build(Range $range): array
    {
        $own = MarketingCampaign::own()->whereIn('platform', self::PLATFORMS)->whereNotNull('published_at')
            ->orderBy('id')->get()
            ->filter(fn (MarketingCampaign $c) => ! empty($c->platform_ref['campaign_id']));

        $platforms = [];
        $campaigns = [];
        $daily = [];
        foreach (self::PLATFORMS as $platform) {
            $publisher = $this->registry->for($platform);
            $mine = $own->where('platform', $platform)->values();
            $row = ['platform' => $platform, 'connected' => $publisher->isConfigured()] + self::totals([]) + ['error' => null];
            if (! $row['connected'] || $mine->isEmpty()) {
                $platforms[] = $row;

                continue;
            }
            try {
                $key = "werk.ads.{$platform}.{$range->from}.{$range->to}.".md5($mine->pluck('id')->implode(','));
                // Only an answer is cached: an exception leaves the cache empty for the next ask.
                $read = Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), fn () => $this->read($publisher, $mine, $range));
            } catch (\Throwable $e) {
                $platforms[] = ['error' => mb_substr($e->getMessage(), 0, 300)] + $row;

                continue;
            }

            $byDay = array_fill_keys($range->days(), ['spend' => 0.0, 'clicks' => 0, 'conversions' => 0.0]);
            $all = [];
            foreach ($read as $id => $days) {
                $campaign = $mine->firstWhere('id', $id);
                $sum = self::totals($days);
                $campaigns[] = [
                    'platform' => $platform,
                    'name' => (string) ($campaign->strategy['name'] ?? 'Campaign #'.$id),
                    'status' => (string) $campaign->platform_status,
                    'spend' => $sum['spend'],
                    'impressions' => $sum['impressions'],
                    'clicks' => $sum['clicks'],
                    'conversions' => $sum['conversions'],
                ];
                foreach ($days as $d) {
                    if (! isset($byDay[$d['date']])) {
                        continue;
                    }
                    $byDay[$d['date']]['spend'] += $d['spend'];
                    $byDay[$d['date']]['clicks'] += $d['clicks'];
                    $byDay[$d['date']]['conversions'] += $d['conversions'];
                    $all[] = $d;
                }
            }
            foreach ($byDay as $date => $d) {
                $daily[] = ['date' => $date, 'platform' => $platform, 'spend' => round($d['spend'], 2),
                    'clicks' => $d['clicks'], 'conversions' => round($d['conversions'], 1)];
            }
            $platforms[] = ['platform' => $platform, 'connected' => true] + self::totals($all) + ['error' => null];
        }
        usort($daily, fn ($a, $b) => [$a['date'], $a['platform']] <=> [$b['date'], $b['platform']]);

        return [
            // Both ad accounts bill in euros (docs/ads-accounts.md).
            'currency' => 'EUR',
            'from' => $range->from,
            'to' => $range->to,
            'platforms' => $platforms,
            'campaigns' => $campaigns,
            'daily' => $daily,
        ];
    }

    /**
     * Per campaign id, its days with numbers.
     *
     * @param  Collection<int, MarketingCampaign>  $campaigns
     * @return array<int, list<array{date:string, spend:float, impressions:int, clicks:int, conversions:float}>>
     */
    private function read(object $publisher, Collection $campaigns, Range $range): array
    {
        $out = [];
        foreach ($campaigns as $campaign) {
            $out[$campaign->id] = match (true) {
                $publisher instanceof GoogleAdsPublisher => array_map(fn (array $r) => [
                    'date' => (string) ($r['segments']['date'] ?? ''),
                    'spend' => ((int) ($r['metrics']['costMicros'] ?? 0)) / 1_000_000,
                    'impressions' => (int) ($r['metrics']['impressions'] ?? 0),
                    'clicks' => (int) ($r['metrics']['clicks'] ?? 0),
                    'conversions' => (float) ($r['metrics']['conversions'] ?? 0),
                ], $publisher->report($campaign, 'SELECT segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions'
                    ." FROM campaign WHERE campaign.resource_name = '{campaign}' AND segments.date BETWEEN '{$range->from}' AND '{$range->to}'")),
                $publisher instanceof MetaAdsPublisher => $publisher->daily($campaign, $range->from, $range->to),
                default => [],
            };
        }

        return $out;
    }

    /**
     * @param  iterable<array{spend:float, impressions:int, clicks:int, conversions:float}>  $days
     * @return array{spend:float, impressions:int, clicks:int, conversions:float, ctr:?float, cpc:?float, cost_per_conversion:?float}
     */
    private static function totals(iterable $days): array
    {
        $spend = 0.0;
        $impressions = 0;
        $clicks = 0;
        $conversions = 0.0;
        foreach ($days as $d) {
            $spend += $d['spend'];
            $impressions += $d['impressions'];
            $clicks += $d['clicks'];
            $conversions += $d['conversions'];
        }

        return [
            'spend' => round($spend, 2),
            'impressions' => $impressions,
            'clicks' => $clicks,
            'conversions' => round($conversions, 1),
            // Percent, as the platforms show it.
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : null,
            'cpc' => $clicks > 0 ? round($spend / $clicks, 2) : null,
            'cost_per_conversion' => $conversions > 0 ? round($spend / $conversions, 2) : null,
        ];
    }
}
