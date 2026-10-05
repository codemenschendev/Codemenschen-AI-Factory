<?php

namespace App\Domain\Sofabuilt;

/**
 * The price of a desk scope, from config/sofabuilt.php. The agent names modules; every euro comes
 * from here, so a model can never talk a price up or down.
 */
class Pricing
{
    /**
     * Every part the customer can add or remove on the desk, with its price.
     *
     * @return list<array{key: string, label: string, eur: int, max: int}>
     */
    public static function options(string $locale = 'en', string $platform = 'wordpress'): array
    {
        $lang = $locale === 'de' ? 'de' : 'en';
        $repeatable = Platforms::repeatable($platform);
        $out = [];
        foreach (Platforms::modules($platform) as $key => $m) {
            $out[] = ['key' => $key, 'label' => $m[$lang], 'eur' => (int) $m['eur'], 'max' => (int) ($repeatable[$key] ?? 1)];
        }

        return $out;
    }

    /**
     * A new feature for a plugin that is already built (the console's change chat): the parts it
     * needs from the platform's price list, without the base, at the admin's price level, less the
     * Care discount while Care is active. Never below the price of a single change round.
     *
     * @param  list<array{key: string, qty?: int}>  $modules
     * @return array{eur: int, modules: list<array{key: string, qty: int}>, discount_pct: int}
     */
    public static function feature(string $platform, array $modules, bool $care): array
    {
        $known = Platforms::modules($platform);
        $repeatable = Platforms::repeatable($platform);
        $picked = [];
        foreach ($modules as $m) {
            $key = (string) ($m['key'] ?? '');
            if ($key === 'base' || ! isset($known[$key])) {
                continue;
            }
            $picked[$key] = min($repeatable[$key] ?? 1, max($picked[$key] ?? 0, (int) ($m['qty'] ?? 1)));
        }
        $sum = 0;
        foreach ($picked as $key => $n) {
            $sum += (int) round($known[$key]['eur'] * (int) Settings::get('price_pct') / 100) * $n;
        }
        $pct = $care ? (int) config('sofabuilt.care_feature_discount_pct', 20) : 0;
        $eur = max(\App\Domain\Pricing\Estimator::REVISION_PRICE_EUR, (int) round($sum * (100 - $pct) / 100));

        return ['eur' => $eur, 'modules' => array_map(fn ($k, $n) => ['key' => $k, 'qty' => $n], array_keys($picked), $picked), 'discount_pct' => $pct];
    }

    /**
     * @param  array{modules?: list<array{key?: string, qty?: int}>}|null  $scope
     * @return array{lines: list<array{key: string, label: string, qty: int, eur: int}>, build_eur: int, too_big: bool, care_monthly_eur: int, launch: array<string, array{label: string, eur: int}>, delivery_days: array{0: int, 1: int}}
     */
    public static function quote(?array $scope, string $locale = 'en'): array
    {
        $platform = Platforms::of($scope);
        $modules = Platforms::modules($platform);
        $repeatable = Platforms::repeatable($platform);
        $lang = $locale === 'de' ? 'de' : 'en';
        $qty = ['base' => 1];
        foreach ((array) ($scope['modules'] ?? []) as $m) {
            $key = is_array($m) ? (string) ($m['key'] ?? '') : (string) $m;
            if (! isset($modules[$key]) || $key === 'base') {
                continue;
            }
            $n = max(1, (int) (is_array($m) ? ($m['qty'] ?? 1) : 1));
            $qty[$key] = min($repeatable[$key] ?? 1, max($qty[$key] ?? 0, $n));
        }
        $lines = [];
        foreach ($qty as $key => $n) {
            // The admin's price lever (Settings price_pct) scales every part, rounded to whole euros.
            $lines[] = ['key' => $key, 'label' => $modules[$key][$lang], 'qty' => $n, 'eur' => (int) round($modules[$key]['eur'] * (int) Settings::get('price_pct') / 100) * $n];
        }
        $build = array_sum(array_column($lines, 'eur'));

        return [
            'lines' => $lines,
            'build_eur' => $build,
            'too_big' => $build > (int) Settings::get('max_build_eur'),
            'care_monthly_eur' => Platforms::care($platform),
            'care_trial_months' => (int) Settings::get('care_trial_months'),
            'launch' => array_map(fn ($l) => ['label' => $l[$lang], 'eur' => $l['eur']], Platforms::launch($platform)),
            'delivery_days' => Platforms::deliveryDays($platform),
            'hosting_monthly_eur' => Platforms::hosting($platform, array_keys($qty)),
        ];
    }
}
