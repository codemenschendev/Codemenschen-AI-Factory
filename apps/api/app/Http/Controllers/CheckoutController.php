<?php

namespace App\Http\Controllers;

use App\Domain\Analytics\Analytics;
use App\Domain\Payments\StripeKeys;
use App\Domain\Pricing\Estimator;
use App\Domain\Pricing\Packages;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Quote;
use App\Services\CareService;
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
            // Which keys a quote may carry depends on what it is for (Packages::for).
            'packages.*' => 'boolean',
            'ad_budget_monthly_eur' => 'nullable|integer|in:'.implode(',', Estimator::AD_BUDGET_OPTIONS),
            // FAGG § 18 express waiver — must be an explicit choice, never defaulted.
            'fagg_waiver' => 'required|boolean',
            // The terms are a condition of the sale, so the server refuses an order without them.
            // A box that is only checked in the browser is decoration.
            'terms' => 'required|accepted',
            // Sofabuilt: Care with its free first months, ticked by the buyer (never by default).
            'care_trial' => 'nullable|boolean',
            'locale' => 'nullable|in:de,en',
            // Store-listing languages; defaults to every supported one.
            'store_locales' => 'nullable|array|min:1',
            'store_locales.*' => 'string|in:'.implode(',', Order::SUPPORTED_STORE_LOCALES),
        ]);

        $quote = Quote::findOrFail($data['quote_id']);
        abort_if($quote->status === 'converted', 409, 'Quote already used');
        abort_if($quote->valid_until->isPast(), 410, 'Quote expired');

        $customer = Customer::firstOrCreate(
            ['email' => strtolower($data['email'])],
            ['name' => $data['name'] ?? null, 'locale' => $data['locale'] ?? $quote->locale],
        );

        $packages = array_intersect_key($data['packages'] ?? [], Packages::for($quote));
        if ($quote->kind === 'site') {
            abort_if($quote->prototype === null, 410, 'The website preview is gone.');
        }
        $total = Packages::total($quote, $packages);

        $order = Order::create([
            'customer_id' => $customer->id,
            'quote_id' => $quote->id,
            'brand' => $quote->brand ?? 'appmitki',
            'packages' => $packages,
            'ad_budget_monthly_eur' => $data['ad_budget_monthly_eur'] ?? 0,
            'total_one_time_eur' => $total,
            'hosting_monthly_eur' => $quote->hosting_monthly_eur,
            'fagg_waiver' => $data['fagg_waiver'],
            'fagg_waiver_at' => $data['fagg_waiver'] ? now() : null,
            'fagg_waiver_ip' => $data['fagg_waiver'] ? $request->ip() : null,
            'care_trial' => ($quote->kind === 'plugin' || Packages::fromDesk($quote)) && ! empty($data['care_trial']),
            'terms_accepted_at' => now(),
            'terms_accepted_ip' => $request->ip(),
            'locale' => $data['locale'] ?? $quote->locale,
            'store_locales' => array_values(array_unique($data['store_locales'] ?? Order::SUPPORTED_STORE_LOCALES)),
        ]);

        app(Analytics::class)->record('checkout_started', $request, ['quote_id' => $order->quote_id, 'order_id' => $order->id, 'customer_id' => $order->customer_id], [
            'total_eur' => $order->total_one_time_eur,
        ]);

        $stripeKeys = app(StripeKeys::class);
        $order->update(['livemode' => $stripeKeys->live()]);
        $secret = $stripeKeys->secret();
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
        $front = self::front($quote);
        $name = match (true) {
            $quote->kind === 'plugin' => 'Sofabuilt plugin: '.mb_substr((string) ($quote->breakdown['scope']['name'] ?? 'WordPress plugin'), 0, 80),
            $quote->kind === 'site' => 'Website: '.mb_substr((string) ($quote->idea ?: 'one page'), 0, 80),
            (bool) $quote->listing_slug => ucfirst($quote->listing_slug).' — App development',
            ! empty($quote->breakdown['scope']['name']) => 'App: '.mb_substr((string) $quote->breakdown['scope']['name'], 0, 80),
            default => 'Custom app development',
        };

        $lineItems = [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'eur',
                'unit_amount' => $quote->price_eur * 100,
                'product_data' => ['name' => $name],
            ],
        ]];
        foreach (Packages::for($quote) as $key => $fee) {
            if (! empty($order->packages[$key])) {
                $lineItems[] = [
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => 'eur',
                        'unit_amount' => $fee * 100,
                        'product_data' => ['name' => Packages::label($quote, $key)],
                    ],
                ];
            }
        }

        // Type B hosting is a separate subscription started at delivery, and ad
        // budget is billed separately after campaign approval — neither belongs
        // in this one-time session (appwerk doc 27 decision, doc 05 rules).
        // With the Care trial the card is kept for the monthly charge after the free months; Stripe
        // shows the buyer that it is saved, and the text below says what it is for.
        $care = $order->care_trial ? [
            'customer_creation' => 'always',
            'payment_intent_data' => ['setup_future_usage' => 'off_session'],
            'custom_text' => ['submit' => ['message' => $order->locale === 'de'
                ? sprintf('Wartungsplan: die ersten %d Monate gratis, danach %d € pro Monat. Jederzeit kündbar.', CareService::trialMonths(), CareService::monthlyForQuote($quote))
                : sprintf('Care: the first %d months are free, then €%d a month. Cancel any time.', CareService::trialMonths(), CareService::monthlyForQuote($quote))]],
        ] : [];

        return $stripe->checkout->sessions->create($care + [
            'mode' => 'payment',
            'line_items' => $lineItems,
            'customer_email' => $order->customer->email,
            'client_reference_id' => $order->id,
            'locale' => $locale,
            'invoice_creation' => ['enabled' => true],
            'success_url' => "$front/$locale/success?order={$order->id}&kind={$quote->kind}",
            'cancel_url' => match (true) {
                $quote->kind === 'plugin' => "$front/$locale/desk",
                Packages::fromDesk($quote) => "$front/$locale/prototype",
                default => "$front/$locale/checkout?quote={$quote->id}",
            },
        ]);
    }

    /** The storefront the buyer came from: Stripe sends them back there. */
    public static function front(Quote $quote): string
    {
        return rtrim((string) (($quote->brand ?? 'appmitki') === 'sofabuilt'
            ? config('services.sofabuilt_url', 'https://sofabuilt.codemenschen.at')
            : config('services.frontend_url')), '/');
    }
}
