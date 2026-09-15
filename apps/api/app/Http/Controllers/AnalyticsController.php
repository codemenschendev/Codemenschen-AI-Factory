<?php

namespace App\Http\Controllers;

use App\Domain\Analytics\Analytics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AnalyticsController extends Controller
{
    /**
     * The portal's beacon. navigator.sendBeacon posts text/plain to avoid a CORS preflight, so the
     * JSON body is read by hand. Always 204: a visitor's browser gets nothing to act on either way.
     */
    public function store(Request $request, Analytics $analytics): Response
    {
        $data = json_decode((string) $request->getContent(), true);
        if (! is_array($data) || ! in_array($data['name'] ?? null, Analytics::CLIENT_EVENTS, true)) {
            return response()->noContent();
        }
        $props = array_filter(
            is_array($data['props'] ?? null) ? $data['props'] : [],
            fn ($v, $k) => is_string($k) && strlen($k) <= 30 && (is_scalar($v) || $v === null) && mb_strlen((string) $v) <= 120,
            ARRAY_FILTER_USE_BOTH,
        );

        $analytics->record($data['name'], $request, [
            'path' => is_string($data['path'] ?? null) ? $data['path'] : null,
            'locale' => $data['locale'] ?? null,
            'referrer' => is_string($data['referrer'] ?? null) ? $data['referrer'] : null,
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'click_id' => $data['click_id'] ?? null,
            'quote_id' => $data['quote_id'] ?? null,
        ], $props);

        return response()->noContent();
    }
}
