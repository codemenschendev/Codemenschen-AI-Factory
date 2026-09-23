<?php

namespace App\Http\Controllers;

use App\Domain\Ads\AdSettings;
use Illuminate\Http\JsonResponse;

/**
 * What the storefront needs to know at run time and may show anybody: today only the Tag Manager
 * container, which the consent banner loads after a yes. Set in the console, so changing it needs
 * no rebuild.
 */
class SiteConfigController extends Controller
{
    public function show(): JsonResponse
    {
        $gtm = AdSettings::get('gtm_id');

        return response()->json(['gtm_id' => preg_match('~^GTM-[A-Z0-9]{4,12}$~', $gtm) === 1 ? $gtm : null])
            ->header('Cache-Control', 'public, max-age=60');
    }
}
