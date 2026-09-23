<?php

namespace App\Http\Controllers;

use App\Domain\Ads\Conversions;
use App\Jobs\SendAdConversion;
use App\Models\AdConversion;
use Illuminate\Http\JsonResponse;

/**
 * The conversion tracking screen (2026-09-23): whether Meta and Google are set up to take results,
 * what was reported, and what was refused and why. The matching data never leaves the server.
 */
class AdminConversionController extends Controller
{
    public function index(Conversions $conversions): JsonResponse
    {
        $counts = AdConversion::selectRaw('platform, event, status, COUNT(*) as n, COALESCE(SUM(value_eur), 0) as value')
            ->groupBy('platform', 'event', 'status')->get()
            ->map(fn ($r) => ['platform' => $r->platform, 'event' => $r->event, 'status' => $r->status, 'count' => (int) $r->n, 'value_eur' => (float) $r->value]);

        return response()->json([
            'setup' => $conversions->setup(),
            'counts' => $counts,
            'rows' => AdConversion::latest('id')->limit(100)->get()->map(fn (AdConversion $c) => [
                'id' => $c->id,
                'platform' => $c->platform,
                'event' => $c->event,
                'status' => $c->status,
                'value_eur' => $c->value_eur,
                'quote_id' => $c->quote_id,
                'prototype_id' => $c->prototype_id,
                'happened_at' => $c->happened_at?->toIso8601String(),
                'sent_at' => $c->sent_at?->toIso8601String(),
                'attempts' => $c->attempts,
                'error' => $c->error,
            ]),
        ]);
    }

    /** Failed rows get a fresh set of tries, for after a setup mistake was fixed. */
    public function retry(): JsonResponse
    {
        $ids = AdConversion::whereIn('status', ['failed', 'pending'])->pluck('id');
        AdConversion::whereIn('id', $ids)->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0]);
        foreach ($ids as $id) {
            SendAdConversion::dispatch($id);
        }

        return response()->json(['queued' => $ids->count()]);
    }
}
