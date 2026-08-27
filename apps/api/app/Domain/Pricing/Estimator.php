<?php

namespace App\Domain\Pricing;

/**
 * Authoritative pricing engine — PHP mirror of packages/pricing (TypeScript).
 * The web wizard uses the TS copy for live feedback; every quote and checkout
 * total is recomputed HERE. Keep the two in sync (both carry this notice).
 */
class Estimator
{
    /**
     * Feature catalog: cost in EUR + whether it forces a hosted backend.
     * Price sheet lowered 2026-08-27 (Patrick): the factory competes with
     * AI-builder subscriptions, so an add-on is priced like a few hours, not
     * a sprint. Base prices per platform live in estimate().
     */
    public const FEATURES = [
        'auth' => ['cost' => 40, 'needsBackend' => true],
        'pay' => ['cost' => 60, 'needsBackend' => true],
        'dash' => ['cost' => 40, 'needsBackend' => false],
        'ai' => ['cost' => 80, 'needsBackend' => true],
        'notif' => ['cost' => 30, 'needsBackend' => true],
        'api' => ['cost' => 40, 'needsBackend' => true],
        'offline' => ['cost' => 30, 'needsBackend' => false],
        'i18n' => ['cost' => 20, 'needsBackend' => false],
    ];

    public const PACKAGE_PRICES = [
        'storePublishing' => 79,
        'transferAssist' => 49,
        'marketingLaunch' => 129,
    ];

    /** One paid change-request round (after the free REVIEW rounds / once released). Patrick's call. */
    public const REVISION_PRICE_EUR = 39;

    /** Development price clamp (EUR). */
    public const PRICE_MIN = 149;

    public const PRICE_MAX = 1500;

    /** Monthly hosting & maintenance per app type. Bands: Patrick's call. */
    public const HOSTING_MONTHLY = ['A' => 0, 'B' => 19];

    public const AD_BUDGET_OPTIONS = [0, 300, 500, 1000, 2000];

    private static function rnd(float $n, int $s): int
    {
        return (int) (round($n / $s) * $s);
    }

    /** @param list<string> $features */
    public static function appType(array $features): string
    {
        foreach ($features as $f) {
            if (self::FEATURES[$f]['needsBackend'] ?? false) {
                return 'B';
            }
        }

        return 'A';
    }

    /**
     * @param  'consumer'|'b2b'|'both'  $audience
     * @param  'web'|'mobile'|'both'  $platform
     * @param  list<string>  $features
     * @return array{devLo:int,devHi:int,marketingLo:int,marketingHi:int,price:int,weeksLo:int,weeksHi:int,appType:string,hostingMonthly:int}
     */
    public static function estimate(string $audience, string $platform, array $features): array
    {
        $base = $platform === 'mobile' ? 300 : ($platform === 'both' ? 450 : 200);
        $dev = $base;
        foreach ($features as $f) {
            $dev += self::FEATURES[$f]['cost'];
        }
        if ($audience === 'b2b') {
            $dev *= 1.15;
        }
        if ($audience === 'both') {
            $dev *= 1.1;
        }

        // Suggested launch ad budget — informational, never part of the price.
        $marketingLo = $audience === 'consumer' ? 200 : 300;
        $marketingHi = $audience === 'consumer' ? 500 : 800;
        $price = min(self::PRICE_MAX, max(self::PRICE_MIN, self::rnd($dev * 1.2, 50)));
        $nf = count($features);
        $appType = self::appType($features);

        return [
            'devLo' => self::rnd($dev * 0.85, 50),
            'devHi' => self::rnd($dev * 1.3, 50),
            'marketingLo' => $marketingLo,
            'marketingHi' => $marketingHi,
            'price' => $price,
            'weeksLo' => 3 + $nf,
            'weeksHi' => 6 + $nf + ($platform === 'both' ? 2 : 0),
            'appType' => $appType,
            'hostingMonthly' => self::HOSTING_MONTHLY[$appType],
        ];
    }

    /**
     * @param  array{storePublishing?:bool,transferAssist?:bool,marketingLaunch?:bool}  $packages
     */
    public static function oneTimeTotal(int $price, array $packages): int
    {
        $total = $price;
        foreach (self::PACKAGE_PRICES as $key => $fee) {
            if (! empty($packages[$key])) {
                $total += $fee;
            }
        }

        return $total;
    }
}
