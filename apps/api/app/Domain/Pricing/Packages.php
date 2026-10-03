<?php

namespace App\Domain\Pricing;

use App\Models\Quote;

/** The add-ons a quote can be bought with, and their prices: one place for checkout and totals. */
class Packages
{
    /** @return array<string, int> */
    public static function for(Quote $quote): array
    {
        return match ($quote->kind) {
            // Sofabuilt's launch options (config/sofabuilt.php).
            'plugin' => array_map(fn ($l) => (int) $l['eur'], config('sofabuilt.launch')),
            // A website has no store and no developer account; the ads are what it can add.
            'site' => array_intersect_key(Estimator::PACKAGE_PRICES, ['marketingLaunch' => 1]),
            default => Estimator::PACKAGE_PRICES,
        };
    }

    /** A readable line name for the Stripe receipt. */
    public static function label(Quote $quote, string $key): string
    {
        if ($quote->kind === 'plugin') {
            return (string) (config("sofabuilt.launch.$key.en") ?? $key);
        }

        return ucfirst((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $key));
    }

    /** @param  array<string, mixed>  $chosen */
    public static function total(Quote $quote, array $chosen): int
    {
        $total = (int) $quote->price_eur;
        foreach (self::for($quote) as $key => $eur) {
            if (! empty($chosen[$key])) {
                $total += $eur;
            }
        }

        return $total;
    }
}
