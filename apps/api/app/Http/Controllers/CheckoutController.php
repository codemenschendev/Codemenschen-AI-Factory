<?php

namespace App\Http\Controllers;

use App\Domain\Pricing\Estimator;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Checkout\Session as StripeSession;
use Stripe\StripeClient;

class CheckoutController extends Controller
{
    /**
     * Turn a quote into an order and a Stripe Checkout Session. Totals are
     * recomputed here from the stored quote — never taken from the client.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'quote_id' => 'required|uuid|exists:quotes,id',
            'email' => 'required|email',
            'name' => 'nullable|string|max:120',
            'packages' => 'array',
            'packages.storePublishing' => 'boolean',
            'packages.transferAssist' => 'boolean',
            'packages.marketingLaunch' => 'boolean',
            'ad_budget_monthly_eur' => 'nullable|integer|in:'.implode(',', Estimator::AD_BUDGET_OPTIONS),
            // FAGG § 18 express waiver — must be an explicit choice, never defaulted.
            'fagg_waiver' => 'required|boolean',
            'locale' => 'nullable|in:de,en',
        ]);

        $quote = Quote::findOrFail($data['quote_id']);
        abort_if($quote->status === 'converted', 409, 'Quote already used');
        abort_if($quote->valid_until->isPast(), 410, 'Quote expired');

        $customer = Customer::firstOrCreate(
            ['email' => strtolower($data['email'])],
            ['name' => $data['name'] ?? null, 'locale' => $data['locale'] ?? $quote->locale],
        );

        $packages = $data['packages'] ?? [];
        $total = Estimator::oneTimeTotal($quote->price_eur, $packages);

        $order = Order::create([
            'customer_id' => $customer->id,
            'quote_id' => $quote->id,
            'packages' => $packages,
            'ad_budget_monthly_eur' => $data['ad_budget_monthly_eur'] ?? 0,
            'total_one_time_eur' => $total,
            'hosting_monthly_eur' => $quote->hosting_monthly_eur,
            'fagg_waiver' => $data['fagg_waiver'],
            'fagg_waiver_at' => $data['fagg_waiver'] ? now() : null,
            'fagg_waiver_ip' => $data['fagg_waiver'] ? $request->ip() : null,
            'locale' => $data['locale'] ?? $quote->locale,
        ]);

        $secret = config('services.stripe.secret');
        if (! $secret) {
            // Staging: everything except the actual charge works end-to-end.
            return response()->json([
                'order_id' => $order->id,
                'payment' => 'unconfigured',
                'message' => 'Stripe is not configured yet (staging).',
            ], 503);
        }

        $stripe = new StripeClient($secret);
        $session = $this->createSession($stripe, $order, $quote);
        $order->update(['stripe_checkout_session_id' => $session->id]);

        return response()->json(['order_id' => $order->id, 'checkout_url' => $session->url], 201);
    }

    private function createSession(StripeClient $stripe, Order $order, Quote $quote): StripeSession
    {
        $locale = $order->locale;
        $front = rtrim(config('services.frontend_url'), '/');
        $name = $quote->listing_slug
            ? ucfirst($quote->listing_slug).' — App development'
            : 'Custom app development';

        $lineItems = [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'eur',
                'unit_amount' => $quote->price_eur * 100,
                'product_data' => ['name' => $name],
            ],
        ]];
        foreach (Estimator::PACKAGE_PRICES as $key => $fee) {
            if (! empty($order->packages[$key])) {
                $lineItems[] = [
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => 'eur',
                        'unit_amount' => $fee * 100,
                        'product_data' => ['name' => ucfirst(preg_replace('/(?<!^)[A-Z]/', ' $0', $key))],
                    ],
                ];
            }
        }

        // Type B hosting is a separate subscription started at delivery, and ad
        // budget is billed separately after campaign approval — neither belongs
        // in this one-time session (appwerk doc 27 decision, doc 05 rules).
        return $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => $lineItems,
            'customer_email' => $order->customer->email,
            'client_reference_id' => $order->id,
            'locale' => $locale,
            'invoice_creation' => ['enabled' => true],
            'success_url' => "$front/$locale/success?order={$order->id}",
            'cancel_url' => "$front/$locale/checkout?quote={$quote->id}",
        ]);
    }
}
