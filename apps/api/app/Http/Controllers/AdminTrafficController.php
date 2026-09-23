<?php

namespace App\Http\Controllers;

use App\Domain\Ads\GoogleTraffic;
use App\Models\MarketingCampaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A campaign's traffic as Google reports it, for the console (2026-09-23). Any published Google
 * campaign, ours or a customer's. Reads only; nothing here changes a campaign.
 */
class AdminTrafficController extends Controller
{
    public function show(Request $request, MarketingCampaign $campaign, GoogleTraffic $traffic): JsonResponse
    {
        $data = $request->validate(['days' => 'nullable|integer|in:'.implode(',', GoogleTraffic::PERIODS), 'fresh' => 'nullable|boolean']);
        if ($campaign->platform !== 'google') {
            return response()->json(['error' => 'Traffic reports are for Google campaigns so far.'], 422);
        }
        if (($campaign->platform_ref['campaign_id'] ?? null) === null) {
            return response()->json(['error' => 'This campaign is not on Google yet, so there is no traffic to read.'], 422);
        }

        try {
            return response()->json($traffic->for($campaign, (int) ($data['days'] ?? 30), (bool) ($data['fresh'] ?? false)));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
