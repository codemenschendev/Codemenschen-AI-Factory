<?php

namespace App\Http\Controllers;

use App\Domain\Ads\GoogleAdsPublisher;
use App\Domain\Ads\Preflight;
use App\Domain\Ads\PublisherRegistry;
use App\Domain\Ads\SpendGuard;
use App\Domain\Ai\SearchAdWriter;
use App\Domain\Analytics\CampaignFunnel;
use App\Jobs\PublishCampaign;
use App\Models\MarketingCampaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Appwerk advertising itself (2026-09-23): the Google search campaigns that run on our own
 * account and are paid by us, apart from every customer screen.
 *
 * A campaign is written here, its keywords are chosen on the keyword screen, and it goes to Google
 * paused. Starting it is the same button, behind the same spend guard, as every other campaign.
 * Once it is on Google its text is fixed here: a change would have to be a new ad on Google, and a
 * form that looks editable but changes nothing is worse than a form that is read-only.
 */
class AdminOwnCampaignController extends Controller
{
    /** Before publishing, and after a publish that failed. */
    private const EDITABLE = ['draft', 'unpublished', 'failed'];

    public function index(SpendGuard $guard, PublisherRegistry $registry): JsonResponse
    {
        $rows = MarketingCampaign::own()->where('platform', 'google')
            ->with(['creatives', 'keywords'])->latest()->get();

        return response()->json([
            'campaigns' => $rows->map(fn (MarketingCampaign $c) => self::row($c))->values(),
            'limits' => $guard->limits(),
            'google' => $registry->for('google')->isConfigured(),
            'countries' => array_keys(GoogleAdsPublisher::COUNTRIES),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $campaign = DB::transaction(function () use ($data) {
            $campaign = MarketingCampaign::create(['platform' => 'google', 'status' => 'approved', 'platform_status' => 'draft'] + $this->columns($data));
            $this->copy($campaign, $data);

            return $campaign;
        });

        return response()->json(self::row($campaign->fresh(['creatives', 'keywords'])), 201);
    }

    public function update(Request $request, MarketingCampaign $campaign): JsonResponse
    {
        $this->own($campaign);
        abort_unless(in_array($campaign->platform_status, self::EDITABLE, true), 409, 'This campaign is on Google already. Its text can no longer change here.');
        $data = $this->validated($request);
        DB::transaction(function () use ($campaign, $data) {
            $campaign->update($this->columns($data) + ['publish_error' => null]);
            $this->copy($campaign, $data);
        });

        return response()->json(self::row($campaign->fresh(['creatives', 'keywords'])));
    }

    public function destroy(MarketingCampaign $campaign): JsonResponse
    {
        $this->own($campaign);
        abort_unless($campaign->published_at === null && in_array($campaign->platform_status, self::EDITABLE, true), 409,
            'A campaign that reached Google stays, so what it spent can still be read.');
        DB::transaction(function () use ($campaign) {
            $campaign->creatives()->delete();
            $campaign->delete();
        });

        return response()->json(['deleted' => true]);
    }

    /** A draft of the ad text from Appwerk AI. Stores nothing: the form shows it, a person saves it. */
    public function write(Request $request, SearchAdWriter $writer): JsonResponse
    {
        $data = $request->validate([
            'message' => 'required|string|max:600',
            'audience' => 'nullable|string|max:300',
            'offer' => 'nullable|string|max:300',
            'landing_url' => 'nullable|string|max:500',
            'language' => 'nullable|in:de,en',
        ]);
        try {
            return response()->json($writer->write($data, $data['language'] ?? 'de'));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /** To Google, paused. Spends nothing; the start button is a separate decision. */
    public function publish(MarketingCampaign $campaign, Preflight $preflight, PublisherRegistry $registry): JsonResponse
    {
        $this->own($campaign);
        abort_unless(in_array($campaign->platform_status, self::EDITABLE, true), 409, 'This campaign is already on its way to Google or on it.');
        abort_unless($registry->for('google')->isConfigured(), 422, 'Google Ads is not configured on the server.');

        if (($problems = $preflight->check($campaign->load('creatives'))) !== []) {
            return response()->json(['error' => implode(' ', $problems), 'problems' => $problems], 422);
        }
        $campaign->update(['platform_status' => 'publishing', 'publish_error' => null]);
        PublishCampaign::dispatch($campaign->id);

        return response()->json(self::row($campaign->fresh(['creatives', 'keywords'])), 202);
    }

    private function own(MarketingCampaign $campaign): void
    {
        abort_unless($campaign->project_id === null && $campaign->prototype_id === null && $campaign->platform === 'google', 404);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        $max = app(SpendGuard::class)->limits()['max_campaign_eur'];

        return $request->validate([
            'name' => 'required|string|max:60',
            'language' => 'required|in:de,en',
            'countries' => 'required|array|min:1',
            'countries.*' => 'string|in:'.implode(',', array_keys(GoogleAdsPublisher::COUNTRIES)),
            'landing_url' => 'required|url:https|max:500',
            'message' => 'required|string|max:600',
            'audience' => 'nullable|string|max:300',
            'offer' => 'nullable|string|max:300',
            'daily_eur' => 'required|integer|min:1|max:500',
            'spend_cap_eur' => "required|integer|min:10|max:$max",
            'ends_at' => 'nullable|date|after:today',
            'headlines' => 'array|max:15',
            'headlines.*' => 'string|max:'.SearchAdWriter::HEADLINE_MAX,
            'descriptions' => 'array|max:4',
            'descriptions.*' => 'string|max:'.SearchAdWriter::DESCRIPTION_MAX,
        ]);
    }

    /** @return array<string,mixed> */
    private function columns(array $data): array
    {
        return [
            'strategy' => [
                'kind' => 'own',
                'name' => $data['name'],
                'language' => $data['language'],
                'countries' => array_values(array_unique($data['countries'])),
                'landing_url' => $data['landing_url'],
                'message' => $data['message'],
                'audience' => $data['audience'] ?? null,
                'offer' => $data['offer'] ?? null,
                'daily_eur' => (int) $data['daily_eur'],
            ],
            // Kept for the screens that read a monthly figure; the day amount above is what Google gets.
            'ad_budget_monthly_eur' => (int) $data['daily_eur'] * 30,
            'spend_cap_eur' => (int) $data['spend_cap_eur'],
            'ends_at' => isset($data['ends_at']) ? Carbon::parse($data['ends_at'])->endOfDay() : null,
        ];
    }

    /** The ad text is replaced as a whole: what the form shows is what is stored. */
    private function copy(MarketingCampaign $campaign, array $data): void
    {
        $campaign->creatives()->whereIn('kind', ['headline', 'ad_copy'])->delete();
        $rows = [];
        foreach (['headline' => $data['headlines'] ?? [], 'ad_copy' => $data['descriptions'] ?? []] as $kind => $lines) {
            foreach ($lines as $line) {
                if (($line = trim((string) $line)) !== '') {
                    $rows[] = ['kind' => $kind, 'content' => $line, 'locale' => $data['language']];
                }
            }
        }
        $campaign->creatives()->createMany($rows);
    }

    /** @return array<string,mixed> */
    private static function row(MarketingCampaign $c): array
    {
        $s = $c->strategy ?? [];
        $keywords = $c->keywords;

        return [
            'id' => $c->id,
            'name' => $s['name'] ?? 'Campaign #'.$c->id,
            'status' => $c->platform_status,
            'editable' => in_array($c->platform_status, self::EDITABLE, true),
            'language' => $s['language'] ?? 'de',
            'countries' => $s['countries'] ?? [],
            'landing_url' => $s['landing_url'] ?? '',
            'message' => $s['message'] ?? '',
            'audience' => $s['audience'] ?? '',
            'offer' => $s['offer'] ?? '',
            'daily_eur' => $c->dailyEur(),
            'spend_cap_eur' => $c->spend_cap_eur,
            'ends_at' => $c->ends_at?->toDateString(),
            'headlines' => $c->creatives->where('kind', 'headline')->pluck('content')->values(),
            'descriptions' => $c->creatives->where('kind', 'ad_copy')->pluck('content')->values(),
            'keywords' => [
                'approved' => $keywords->where('negative', false)->whereIn('status', ['approved', 'applied'])->count(),
                'waiting' => $keywords->where('status', 'proposed')->count(),
                'negatives' => $keywords->where('negative', true)->whereIn('status', ['approved', 'applied'])->count(),
            ],
            'spent_eur' => (float) $c->spent_eur,
            'spent_today_eur' => (float) $c->spent_today_eur,
            'impressions' => (int) $c->impressions,
            'clicks' => (int) $c->link_clicks,
            'published_at' => $c->published_at?->toIso8601String(),
            'activated_at' => $c->activated_at?->toIso8601String(),
            'error' => $c->publish_error,
            'stopped_reason' => $c->stopped_reason,
            // The address a click lands on, UTM included, and what those clicks became.
            // What Google has, once it has it: a campaign sent before UTM existed has none.
            'final_url' => $c->platform_ref['final_url'] ?? ($c->published_at === null ? $c->finalUrl() : ($c->strategy['landing_url'] ?? '')),
            'funnel' => app(CampaignFunnel::class)->for($c),
        ];
    }
}
