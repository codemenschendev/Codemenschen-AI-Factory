<?php

namespace App\Http\Controllers;

use App\Domain\Ads\Conversions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The server copy of a funnel step the browser also gave the Meta pixel (lib/metaPixel.ts metaEvent).
 * The browser only calls this after a yes to ad measurement and says so; without that, nothing happens.
 */
class MetaEventController extends Controller
{
    public function store(Request $request, Conversions $conversions): JsonResponse
    {
        $data = $request->validate([
            'consent' => 'required|accepted',
            'event' => 'required|string|in:'.implode(',', Conversions::FUNNEL),
            'event_id' => 'required|string|regex:/^[A-Za-z0-9\-]{8,80}$/',
            'url' => 'nullable|string|max:500',
            'fbp' => 'nullable|string|max:120',
            'fbc' => 'nullable|string|max:600',
            'fbclid' => 'nullable|string|max:500',
            'clicked_at' => 'nullable|integer',
            'value' => 'nullable|numeric',
        ]);
        $row = $conversions->funnel($data['event'], $data['event_id'], $request, $data);

        return response()->json(['queued' => $row !== null], 202);
    }
}
