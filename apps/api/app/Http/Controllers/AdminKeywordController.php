<?php

namespace App\Http\Controllers;

use App\Domain\Ads\Keywords;
use App\Models\CampaignKeyword;
use App\Models\MarketingCampaign;
use App\Services\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The keyword screen in the admin panel (2026-09-23).
 *
 * Admin lane only, like everything under /admin: the middleware decides that on the server, not
 * the panel. Every route here writes to our own database except one, apply, which is the single
 * button that changes anything on Google. Suggesting costs one cheap AI call and changes nothing
 * a person has not ticked.
 */
class AdminKeywordController extends Controller
{
    /** The campaigns worth a keyword screen: Google search campaigns, newest first. */
    public function campaigns(): JsonResponse
    {
        $rows = MarketingCampaign::where('platform', 'google')
            ->with(['project:id,name', 'keywords'])
            ->latest()->limit(100)->get()
            ->map(fn (MarketingCampaign $c) => [
                'id' => $c->id,
                'name' => $c->project?->name ?? $c->strategy['name'] ?? ('Campaign #'.$c->id),
                'status' => $c->platform_status,
                'published' => $c->published_at !== null,
                'on_google' => ($c->platform_ref['ad_group'] ?? null) !== null,
                'keywords' => $c->keywords->where('negative', false)->count(),
                'negatives' => $c->keywords->where('negative', true)->count(),
                'waiting' => $c->keywords->where('status', 'proposed')->count(),
            ]);

        return response()->json(['campaigns' => $rows]);
    }

    public function index(MarketingCampaign $campaign): JsonResponse
    {
        return response()->json($this->state($campaign));
    }

    /** Asks Appwerk AI. Writes proposals, approves nothing. */
    public function suggest(MarketingCampaign $campaign, Keywords $keywords): JsonResponse
    {
        try {
            $made = $keywords->propose($campaign);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($this->state($campaign) + ['proposed' => $made['proposed'], 'skipped' => $made['skipped']]);
    }

    /** A word the AI did not think of. An admin typing it is approving it in the same breath. */
    public function store(Request $request, MarketingCampaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'text' => 'required|string|max:80',
            'match_type' => 'nullable|in:phrase,exact',
            'negative' => 'nullable|boolean',
        ]);
        $text = mb_strtolower(trim(preg_replace('~\s+~u', ' ', $data['text']) ?? ''));
        $negative = (bool) ($data['negative'] ?? false);

        if ($text === '') {
            return response()->json(['error' => 'Empty keyword.'], 422);
        }
        if (CampaignKeyword::where('campaign_id', $campaign->id)->where('text', $text)->where('negative', $negative)->exists()) {
            return response()->json(['error' => 'That keyword is already on this campaign.'], 422);
        }

        CampaignKeyword::create([
            'campaign_id' => $campaign->id, 'text' => $text,
            'match_type' => $data['match_type'] ?? 'phrase', 'negative' => $negative,
            'status' => 'approved', 'source' => 'admin',
        ]);

        return response()->json($this->state($campaign), 201);
    }

    /**
     * Keep it, change its match type, or take it out of service.
     *
     * A keyword that already reached Google is never deleted here. It is set to paused and the
     * next apply withdraws it on Google, which keeps what it cost readable in their reports.
     */
    public function update(Request $request, CampaignKeyword $keyword): JsonResponse
    {
        $data = $request->validate([
            'status' => 'nullable|in:proposed,approved,paused',
            'match_type' => 'nullable|in:phrase,exact',
        ]);
        $keyword->fill(array_filter($data, fn ($v) => $v !== null))->save();

        return response()->json($this->state($keyword->campaign));
    }

    /** Forgets a proposal. Refuses on anything Google already knows about. */
    public function destroy(CampaignKeyword $keyword): JsonResponse
    {
        if ($keyword->resource_name !== null) {
            return response()->json([
                'error' => 'This keyword is live on Google. Pause it instead, then press apply.',
            ], 422);
        }
        $campaign = $keyword->campaign;
        $keyword->delete();

        return response()->json($this->state($campaign));
    }

    /** The one button that changes something on the platform. */
    public function apply(MarketingCampaign $campaign, Keywords $keywords, Notify $notify, Request $request): JsonResponse
    {
        try {
            $done = $keywords->apply($campaign);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        if ($done['applied'] > 0 || $done['paused'] > 0) {
            $notify->system("keywords on campaign #{$campaign->id}: {$done['applied']} added, {$done['paused']} withdrawn, by ".$request->user()->email);
        }

        return response()->json($this->state($campaign) + $done);
    }

    /** @return array<string,mixed> */
    private function state(MarketingCampaign $campaign): array
    {
        $rows = $campaign->keywords()->orderBy('negative')->orderBy('text')->get()->map(fn (CampaignKeyword $k) => [
            'id' => $k->id,
            'text' => $k->text,
            'match_type' => $k->match_type,
            'negative' => $k->negative,
            'status' => $k->status,
            'source' => $k->source,
            'live' => $k->resource_name !== null,
            'error' => $k->error,
        ]);

        return [
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->project?->name ?? $campaign->strategy['name'] ?? ('Campaign #'.$campaign->id),
                'status' => $campaign->platform_status,
                'on_google' => ($campaign->platform_ref['ad_group'] ?? null) !== null,
            ],
            'keywords' => $rows->values(),
        ];
    }
}
