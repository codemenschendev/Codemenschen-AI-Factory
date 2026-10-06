<?php

namespace App\Domain\Sofabuilt;

/**
 * The price of a desk scope, from config/sofabuilt.php: build minutes times the platform's hourly
 * rate (owner, 2026-10-05). The agent names modules and estimates their minutes for the idea; the
 * minutes are held to between half and three times the usual time of the part, and every euro is
 * computed here, so a model can never talk a price far up or down.
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
            $out[] = ['key' => $key, 'label' => $m[$lang], 'eur' => self::eur($platform, (int) $m['minutes']), 'minutes' => (int) $m['minutes'], 'max' => (int) ($repeatable[$key] ?? 1)];
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
            $minutes = (int) $known[$key]['minutes'];
            $sum += (int) round((self::eurRaw($platform, $minutes) + self::tokenEur(self::usualTokensK($minutes))) * Platforms::multiplier($platform)) * $n;
        }
        $pct = $care ? (int) config('sofabuilt.care_feature_discount_pct', 20) : 0;
        $eur = max(\App\Domain\Pricing\Estimator::REVISION_PRICE_EUR, (int) round($sum * (100 - $pct) / 100));

        return ['eur' => $eur, 'modules' => array_map(fn ($k, $n) => ['key' => $k, 'qty' => $n], array_keys($picked), $picked), 'discount_pct' => $pct];
    }

    /**
     * @param  array{modules?: list<array{key?: string, qty?: int}>}|null  $scope
     * @return array{lines: list<array{key: string, label: string, qty: int, minutes: int, eur: int}>, build_eur: int, too_big: bool, care_monthly_eur: int, launch: array<string, array{label: string, eur: int}>, delivery_days: array{0: int, 1: int}}
     */
    public static function quote(?array $scope, string $locale = 'en'): array
    {
        $platform = Platforms::of($scope);
        $modules = Platforms::modules($platform);
        $repeatable = Platforms::repeatable($platform);
        $lang = $locale === 'de' ? 'de' : 'en';
        $qty = ['base' => 1];
        $estimate = [];
        $tokens = [];
        foreach ((array) ($scope['modules'] ?? []) as $m) {
            $key = is_array($m) ? (string) ($m['key'] ?? '') : (string) $m;
            if (! isset($modules[$key])) {
                continue;
            }
            if (is_array($m) && isset($m['minutes'])) {
                $estimate[$key] = (int) $m['minutes'];
            }
            if (is_array($m) && isset($m['tokens_k'])) {
                $tokens[$key] = (int) $m['tokens_k'];
            }
            if ($key === 'base') {
                continue;
            }
            $n = max(1, (int) (is_array($m) ? ($m['qty'] ?? 1) : 1));
            $qty[$key] = min($repeatable[$key] ?? 1, max($qty[$key] ?? 0, $n));
        }
        $lines = [];
        foreach ($qty as $key => $n) {
            $usual = (int) $modules[$key]['minutes'];
            $minutes = self::minutes($usual, $estimate[$key] ?? null);
            $tokensK = self::tokensK(self::usualTokensK($usual), $tokens[$key] ?? null);
            // The platform's factor (apps: 10) applies to both, so the split still adds up.
            $mult = Platforms::multiplier($platform);
            $time = self::eurRaw($platform, $minutes) * $mult;
            $ai = self::tokenEur($tokensK) * $mult;
            $lines[] = ['key' => $key, 'label' => $modules[$key][$lang], 'qty' => $n, 'minutes' => $minutes * $n, 'tokens_k' => $tokensK * $n,
                'time_eur' => round($time * $n, 2), 'token_eur' => round($ai * $n, 2), 'eur' => (int) round($time + $ai) * $n];
        }
        $build = array_sum(array_column($lines, 'eur'));

        return [
            'lines' => $lines,
            'build_eur' => $build,
            'build_minutes' => array_sum(array_column($lines, 'minutes')),
            'build_tokens_k' => array_sum(array_column($lines, 'tokens_k')),
            'time_eur' => (int) round(array_sum(array_column($lines, 'time_eur'))),
            'token_eur' => round(array_sum(array_column($lines, 'token_eur')), 2),
            'token_model' => (string) config('sofabuilt.tokens.model'),
            'rate_eur_hour' => Platforms::rate($platform),
            'multiplier' => Platforms::multiplier($platform),
            'too_big' => $build > (int) config("sofabuilt.$platform.max_build_eur", Settings::get('max_build_eur')),
            'care_monthly_eur' => Platforms::care($platform),
            'care_trial_months' => (int) Settings::get('care_trial_months'),
            'launch' => array_map(fn ($l) => ['label' => $l[$lang], 'eur' => $l['eur']], Platforms::launch($platform)),
            'delivery_days' => Platforms::deliveryDays($platform),
            'hosting_monthly_eur' => Platforms::hosting($platform, array_keys($qty)),
        ];
    }

    /** The model's estimate for one unit of a part, held near the part's usual minutes. */
    public static function minutes(int $usual, ?int $estimate): int
    {
        if ($estimate === null || $estimate <= 0) {
            return $usual;
        }

        return max(max(1, intdiv($usual, 2)), min($usual * 3, $estimate));
    }

    /** Minutes into euros at the platform's rate and the admin's price lever (Settings price_pct). */
    public static function eur(string $platform, int $minutes): int
    {
        return (int) round(self::eurRaw($platform, $minutes));
    }

    public static function eurRaw(string $platform, int $minutes): float
    {
        return $minutes * Platforms::rate($platform) / 60 * (int) Settings::get('price_pct') / 100;
    }

    /** Thousands of tokens a part usually takes, from its usual minutes. */
    public static function usualTokensK(int $minutes): int
    {
        return (int) round($minutes * (float) config('sofabuilt.tokens.per_minute_k', 62.5));
    }

    /** The model's token estimate for one unit, held near the usual tokens. */
    public static function tokensK(int $usual, ?int $estimate): int
    {
        if ($estimate === null || $estimate <= 0) {
            return $usual;
        }

        return max(max(1, intdiv($usual, 2)), min($usual * 3, $estimate));
    }

    /** Thousands of tokens into euros: the model's list price, read and written, times token_pct. */
    public static function tokenEur(int $tokensK): float
    {
        $t = (array) config('sofabuilt.tokens');
        $out = $tokensK * (float) ($t['out_share'] ?? 0.04);
        $usd = (($tokensK - $out) * (float) ($t['usd_per_mtok']['in'] ?? 2) + $out * (float) ($t['usd_per_mtok']['out'] ?? 10)) / 1000;

        return $usd * (float) ($t['usd_eur'] ?? 0.92) * (int) Settings::get('token_pct') / 100;
    }
}
