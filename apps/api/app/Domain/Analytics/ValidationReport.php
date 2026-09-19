<?php

namespace App\Domain\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\LandingSignup;
use App\Models\MarketingCampaign;
use App\Models\Prototype;
use Carbon\CarbonInterface;

/**
 * The validation report (step 4 of the funnel, 2026-09-19): did people want it?
 *
 * The funnel of one campaign from the ad to the confirmed sign-up, and a verdict against goals
 * that were set before the test ran, so nobody reads the numbers into a yes afterwards. The
 * verdict is arithmetic, not a model's opinion: the conversion to confirmed sign-ups against its
 * goal, the cost of one sign-up against its goal, and a range that says how sure the numbers are.
 */
class ValidationReport
{
    /** Goals when nobody set others: one visitor in ten signs up, a sign-up costs at most 5 EUR. */
    public const DEFAULT_RATE = 0.10;

    public const DEFAULT_CPL = 5.0;

    /** Fewer visitors than this and the report says it is too early, whatever the rate. */
    public const MIN_VISITORS = 50;

    /** @return array<string,mixed> */
    public function build(Prototype $campaign): array
    {
        $site = Prototype::where('parent_id', $campaign->id)->where('kind', 'site')->first();
        $tests = MarketingCampaign::where('prototype_id', $campaign->id)->get();
        $test = $tests->sortByDesc('id')->first();
        $from = $site?->published_at;
        $to = $test?->ends_at !== null && $test->ends_at->isPast() ? $test->ends_at->copy()->addDay() : now();

        $events = fn () => AnalyticsEvent::query()->where('props->prototype', $site?->id ?? '-')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))->where('created_at', '<=', $to);
        // A visitor is counted once a day: the analytics hash is salted per day and keeps nobody
        // across days, on purpose.
        $visitors = (int) $events()->where('name', 'landing_view')->selectRaw("count(distinct visitor || '-' || date(created_at)) as n")->value('n');
        $fromAds = (int) $events()->where('name', 'landing_view')->where(fn ($q) => $q->where('utm_source', 'meta')->orWhere('click_id', 'fbclid'))
            ->selectRaw("count(distinct visitor || '-' || date(created_at)) as n")->value('n');
        $signups = LandingSignup::where('prototype_id', $site?->id ?? '-')->get();
        $confirmed = $signups->where('status', 'confirmed')->count();

        $spend = round((float) $tests->sum('spent_eur'), 2);
        $impressions = (int) $tests->sum('impressions');
        $clicks = (int) $tests->sum('link_clicks');
        $goals = [
            'rate' => (float) ($test?->strategy['goals']['rate'] ?? self::DEFAULT_RATE),
            'cpl' => (float) ($test?->strategy['goals']['cpl'] ?? self::DEFAULT_CPL),
        ];
        $rate = $visitors > 0 ? $confirmed / $visitors : null;
        $cpl = $spend > 0 && $confirmed > 0 ? round($spend / $confirmed, 2) : null;

        return [
            'from' => $from?->toIso8601String(),
            'to' => $to->toIso8601String(),
            'running' => $test?->platform_status === 'active',
            'funnel' => [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $impressions > 0 ? round($clicks / $impressions, 4) : null,
                'visitors' => $visitors,
                'visitors_from_ads' => $fromAds,
                'signups' => $signups->count(),
                'confirmed' => $confirmed,
                'rate' => $rate === null ? null : round($rate, 4),
                'rate_range' => $visitors > 0 ? self::wilson($confirmed, $visitors) : null,
                'spend_eur' => $spend,
                'cost_per_signup_eur' => $cpl,
            ],
            'goals' => $goals,
            'verdict' => self::verdict($visitors, $rate, $spend, $confirmed, $cpl, $goals),
            'days' => $from === null ? [] : $this->days($site, $from, $to),
        ];
    }

    /**
     * go: both goals met (or the rate met and nothing was spent). no_go: the rate missed and the
     * cost did not save it. unclear: one met, one missed. too_early: not enough visitors to say.
     */
    public static function verdict(int $visitors, ?float $rate, float $spend, int $confirmed, ?float $cpl, array $goals): string
    {
        if ($visitors < self::MIN_VISITORS || $rate === null) {
            return 'too_early';
        }
        $rateOk = $rate >= $goals['rate'];
        $cplOk = $spend <= 0 ? null : ($confirmed > 0 && $cpl !== null && $cpl <= $goals['cpl']);

        return match (true) {
            $rateOk && $cplOk !== false => 'go',
            ! $rateOk && $cplOk !== true => 'no_go',
            default => 'unclear',
        };
    }

    /**
     * The 95% range the true rate lies in (Wilson score interval). With 100 visitors and 10
     * sign-ups it is about 5% to 17%: the report says so rather than printing "10%" as if exact.
     *
     * @return array{0:float,1:float}
     */
    public static function wilson(int $hits, int $n): array
    {
        $z = 1.96;
        $p = $hits / $n;
        $den = 1 + $z * $z / $n;
        $mid = ($p + $z * $z / (2 * $n)) / $den;
        $half = $z * sqrt($p * (1 - $p) / $n + $z * $z / (4 * $n * $n)) / $den;

        return [round(max(0, $mid - $half), 4), round(min(1, $mid + $half), 4)];
    }

    /** @return list<array{date:string,visitors:int,signups:int,confirmed:int}> */
    private function days(?Prototype $site, CarbonInterface $from, CarbonInterface $to): array
    {
        $views = AnalyticsEvent::query()->where('props->prototype', $site?->id ?? '-')->where('name', 'landing_view')
            ->whereBetween('created_at', [$from, $to])->selectRaw('date(created_at) as d, count(distinct visitor) as n')
            ->groupBy('d')->pluck('n', 'd');
        $rows = LandingSignup::where('prototype_id', $site?->id ?? '-')->get();
        $out = [];
        for ($day = $from->copy()->startOfDay(); $day->lte($to) && count($out) < 31; $day = $day->copy()->addDay()) {
            $d = $day->toDateString();
            $out[] = ['date' => $d, 'visitors' => (int) ($views[$d] ?? 0),
                'signups' => $rows->filter(fn ($r) => $r->created_at->toDateString() === $d)->count(),
                'confirmed' => $rows->filter(fn ($r) => $r->confirmed_at?->toDateString() === $d)->count()];
        }

        return $out;
    }
}
