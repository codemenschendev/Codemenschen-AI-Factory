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
 * With a source (meta, google) every number is limited to the visitors who arrived from it, so an
 * ad's visitors can be read on their own: how far they scrolled, what they clicked, where they left.
 */
class AnalyticsReport
{
    public const SOURCES = ['meta', 'google'];

    /** Signals of what a visitor did on a page (App\Domain\Analytics\Analytics::CLIENT_EVENTS). */
    private const ENGAGEMENT = ['scroll_depth', 'section_view', 'ui_click', 'faq_open', 'tool_use', 'page_leave'];

    /** @return array<string, mixed> */
    public function summary(int $days, ?string $source = null): array
    {
        $since = now()->subDays($days)->startOfDay();
        $source = in_array($source, self::SOURCES, true) ? $source : null;
        $fromSource = $source === null ? null : AnalyticsEvent::where('created_at', '>=', $since)->whereNotNull('visitor')
            ->where(fn ($q) => $source === 'meta'
                ? $q->where('utm_source', 'meta')->orWhere('click_id', 'fbclid')
                : $q->where('utm_source', 'google')->orWhere('click_id', 'gclid'))
            ->distinct()->pluck('visitor')->all();
        $events = fn () => AnalyticsEvent::where('created_at', '>=', $since)
            ->when($fromSource !== null, fn ($q) => $q->whereIn('visitor', $fromSource));

        $visitorsWith = fn (array $names) => (int) $events()->whereIn('name', $names)->whereNotNull('visitor')->distinct()->count('visitor');

        $daily = $events()->where('name', 'page_view')
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as views'), DB::raw('COUNT(DISTINCT visitor) as visitors'))
            ->groupBy('day')->orderBy('day')->get()
            ->map(fn ($r) => ['day' => (string) $r->day, 'views' => (int) $r->views, 'visitors' => (int) $r->visitors])->all();

        $top = fn (string $column, array $names = ['page_view'], int $limit = 10) => $events()->whereIn('name', $names)->whereNotNull($column)
            ->select($column, DB::raw('COUNT(DISTINCT visitor) as visitors'))
            ->groupBy($column)->orderByDesc('visitors')->limit($limit)->get()
            ->map(fn ($r) => ['key' => (string) $r->{$column}, 'visitors' => (int) $r->visitors])->all();

        // A paid order has no visitor: with a source it counts when the quote was made by one of its visitors.
        $paid = AnalyticsEvent::where('created_at', '>=', $since)->where('name', 'order_paid')
            ->when($fromSource !== null, fn ($q) => $q->whereIn('quote_id', $events()->where('name', 'quote_created')->whereNotNull('quote_id')->pluck('quote_id')))
            ->get(['quote_id', 'props']);

        return [
            'days' => $days,
            'source' => $source,
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
            'engagement' => $this->engagement($events()->whereIn('name', self::ENGAGEMENT)->whereNotNull('visitor')->get(['visitor', 'name', 'props'])),
            'journeys' => $this->journeys($events),
        ];
    }

    /**
     * What visitors did on the pages: how deep they scrolled, which sections they saw, what they
     * clicked, which questions they opened, and how long they stayed. Counted in PHP: the props are
     * JSON, and the same code then runs on Postgres and on the SQLite of the tests.
     *
     * @param  Collection<int, AnalyticsEvent>  $rows
     * @return array<string, mixed>
     */
    private function engagement(Collection $rows): array
    {
        $by = fn (string $name, string $prop, int $limit) => $rows->where('name', $name)
            ->groupBy(fn ($e) => (string) ($e->props[$prop] ?? ''))->filter(fn ($g, $k) => $k !== '')
            ->map(fn ($g, $k) => ['key' => (string) $k, 'visitors' => $g->pluck('visitor')->unique()->count()])
            ->sortByDesc('visitors')->take($limit)->values()->all();

        $seconds = $rows->where('name', 'page_leave')->groupBy('visitor')
            ->map(fn ($g) => (int) $g->sum(fn ($e) => (int) ($e->props['seconds'] ?? 0)))->values()->sort()->values();
        $buckets = ['0-10s' => [0, 10], '10-30s' => [10, 30], '30-60s' => [30, 60], '1-3min' => [60, 180], '3min+' => [180, PHP_INT_MAX]];

        return [
            'scroll' => array_map(fn ($pct) => ['key' => $pct.'%', 'visitors' => $rows->where('name', 'scroll_depth')
                ->filter(fn ($e) => (int) ($e->props['pct'] ?? 0) === $pct)->pluck('visitor')->unique()->count()], [25, 50, 75, 100]),
            'time' => array_map(fn ($k, $r) => ['key' => $k, 'visitors' => $seconds->filter(fn ($s) => $s >= $r[0] && $s < $r[1])->count()], array_keys($buckets), $buckets),
            'median_seconds' => $seconds->isEmpty() ? null : (int) $seconds[intdiv($seconds->count(), 2)],
            'sections' => $by('section_view', 'section', 12),
            'clicks' => $by('ui_click', 'label', 15),
            'faq' => $by('faq_open', 'q', 10),
            'tools' => $by('tool_use', 'section', 5),
        ];
    }

    /**
     * The latest visitors, one line each: where they came from, how long they stayed, how far they
     * scrolled, and the steps they took in order. Scrolling and sections are folded into the numbers.
     *
     * @param  \Closure(): \Illuminate\Database\Eloquent\Builder<AnalyticsEvent>  $events
     * @return list<array<string, mixed>>
     */
    private function journeys(\Closure $events, int $limit = 30): array
    {
        $latest = $events()->whereNotNull('visitor')->select('visitor', DB::raw('MAX(id) as last_id'))
            ->groupBy('visitor')->orderByDesc('last_id')->limit($limit)->pluck('visitor');
        if ($latest->isEmpty()) {
            return [];
        }
        $label = fn (AnalyticsEvent $e) => match ($e->name) {
            'page_view' => 'view '.$e->path,
            'ui_click' => 'click "'.($e->props['label'] ?? '').'"',
            'faq_open' => 'faq "'.($e->props['q'] ?? '').'"',
            'tool_use' => 'tool '.($e->props['section'] ?? ''),
            'wizard_step' => 'wizard '.($e->props['step'] ?? ''),
            'cta_click' => 'cta '.($e->props['cta'] ?? ''),
            'scroll_depth', 'section_view', 'page_leave' => null,
            default => $e->name,
        };

        return $events()->whereIn('visitor', $latest)->orderBy('id')
            ->get(['visitor', 'name', 'path', 'device', 'utm_source', 'utm_campaign', 'click_id', 'referrer', 'props', 'created_at'])
            ->groupBy('visitor')
            ->map(function (Collection $g) use ($label) {
                $first = $g->first();
                $from = $g->first(fn ($e) => $e->utm_source || $e->click_id || $e->referrer);
                $steps = [];
                foreach ($g as $e) {
                    $l = $label($e);
                    if ($l !== null && end($steps) !== $l) {
                        $steps[] = mb_substr($l, 0, 90);
                    }
                }

                return [
                    'visitor' => substr((string) $first->visitor, 0, 6),
                    'at' => $first->created_at?->toIso8601String(),
                    'last_at' => $g->last()->created_at?->toIso8601String(),
                    'device' => $first->device,
                    'source' => $from?->utm_source ?: ($from?->click_id ?: ($from?->referrer ?: 'direct')),
                    'campaign' => $g->first(fn ($e) => $e->utm_campaign)?->utm_campaign,
                    'seconds' => (int) $g->where('name', 'page_leave')->sum(fn ($e) => (int) ($e->props['seconds'] ?? 0)),
                    'max_scroll' => (int) $g->where('name', 'scroll_depth')->max(fn ($e) => (int) ($e->props['pct'] ?? 0)),
                    'sections' => $g->where('name', 'section_view')->count(),
                    'steps' => array_slice($steps, 0, 25),
                ];
            })
            ->sortByDesc('last_at')->values()->all();
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
