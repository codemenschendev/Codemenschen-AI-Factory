<?php

namespace App\Domain\Analytics;

use App\Models\AnalyticsEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The numbers the admin panel shows: traffic, where it came from, and how far visitors get.
 *
 * A funnel step counts distinct visitors of the period who did that step. Paid orders are counted
 * directly (the Stripe webhook has no visitor) and attributed to a source through their quote.
 */
class AnalyticsReport
{
    /** @return array<string, mixed> */
    public function summary(int $days): array
    {
        $since = now()->subDays($days)->startOfDay();
        $events = fn () => AnalyticsEvent::where('created_at', '>=', $since);

        $visitorsWith = fn (array $names) => (int) $events()->whereIn('name', $names)->whereNotNull('visitor')->distinct()->count('visitor');

        $daily = $events()->where('name', 'page_view')
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as views'), DB::raw('COUNT(DISTINCT visitor) as visitors'))
            ->groupBy('day')->orderBy('day')->get()
            ->map(fn ($r) => ['day' => (string) $r->day, 'views' => (int) $r->views, 'visitors' => (int) $r->visitors])->all();

        $top = fn (string $column, array $names = ['page_view'], int $limit = 10) => $events()->whereIn('name', $names)->whereNotNull($column)
            ->select($column, DB::raw('COUNT(DISTINCT visitor) as visitors'))
            ->groupBy($column)->orderByDesc('visitors')->limit($limit)->get()
            ->map(fn ($r) => ['key' => (string) $r->{$column}, 'visitors' => (int) $r->visitors])->all();

        $paid = $events()->where('name', 'order_paid')->get(['quote_id', 'props']);

        return [
            'days' => $days,
            'totals' => [
                'visitors' => $visitorsWith(['page_view']),
                'page_views' => (int) $events()->where('name', 'page_view')->count(),
                'orders_paid' => $paid->count(),
                'revenue_eur' => (int) $paid->sum(fn ($e) => (int) ($e->props['amount_eur'] ?? 0)),
            ],
            'daily' => $daily,
            'funnel' => [
                ['step' => 'visit', 'visitors' => $visitorsWith(['page_view'])],
                // Each step includes the later ones: a visitor who picks a listing goes straight to a
                // quote without a wizard step, and still showed interest.
                ['step' => 'interest', 'visitors' => $visitorsWith(['wizard_step', 'cta_click', 'prototype_requested', 'quote_created', 'checkout_started'])],
                ['step' => 'quote', 'visitors' => $visitorsWith(['quote_created', 'checkout_started'])],
                ['step' => 'checkout', 'visitors' => $visitorsWith(['checkout_started'])],
                ['step' => 'paid', 'visitors' => $paid->count()],
            ],
            'prototypes' => [
                'requested' => $visitorsWith(['prototype_requested']),
                'viewed' => $visitorsWith(['prototype_view']),
                'made_real' => (int) $events()->where('name', 'cta_click')->where('props->cta', 'prototype_make_real')->distinct()->count('visitor'),
            ],
            'pages' => $top('path'),
            'referrers' => $top('referrer'),
            'campaigns' => $top('utm_source', ['page_view'], 10),
            'devices' => $top('device', ['page_view'], 3),
            'paid_sources' => $this->paidSources($paid->pluck('quote_id')->filter()->values()),
        ];
    }

    /**
     * Where the paying visitors came from: the first page view of the visitor who made the quote,
     * on the day they made it. "direct" when that view had no referrer and no campaign.
     *
     * @param  Collection<int, string>  $quoteIds
     * @return list<array{key:string, orders:int}>
     */
    private function paidSources(Collection $quoteIds): array
    {
        if ($quoteIds->isEmpty()) {
            return [];
        }
        $counts = [];
        foreach (AnalyticsEvent::where('name', 'quote_created')->whereIn('quote_id', $quoteIds)->get(['visitor', 'created_at']) as $quote) {
            $first = AnalyticsEvent::where('visitor', $quote->visitor)->where('name', 'page_view')
                ->whereDate('created_at', $quote->created_at->toDateString())
                ->orderBy('id')->first(['utm_source', 'referrer', 'click_id']);
            $key = $first?->utm_source ?: ($first?->click_id === 'gclid' ? 'google ads' : ($first?->click_id === 'fbclid' ? 'meta ads' : ($first?->referrer ?: 'direct')));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);

        return array_map(fn ($k, $v) => ['key' => (string) $k, 'orders' => $v], array_keys($counts), $counts);
    }
}
