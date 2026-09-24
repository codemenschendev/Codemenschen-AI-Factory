<?php

namespace App\Http\Controllers;

use App\Domain\Ads\AdClick;
use App\Domain\Ads\Conversions;
use App\Domain\Analytics\Analytics;
use App\Domain\Catalog\Listings;
use App\Domain\Pricing\Estimator;
use App\Models\Order;
use App\Models\Prototype;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteController extends Controller
{
    /**
     * Create a quote — from a catalog listing (fixed price), from the wizard's structured custom
     * input, or for a website preview (kind site, one price). Prices are computed server-side
     * only; the client's own numbers are never trusted.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'listing_slug' => 'nullable|string|max:40',
            'prototype_id' => 'nullable|uuid',
            'idea' => 'required_without_all:listing_slug,prototype_id|nullable|string|max:5000',
            'audience' => 'required_without_all:listing_slug,prototype_id|nullable|in:consumer,b2b,both',
            'platform' => 'required_without_all:listing_slug,prototype_id|nullable|in:web,mobile,both',
            'features' => 'array',
            'features.*' => 'string|in:'.implode(',', array_keys(Estimator::FEATURES)),
            'locale' => 'nullable|in:de,en',
        ]);

        $prototype = null;
        if (! empty($data['prototype_id'])) {
            // The website is the preview itself, so only a finished, unsold site preview has a price.
            $prototype = Prototype::find($data['prototype_id']);
            abort_if($prototype === null || $prototype->kind !== 'site' || $prototype->parent_id !== null
                || $prototype->status !== 'ready' || $prototype->html === null, 422, 'Only a finished website preview can be bought.');
            abort_if($prototype->project_id !== null, 409, 'This website has been bought already.');
            $breakdown = [
                'kind' => 'site',
                'price' => Estimator::SITE_PRICE_EUR,
                'weeksLo' => 0,
                'weeksHi' => 0,
                'appType' => 'A',
                'hostingMonthly' => Estimator::SITE_HOSTING_MONTHLY_EUR,
                'hostingFreeMonths' => Estimator::SITE_HOSTING_FREE_MONTHS,
                'prototype' => $prototype->id,
            ];
        } elseif (! empty($data['listing_slug'])) {
            $listing = Listings::find($data['listing_slug']);
            abort_if($listing === null, 404, 'Unknown listing');
            $breakdown = [
                'price' => $listing['price'],
                'weeksLo' => $listing['weeksLo'],
                'weeksHi' => $listing['weeksHi'],
                'appType' => $listing['appType'],
                'hostingMonthly' => Estimator::HOSTING_MONTHLY[$listing['appType']],
                'listing' => $data['listing_slug'],
            ];
        } else {
            $breakdown = Estimator::estimate(
                $data['audience'],
                $data['platform'],
                array_values($data['features'] ?? []),
            );
        }

        $quote = Quote::create([
            'kind' => $breakdown['kind'] ?? 'app',
            'prototype_id' => $prototype?->id,
            'listing_slug' => $data['listing_slug'] ?? null,
            'idea' => $prototype !== null ? mb_substr((string) ($prototype->title ?: $prototype->prompt), 0, 200) : ($data['idea'] ?? null),
            'audience' => $data['audience'] ?? null,
            'platform' => $prototype !== null ? 'web' : ($data['platform'] ?? null),
            'features' => array_values($data['features'] ?? []),
            'breakdown' => $breakdown,
            'price_eur' => $breakdown['price'],
            'app_type' => $breakdown['appType'],
            'hosting_monthly_eur' => $breakdown['hostingMonthly'],
            'locale' => $data['locale'] ?? 'de',
            'valid_until' => now()->addDays(14),
        ]);

        // The preview stays up as long as the quote is valid: nobody should come back to buy and
        // find the page gone.
        if ($prototype !== null && $prototype->expires_at !== null && $prototype->expires_at->lt($quote->valid_until)) {
            $prototype->update(['expires_at' => $quote->valid_until]);
        }

        app(Analytics::class)->record('quote_created', $request, ['quote_id' => $quote->id, 'locale' => $quote->locale], [
            'source' => $prototype !== null ? 'site' : ($quote->listing_slug ? 'listing' : 'custom'),
            'listing' => $quote->listing_slug,
            'price_eur' => $quote->price_eur,
        ]);

        // A visitor who came from an ad and allowed ad measurement: the click is kept with the
        // quote (a purchase may follow days later) and the lead is reported now.
        if (($click = AdClick::fromRequest($request)) !== null) {
            $quote->update(['ad_click' => Conversions::forQuote($click, $request)]);
            app(Conversions::class)->lead($click, $request, $quote);
        }

        return response()->json($this->present($quote), 201);
    }

    public function show(Quote $quote): JsonResponse
    {
        return response()->json($this->present($quote));
    }

    private function present(Quote $quote): array
    {
        $site = $quote->kind === 'site';

        return [
            'id' => $quote->id,
            'kind' => $quote->kind,
            'prototype_id' => $quote->prototype_id,
            'listing_slug' => $quote->listing_slug,
            'title' => $site ? $quote->idea : null,
            'price_eur' => $quote->price_eur,
            'app_type' => $quote->app_type,
            'hosting_monthly_eur' => $quote->hosting_monthly_eur,
            'hosting_free_months' => $site ? Estimator::SITE_HOSTING_FREE_MONTHS : 0,
            'breakdown' => $quote->breakdown,
            // A website has no store and no developer account; the ads are what it can add.
            'packages' => $site ? array_intersect_key(Estimator::PACKAGE_PRICES, ['marketingLaunch' => 1]) : Estimator::PACKAGE_PRICES,
            'ad_budget_options' => Estimator::AD_BUDGET_OPTIONS,
            'store_locales' => $site ? [] : Order::SUPPORTED_STORE_LOCALES,
            'valid_until' => $quote->valid_until->toIso8601String(),
            'status' => $quote->status,
        ];
    }
}
