<?php

namespace App\Domain\Sofabuilt;

/**
 * What Sofabuilt builds (docs/specs/sofabuilt.md): WordPress plugins, Shopify apps and Chrome
 * extensions. WordPress's parts sit at the top of config/sofabuilt.php, the others under their own
 * key; the desk, the prices and the pipeline ask here which apply. `offered()` is what the desk
 * shows; a platform left out stays priced and buildable for chats already open.
 */
class Platforms
{
    public const ALL = ['wordpress', 'shopify', 'chrome'];

    /** The pipeline stack each platform is built on (worker templates/<stack>-app). */
    public const STACKS = ['wordpress' => 'wp-plugin', 'shopify' => 'shopify', 'chrome' => 'chrome-ext'];

    /** @return list<string> */
    public static function offered(): array
    {
        return array_values(array_intersect(self::ALL, config('sofabuilt.offered', self::ALL)));
    }

    public static function of(?array $scope): string
    {
        $p = $scope['platform'] ?? 'wordpress';

        return in_array($p, self::ALL, true) ? $p : 'wordpress';
    }

    /** @return array<string, array{eur: int, en: string, de: string}> */
    public static function modules(string $platform): array
    {
        return self::get($platform, 'modules', []);
    }

    /** @return array<string, int> */
    public static function repeatable(string $platform): array
    {
        return self::get($platform, 'repeatable', []);
    }

    /** @return array<string, array{eur: int, en: string, de: string}> */
    public static function launch(string $platform): array
    {
        return self::get($platform, 'launch', []);
    }

    /** @return list<array<string, mixed>> */
    public static function catalog(string $platform): array
    {
        return self::get($platform, 'catalog', []);
    }

    /** A catalogue price per year: "$15/mo" becomes "$180", a yearly price stays as it is. */
    public static function yearly(string $price): string
    {
        return preg_match('/^\$([\d.]+)\/mo$/', $price, $m) ? '$'.(int) round((float) $m[1] * 12) : $price;
    }

    private static function get(string $platform, string $key, array $default): array
    {
        return $platform === 'wordpress' ? config("sofabuilt.$key", $default) : config("sofabuilt.$platform.$key", $default);
    }
}
