<?php

namespace Tests\Unit;

use App\Domain\Catalog\Listings;
use App\Domain\Pricing\Estimator;
use PHPUnit\Framework\TestCase;

/**
 * Every ready-made app idea costs what the price table promises for app development (owner's
 * decision 2026-10-02): the page said 149 to 1,500 EUR while ideas were listed at up to 3,900.
 */
class ListingPricesTest extends TestCase
{
    public function test_every_listing_price_is_inside_the_app_price_range(): void
    {
        foreach (Listings::ALL as $slug => $listing) {
            $this->assertGreaterThanOrEqual(Estimator::PRICE_MIN, $listing['price'], $slug);
            $this->assertLessThanOrEqual(Estimator::PRICE_MAX, $listing['price'], $slug);
        }
    }
}
