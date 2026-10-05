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

    /** Appmitki's phone apps on the same desk; never one of Sofabuilt's platforms (config `app`). */
    public const APP = 'app';

    /** The pipeline stack each platform is built on (worker templates/<stack>-app). */
    public const STACKS = ['wordpress' => 'wp-plugin', 'shopify' => 'shopify', 'chrome' => 'chrome-ext', 'app' => 'expo'];

    /** @return list<string> */
    public static function offered(): array
    {
        $offered = array_values(array_intersect(self::ALL, (array) Settings::get('offered')));

        return $offered === [] ? ['wordpress'] : $offered;
    }

    public static function of(?array $scope): string
    {
        $p = $scope['platform'] ?? 'wordpress';

        return in_array($p, [...self::ALL, self::APP], true) ? $p : 'wordpress';
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

    /** Care per month: WordPress's at the top of the config, a platform may set its own (Shopify includes hosting). */
    public static function care(string $platform): int
    {
        return (int) ($platform === 'wordpress' ? config('sofabuilt.care_monthly_eur') : config("sofabuilt.$platform.care_monthly_eur", config('sofabuilt.care_monthly_eur')));
    }

    /** Monthly server fee: only an app whose parts need our server (`server` in the config). */
    public static function hosting(string $platform, array $keys): int
    {
        $modules = self::modules($platform);
        $server = array_filter($keys, fn ($k) => ! empty($modules[$k]['server']));

        return $server === [] ? 0 : (int) config("sofabuilt.$platform.hosting_monthly_eur", 0);
    }

    /** @return array{0: int, 1: int} */
    public static function deliveryDays(string $platform): array
    {
        return config("sofabuilt.$platform.delivery_days", config('sofabuilt.delivery_days'));
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
