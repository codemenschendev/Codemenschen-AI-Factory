<?php

namespace App\Domain\Sofabuilt;

use App\Models\Setting;

/**
 * Sofabuilt's own switches in the admin console (owner, 2026-10-05: "settings of its own for
 * Sofabuilt"). Same system as Appmitki, separate knobs: stored as `sofabuilt.<key>` settings,
 * defaults from config. Changing one never touches Appmitki.
 */
class Settings
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'desk_open' => true,
            'offered' => (array) config('sofabuilt.offered', Platforms::ALL),
            'price_pct' => 100,
            'max_build_eur' => (int) config('sofabuilt.max_build_eur'),
            'turns_per_day' => 2000,
            'care_trial_months' => (int) config('sofabuilt.care_trial_months', 3),
            'care_edits_per_month' => (int) config('console.care_edits_per_month', 3),
            // Hourly rate per platform: the price of a part is its build minutes times this.
            'rate_wordpress' => (int) config('sofabuilt.rate_eur_hour', 60),
            'rate_shopify' => (int) config('sofabuilt.shopify.rate_eur_hour', config('sofabuilt.rate_eur_hour', 60)),
            'rate_chrome' => (int) config('sofabuilt.chrome.rate_eur_hour', config('sofabuilt.rate_eur_hour', 60)),
            'rate_app' => (int) config('sofabuilt.app.rate_eur_hour', config('sofabuilt.rate_eur_hour', 60)),
            // The AI's token cost in the price: 100 is at cost, 0 leaves it out, 150 adds half.
            'token_pct' => 100,
        ];
    }

    /** What each key accepts (Laravel rules), for the admin endpoint. */
    public const RULES = [
        'desk_open' => 'boolean',
        'offered' => 'array|min:1',
        'offered.*' => 'string|in:wordpress,shopify,chrome',
        'price_pct' => 'integer|min:30|max:200',
        'max_build_eur' => 'integer|min:100|max:10000',
        'turns_per_day' => 'integer|min:0|max:20000',
        'care_trial_months' => 'integer|min:0|max:12',
        'care_edits_per_month' => 'integer|min:0|max:50',
        'rate_wordpress' => 'integer|min:10|max:1000',
        'rate_shopify' => 'integer|min:10|max:1000',
        'rate_chrome' => 'integer|min:10|max:1000',
        'rate_app' => 'integer|min:10|max:1000',
        'token_pct' => 'integer|min:0|max:500',
    ];

    public static function get(string $key): mixed
    {
        $default = self::defaults()[$key] ?? null;
        try {
            return Setting::read("sofabuilt.$key", $default);
        } catch (\Illuminate\Database\QueryException) {
            return $default;
        }
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return collect(self::defaults())->mapWithKeys(fn ($v, $k) => [$k => self::get($k)])->all();
    }

    /** @param array<string, mixed> $values */
    public static function write(array $values, string $by): void
    {
        foreach (array_intersect_key($values, self::defaults()) as $key => $value) {
            Setting::write("sofabuilt.$key", $key === 'offered' ? array_values(array_intersect(Platforms::ALL, $value)) : $value, $by);
        }
    }
}
