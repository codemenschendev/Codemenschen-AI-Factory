<?php

namespace App\Domain\Sofabuilt;

/**
 * The price of a desk scope, from config/sofabuilt.php. The agent names modules; every euro comes
 * from here, so a model can never talk a price up or down.
 */
class Pricing
{
    /**
     * @param  array{modules?: list<array{key?: string, qty?: int}>}|null  $scope
     * @return array{lines: list<array{key: string, label: string, qty: int, eur: int}>, build_eur: int, too_big: bool, care_monthly_eur: int, launch: array<string, array{label: string, eur: int}>, delivery_days: array{0: int, 1: int}}
     */
    public static function quote(?array $scope, string $locale = 'en'): array
    {
        $modules = config('sofabuilt.modules');
        $repeatable = config('sofabuilt.repeatable', []);
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
            $lines[] = ['key' => $key, 'label' => $modules[$key][$lang], 'qty' => $n, 'eur' => $modules[$key]['eur'] * $n];
        }
        $build = array_sum(array_column($lines, 'eur'));

        return [
            'lines' => $lines,
            'build_eur' => $build,
            'too_big' => $build > (int) config('sofabuilt.max_build_eur'),
            'care_monthly_eur' => (int) config('sofabuilt.care_monthly_eur'),
            'launch' => array_map(fn ($l) => ['label' => $l[$lang], 'eur' => $l['eur']], config('sofabuilt.launch')),
            'delivery_days' => config('sofabuilt.delivery_days'),
        ];
    }
}
