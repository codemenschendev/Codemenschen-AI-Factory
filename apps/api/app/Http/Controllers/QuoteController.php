<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\Listings;
use App\Domain\Pricing\Estimator;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteController extends Controller
{
    /**
     * Create a quote — either from a catalog listing (fixed price) or from
     * the wizard's structured custom input. Prices are computed server-side
     * only; the client's own numbers are never trusted.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'listing_slug' => 'nullable|string|max:40',
            'idea' => 'required_without:listing_slug|nullable|string|max:5000',
            'audience' => 'required_without:listing_slug|nullable|in:consumer,b2b,both',
            'platform' => 'required_without:listing_slug|nullable|in:web,mobile,both',
            'features' => 'array',
            'features.*' => 'string|in:'.implode(',', array_keys(Estimator::FEATURES)),
            'locale' => 'nullable|in:de,en',
        ]);

        if (! empty($data['listing_slug'])) {
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
            'listing_slug' => $data['listing_slug'] ?? null,
            'idea' => $data['idea'] ?? null,
            'audience' => $data['audience'] ?? null,
            'platform' => $data['platform'] ?? null,
            'features' => array_values($data['features'] ?? []),
            'breakdown' => $breakdown,
            'price_eur' => $breakdown['price'],
            'app_type' => $breakdown['appType'],
            'hosting_monthly_eur' => $breakdown['hostingMonthly'],
            'locale' => $data['locale'] ?? 'de',
            'valid_until' => now()->addDays(14),
        ]);

        return response()->json($this->present($quote), 201);
    }

    public function show(Quote $quote): JsonResponse
    {
        return response()->json($this->present($quote));
    }

    private function present(Quote $quote): array
    {
        return [
            'id' => $quote->id,
            'listing_slug' => $quote->listing_slug,
            'price_eur' => $quote->price_eur,
            'app_type' => $quote->app_type,
            'hosting_monthly_eur' => $quote->hosting_monthly_eur,
            'breakdown' => $quote->breakdown,
            'packages' => Estimator::PACKAGE_PRICES,
            'ad_budget_options' => Estimator::AD_BUDGET_OPTIONS,
            'valid_until' => $quote->valid_until->toIso8601String(),
            'status' => $quote->status,
        ];
    }
}
