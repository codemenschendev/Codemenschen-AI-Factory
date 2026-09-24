<?php

namespace App\Domain\Ads;

use App\Models\Setting;

/**
 * Appwerk's own ad identity, editable in the admin panel (2026-09-23).
 *
 * These are account numbers, not credentials: the manager account we send link requests from, the
 * Business id a customer pastes to give us partner access, the ad account and page our own
 * campaigns run on. They change when a business is restructured, and until now every change meant
 * an ssh session and a container recreate.
 *
 * Tokens and key files stay in the server env and are never editable from a browser. What is here
 * is exactly what we already print on a customer's screen anyway.
 */
class AdSettings
{
    /** field => the config value it falls back to when nobody set it. */
    public const FIELDS = [
        'meta_business_id' => 'services.ads.meta.business_id',
        'meta_ad_account_id' => 'services.ads.meta.ad_account_id',
        'meta_page_id' => 'services.ads.meta.page_id',
        'google_manager_id' => 'services.ads.google.manager_id',
        'google_customer_id' => 'services.ads.google.customer_id',
        // Where results are reported to (conversion tracking). Ids, not credentials.
        'meta_pixel_id' => 'services.ads.meta.pixel_id',
        'google_conversion_lead' => 'services.ads.google.conversion_lead',
        'google_conversion_purchase' => 'services.ads.google.conversion_purchase',
        'google_conversion_goal' => 'services.ads.google.conversion_goal',
        // The storefront's Tag Manager container (GTM-XXXXXXX). Public by nature: it is in the page.
        'gtm_id' => 'services.analytics.gtm_id',
    ];

    private const KEY = 'ads.appwerk';

    /** The value in use: what an admin set, else what the server env says. */
    public static function get(string $field): string
    {
        $stored = self::stored()[$field] ?? '';

        return $stored !== '' ? $stored : (string) config(self::FIELDS[$field] ?? '', '');
    }

    /**
     * Every field with its value and where that value comes from, so the panel can say "from the
     * server" rather than showing a number nobody remembers setting.
     *
     * @return array<string,array{value:string,source:string}>
     */
    public static function all(): array
    {
        $stored = self::stored();
        $out = [];
        foreach (self::FIELDS as $field => $config) {
            $set = $stored[$field] ?? '';
            $env = (string) config($config, '');
            $out[$field] = ['value' => $set !== '' ? $set : $env, 'source' => $set !== '' ? 'panel' : ($env === '' ? 'empty' : 'env')];
        }

        return $out;
    }

    /**
     * Writes the fields given. An empty string clears that field back to the server env, which is
     * how a wrong number is undone without knowing what the env holds.
     *
     * @param  array<string,string>  $values
     * @return array<string,array{value:string,source:string}>
     */
    public static function put(array $values, string $by): array
    {
        $now = self::stored();
        foreach ($values as $field => $value) {
            if (! array_key_exists($field, self::FIELDS)) {
                continue;
            }
            $clean = preg_replace('~[^A-Za-z0-9_\-]~', '', (string) $value) ?? '';
            if ($clean === '') {
                unset($now[$field]);
            } else {
                $now[$field] = $clean;
            }
        }
        Setting::write(self::KEY, $now, $by);

        return self::all();
    }

    /** @return array<string,string> */
    private static function stored(): array
    {
        $v = Setting::read(self::KEY, []);

        return is_array($v) ? array_map('strval', $v) : [];
    }
}
