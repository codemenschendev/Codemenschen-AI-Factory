<?php

namespace App\Domain\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\MarketingCampaign;

/**
 * What one of our own campaigns brought in (2026-09-23): from Google's clicks to paid orders.
 *
 * The clicks and the money come from Google (the spend guard reads them). Everything after the
 * click comes from our own cookieless analytics: a visit is a page view carrying the campaign's
 * utm_campaign tag, and the later steps are the same visitor on the same day. The visitor hash
 * changes every day, so somebody who clicks today and buys tomorrow is not counted here. The
 * numbers are a floor, never an inflation.
 */
class CampaignFunnel
{
    /** Steps that show a visitor did more than look. */
    private const INTEREST = ['wizard_step', 'cta_click', 'prototype_requested', 'quote_created', 'checkout_started'];

    /**
     * @return array{tag:string,clicks:int,visits:int,interest:int,quotes:int,orders:int,revenue_eur:int,
     *               cost_per_visit:?float,cost_per_quote:?float,cost_per_order:?float}
     */
    public function for(MarketingCampaign $campaign): array
    {
        $tag = $campaign->trackingTag();

        // Each visit as visitor|day, the unit every later step is matched on.
        $visits = AnalyticsEvent::where('name', 'page_view')->where('utm_campaign', $tag)->whereNotNull('visitor')
            ->get(['visitor', 'created_at'])
            ->map(fn ($e) => $e->visitor.'|'.$e->created_at->toDateString())
            ->unique()->values();

        $later = $visits->isEmpty() ? collect() : AnalyticsEvent::whereIn('name', [...self::INTEREST, 'quote_created'])
            ->whereIn('visitor', $visits->map(fn ($k) => explode('|', $k)[0])->unique()->values())
            ->get(['name', 'visitor', 'created_at', 'quote_id'])
            ->filter(fn ($e) => $visits->contains($e->visitor.'|'.$e->created_at->toDateString()));

        $quoteIds = $later->where('name', 'quote_created')->pluck('quote_id')->filter()->unique()->values();
        $paid = $quoteIds->isEmpty() ? collect() : AnalyticsEvent::where('name', 'order_paid')->whereIn('quote_id', $quoteIds)->get(['props']);

        $counts = [
            'tag' => $tag,
            'clicks' => (int) $campaign->link_clicks,
            'visits' => $visits->count(),
            'interest' => $later->whereIn('name', self::INTEREST)->map(fn ($e) => $e->visitor.'|'.$e->created_at->toDateString())->unique()->count(),
            'quotes' => $later->where('name', 'quote_created')->map(fn ($e) => $e->visitor.'|'.$e->created_at->toDateString())->unique()->count(),
            'orders' => $paid->count(),
            'revenue_eur' => (int) $paid->sum(fn ($e) => (int) ($e->props['amount_eur'] ?? 0)),
        ];
        $spent = (float) $campaign->spent_eur;
        $per = fn (int $n) => $n > 0 && $spent > 0 ? round($spent / $n, 2) : null;

        return $counts + [
            'cost_per_visit' => $per($counts['visits']),
            'cost_per_quote' => $per($counts['quotes']),
            'cost_per_order' => $per($counts['orders']),
        ];
    }
}
