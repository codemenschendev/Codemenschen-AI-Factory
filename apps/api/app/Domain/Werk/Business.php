<?php

namespace App\Domain\Werk;

use App\Models\AnalyticsEvent;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Appwerk's funnel and pipeline for Werk and werk.ai, as counts only: no names, no e-mails, no
 * ideas, no prompts. Days in Europe/Vienna, both ends included.
 *
 *   traffic.visitors          distinct visitors with a page view. Appwerk's own analytics count
 *                             without cookies: a visitor is a hash that changes every day, so a
 *                             person who comes on two days counts twice (visitor-days).
 *   traffic.page_views        page views.
 *   traffic.by_source         visitors who arrived from a Meta ad (utm_source meta or fbclid), a
 *                             Google ad (utm_source google or gclid), or anywhere else.
 *   funnel.form_starts        first keystroke in the preview form (wizard_step form_start).
 *   funnel.prototypes_requested  free previews asked for (prototype_requested), of every kind.
 *   funnel.quotes_created     quotes made (quote_created).
 *   funnel.checkouts_started  Stripe checkouts opened (checkout_started).
 *   funnel.orders_paid        real paid orders of the Appmitki storefront (as payments.count).
 *   prototypes_by_kind        prototype_requested per kind (site, app, ads, email, campaign).
 *                             Counted from the analytics events, since a free prototype row is
 *                             deleted when it expires after a week.
 *   signups                   accounts created (new customers rows: sign-in, preview or checkout).
 *   signins                   completed sign-ins (signin_completed).
 *   desk                      the Appmitki chat: chats opened, chats with at least one message of
 *                             the visitor, chats whose scope was ready, quotes made from a chat.
 *   projects_new              projects created in the range from real Appmitki orders, per kind.
 *   projects_by_status        now: the projects of real Appmitki orders that are not archived.
 *   payments                  real money (livemode) of Appmitki orders, as the overview counts it:
 *                             count, gross in EUR, and the part of it refunded since.
 *
 * The analytics events are the storefront's (Appmitki and Sofabuilt share one table), and bots
 * are left out when they are recorded.
 */
class Business
{
    /** @return array<string,mixed> */
    public function build(Range $range): array
    {
        [$start, $end] = $range->bounds();
        $events = fn () => AnalyticsEvent::query()->whereBetween('created_at', [$start, $end]);

        $source = "CASE WHEN utm_source = 'meta' OR click_id = 'fbclid' THEN 'meta' WHEN utm_source = 'google' OR click_id = 'gclid' THEN 'google' ELSE 'other' END";
        $pageVisitors = $events()->where('name', 'page_view')->whereNotNull('visitor')->distinct()->pluck('visitor');
        // A visitor's source is the ad they came from, if any of their events of the range says so
        // (MIN picks google or meta over other).
        $seen = $pageVisitors->flip();
        $sourceOf = $events()->whereNotNull('visitor')
            ->selectRaw("visitor, MIN($source) as src")->groupBy('visitor')->pluck('src', 'visitor')
            ->filter(fn ($src, $visitor) => $seen->has($visitor));

        $kinds = $events()->where('name', 'prototype_requested')->get(['props'])
            ->countBy(fn ($e) => (string) ($e->props['kind'] ?? 'unknown'))->sortKeys()->all();
        $formStarts = $events()->where('name', 'wizard_step')->get(['props'])
            ->filter(fn ($e) => ($e->props['step'] ?? null) === 'form_start')->count();

        $live = fn () => Order::query()->where('livemode', true)->where('brand', 'appmitki');
        $paid = Payment::query()->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('payments.status', 'succeeded')->where('orders.livemode', true)->where('orders.brand', 'appmitki')
            ->whereBetween('payments.created_at', [$start, $end])
            ->get(['payments.amount_eur', 'orders.status as order_status']);
        $projects = fn () => Project::query()->whereIn('order_id', $live()->select('id'));

        $desk = fn () => DB::table('desk_sessions')->where('brand', 'appmitki')->whereBetween('created_at', [$start, $end]);

        return [
            'from' => $range->from,
            'to' => $range->to,
            'timezone' => Range::TIMEZONE,
            'traffic' => [
                'visitors' => $pageVisitors->count(),
                'page_views' => (int) $events()->where('name', 'page_view')->count(),
                'by_source' => [
                    'meta' => $sourceOf->filter(fn ($s) => $s === 'meta')->count(),
                    'google' => $sourceOf->filter(fn ($s) => $s === 'google')->count(),
                    'other' => $sourceOf->filter(fn ($s) => $s === 'other')->count(),
                ],
            ],
            'funnel' => [
                'form_starts' => $formStarts,
                'prototypes_requested' => (int) $events()->where('name', 'prototype_requested')->count(),
                'quotes_created' => (int) $events()->where('name', 'quote_created')->count(),
                'checkouts_started' => (int) $events()->where('name', 'checkout_started')->count(),
                'orders_paid' => $paid->count(),
            ],
            'prototypes_by_kind' => (object) $kinds,
            'signups' => Customer::query()->whereBetween('created_at', [$start, $end])->count(),
            'signins' => (int) $events()->where('name', 'signin_completed')->count(),
            'desk' => [
                'chats' => $desk()->count(),
                'with_message' => DB::table('desk_messages')->whereIn('desk_session_id', $desk()->select('id'))
                    ->where('role', 'customer')->distinct()->count('desk_session_id'),
                'scope_ready' => $desk()->where('ready', true)->count(),
                'quotes' => $desk()->whereNotNull('quote_id')->count(),
            ],
            'projects_new' => [
                'total' => $projects()->whereBetween('created_at', [$start, $end])->count(),
                'by_kind' => (object) $projects()->whereBetween('created_at', [$start, $end])
                    ->selectRaw('kind, COUNT(*) as n')->groupBy('kind')->pluck('n', 'kind')->map(fn ($n) => (int) $n)->sortKeys()->all(),
            ],
            'projects_by_status' => (object) $projects()->whereNull('archived_at')
                ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->sortKeys()->all(),
            'payments' => [
                'count' => $paid->count(),
                'gross' => round((float) $paid->sum('amount_eur'), 2),
                'refunded' => round((float) $paid->where('order_status', 'refunded')->sum('amount_eur'), 2),
                'currency' => 'EUR',
            ],
        ];
    }
}
