<?php

namespace App\Domain\Pricing;

use App\Domain\Sofabuilt\Platforms;
use App\Models\Quote;

/** The add-ons a quote can be bought with, and their prices: one place for checkout and totals. */
class Packages
{
    /** @return array<string, int> */
    public static function for(Quote $quote): array
    {
        if (self::fromDesk($quote)) {
            // A desk quote (Sofabuilt plugin or Appmitki app): its platform's launch options.
            return array_map(fn ($l) => (int) $l['eur'], Platforms::launch(Platforms::of($quote->breakdown['scope'] ?? null)));
        }

        return match ($quote->kind) {
            // Sofabuilt's launch options (config/sofabuilt.php).
            'plugin' => array_map(fn ($l) => (int) $l['eur'], Platforms::launch(Platforms::of($quote->breakdown['scope'] ?? null))),
            // A website has no store and no developer account; the ads are what it can add.
            'site' => array_intersect_key(Estimator::PACKAGE_PRICES, ['marketingLaunch' => 1]),
            default => Estimator::PACKAGE_PRICES,
        };
    }

    /** A readable line name for the Stripe receipt. */
    public static function label(Quote $quote, string $key): string
    {
        if ($quote->kind === 'plugin' || self::fromDesk($quote)) {
            return (string) (Platforms::launch(Platforms::of($quote->breakdown['scope'] ?? null))[$key]['en'] ?? $key);
        }

        return ucfirst((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $key));
    }

    /** A quote frozen from a desk chat carries the agreed scope. */
    public static function fromDesk(Quote $quote): bool
    {
        return isset($quote->breakdown['desk_session_id'], $quote->breakdown['scope']);
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
