<?php

namespace App\Http\Controllers;

use App\Domain\Ads\AdSettings;
use App\Domain\Ai\PrototypeWriter;
use Illuminate\Http\JsonResponse;

/**
 * What the storefront needs to know at run time and may show anybody: the Tag Manager container
 * and the Meta Pixel, which the consent banner loads after a yes, and which kinds of prototype are offered. Set in the console, so changing it needs
 * no rebuild.
 */
class SiteConfigController extends Controller
{
    public function show(): JsonResponse
    {
        $gtm = AdSettings::get('gtm_id');

        return response()->json([
            'gtm_id' => preg_match('~^GTM-[A-Z0-9]{4,12}$~', $gtm) === 1 ? $gtm : null,
            'kinds' => PrototypeWriter::offered(),
            // The Meta Pixel the banner loads after a yes to ad measurement (lib/metaPixel.ts).
            'meta_pixel_id' => preg_match('~^\d{6,20}$~', (string) config('services.ads.meta.pixel_id')) === 1 ? (string) config('services.ads.meta.pixel_id') : null,
        ])
            ->header('Cache-Control', 'public, max-age=60');
    }
}
