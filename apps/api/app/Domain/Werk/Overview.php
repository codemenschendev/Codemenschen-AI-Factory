<?php

namespace App\Domain\Werk;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Project;
use Illuminate\Foundation\Application;

/**
 * The overview of a site (Werk docs/API.md 7.9) for appmitki.com, built the way werk-connect's
 * buildShop sums a Node shop: every day of from..to, the lists since..to.
 *
 * What counts as sold is what the Payments domain records as paid: a `payments` row of status
 * succeeded, written by OrderFulfillment when Stripe confirms an order. Real money only, as on the
 * admin overview: orders charged in Stripe's sandbox (livemode false, or null from before the
 * switch) stay out. Only the Appmitki storefront (brand appmitki), since this is appmitki.com.
 * Paid change rounds and edit credit packs are charged too, but they write no payments row and
 * carry no live/sandbox mark, so they are not part of it.
 *
 * A refund is an order whose status is refunded; it counts on the day of its payment, as in
 * werk-connect. No names, no e-mails, no addresses: a product is the kind of thing bought.
 */
class Overview
{
    private const KINDS = ['app' => 'App', 'site' => 'Website', 'plugin' => 'WordPress plugin'];

    /** Projects past these are delivered or given up: not "processing" any more. */
    private const DONE = ['PUBLISHED', 'MARKETING', 'COMPLETED', 'FAILED'];

    /** @return array<string,mixed> */
    public function build(Range $range): array
    {
        [$start, $end] = $range->bounds();
        $rows = $this->paid()->whereBetween('payments.created_at', [$start, $end])
            ->orderBy('payments.created_at')
            ->get(['payments.id', 'payments.amount_eur', 'payments.created_at', 'orders.status as order_status',
                'orders.customer_id', 'quotes.kind as quote_kind', 'quotes.listing_slug']);

        // A buyer is returning when they had paid before this payment, also before the range.
        $earlier = $this->paid()->whereIn('orders.customer_id', $rows->pluck('customer_id')->unique()->values())
            ->where('payments.created_at', '<=', $end)
            ->orderBy('payments.created_at')->orderBy('payments.id')
            ->get(['payments.id', 'orders.customer_id'])
            ->groupBy('customer_id')
            ->map(fn ($g) => $g->first()->id);

        $daily = [];
        foreach ($range->days() as $day) {
            $daily[$day] = ['date' => $day, 'revenue' => 0.0, 'orders' => 0, 'refunds' => 0.0];
        }
        $products = [];
        $customers = ['new' => 0, 'returning' => 0];

        foreach ($rows as $row) {
            $day = Range::dayOf($row->created_at);
            if (! isset($daily[$day])) {
                continue;
            }
            $total = (float) $row->amount_eur;
            $daily[$day]['revenue'] = round($daily[$day]['revenue'] + $total, 2);
            $daily[$day]['orders']++;
            if ($row->order_status === 'refunded') {
                $daily[$day]['refunds'] = round($daily[$day]['refunds'] + $total, 2);
            }
            if ($day < $range->since) {
                continue;
            }
            $customers[($earlier[$row->customer_id] ?? $row->id) === $row->id ? 'new' : 'returning']++;
            $name = self::productName($row->quote_kind, $row->listing_slug);
            $products[$name] ??= ['name' => $name, 'quantity' => 0, 'revenue' => 0.0];
            $products[$name]['quantity']++;
            $products[$name]['revenue'] = round($products[$name]['revenue'] + $total, 2);
        }
        usort($products, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        $admin = rtrim((string) config('services.frontend_url'), '/').'/de/admin';

        return [
            'timezone' => Range::TIMEZONE,
            'currency' => 'EUR',
            'shop' => [
                'engine' => 'node',
                'currency' => 'EUR',
                'daily' => array_values($daily),
                'top_products' => array_slice($products, 0, 8),
                'customers' => $customers,
                // Appmitki's checkout offers no coupon codes.
                'coupons' => [],
                'status' => $this->status(),
                // Nothing Appmitki sells is a stocked item.
                'low_stock' => [],
                'out_of_stock' => 0,
            ],
            'content' => null,
            'seo' => null,
            'health' => [
                'site_health' => null,
                'updates' => null,
                'runtime' => 'Laravel '.Application::VERSION.', PHP '.PHP_VERSION,
            ],
            'links' => ['orders' => $admin, 'orders_processing' => $admin],
        ];
    }

    /** Succeeded payments of real, Appmitki orders, with the quote that says what was bought. */
    private function paid()
    {
        return Payment::query()
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->leftJoin('quotes', 'quotes.id', '=', 'orders.quote_id')
            ->where('payments.status', 'succeeded')
            ->where('orders.livemode', true)
            ->where('orders.brand', 'appmitki');
    }

    /**
     * Now, in WooCommerce's words: processing is a paid order whose project is still being made,
     * failed_24h an order that failed in the last day. Appmitki has no on-hold, and an unpaid
     * order is a checkout left open, not something waiting for the shop.
     *
     * @return array{processing:int, on_hold:int, pending:int, failed_24h:int}
     */
    private function status(): array
    {
        $live = fn () => Order::query()->where('livemode', true)->where('brand', 'appmitki');

        return [
            'processing' => Project::query()->whereNull('archived_at')->whereNotIn('status', self::DONE)
                ->whereIn('order_id', $live()->where('status', 'paid')->select('id'))->count(),
            'on_hold' => 0,
            'pending' => 0,
            'failed_24h' => $live()->where('status', 'failed')->where('updated_at', '>=', now()->subDay())->count(),
        ];
    }

    private static function productName(?string $kind, ?string $listing): string
    {
        $label = self::KINDS[$kind ?? 'app'] ?? ucfirst((string) $kind);

        // A catalog listing is our own product name, never the customer's words.
        return $listing ? $label.': '.mb_substr($listing, 0, 60) : $label;
    }
}
