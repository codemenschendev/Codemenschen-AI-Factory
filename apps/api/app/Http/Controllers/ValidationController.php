<?php

namespace App\Http\Controllers;

use App\Domain\Ads\Preflight;
use App\Domain\Ads\PublisherRegistry;
use App\Domain\Ads\SpendGuard;
use App\Jobs\PublishCampaign;
use App\Models\MarketingCampaign;
use App\Models\Prototype;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The validation test: a campaign prototype's ad, run on Meta for a few days with a fixed total,
 * pointing at its live landing page (step 3 of the funnel, 2026-09-19).
 *
 * Operators only for now: nothing here is paid for by the customer yet, so a person at
 * Codemenschen decides every euro. It is published paused, started by a button, watched by the
 * spend guard, and stopped by the kill switch like every other campaign.
 */
class ValidationController extends Controller
{
    /** What the panel shows: the defaults for a new test, the tests so far, the guard's limits. */
    public function show(Prototype $prototype, PublisherRegistry $registry, SpendGuard $guard): JsonResponse
    {
        abort_unless($prototype->kind === 'campaign', 422, 'Only a campaign can be tested.');
        [$site, $ad] = $this->parts($prototype);

        return response()->json([
            'ready' => $site?->published_at !== null && $ad?->status === 'ready',
            'landing_url' => $site?->published_at !== null ? LandingController::url($site) : null,
            'defaults' => [
                'headline' => mb_substr($site ? LandingController::name($site) : (string) $prototype->title, 0, 40),
                'text' => self::primaryText($site),
                'budget_eur' => 150, 'days' => 7, 'countries' => ['AT', 'DE'],
            ],
            'meta' => ['configured' => $registry->for('meta')->isConfigured()],
            'limits' => $guard->limits(),
            'tests' => MarketingCampaign::where('prototype_id', $prototype->id)->latest()->get()->map(fn (MarketingCampaign $c) => self::row($c)),
        ]);
    }

    /** Prepares the test on Meta, paused. Spends nothing. */
    public function store(Request $request, Prototype $prototype, SpendGuard $guard, Preflight $preflight): JsonResponse
    {
        $max = $guard->limits()['max_campaign_eur'];
        $data = $request->validate([
            'budget_eur' => "required|integer|min:20|max:$max",
            'days' => 'required|integer|min:3|max:14',
            'countries' => 'required|array|min:1|max:5',
            'countries.*' => 'string|in:AT,DE,CH,IT,NL,BE,LU,FR,ES,GB,US',
            'headline' => 'required|string|max:40',
            'text' => 'required|string|max:500',
        ]);
        abort_unless($prototype->kind === 'campaign', 422, 'Only a campaign can be tested.');
        [$site, $ad] = $this->parts($prototype);
        abort_unless($site?->published_at !== null && $site->isLive(), 422, 'Put the landing page live first.');
        $picture = $ad?->status === 'ready' ? self::adPicture((string) $ad->html) : null;
        abort_if($picture === null, 422, 'The ad has no picture to run.');
        abort_if(MarketingCampaign::where('prototype_id', $prototype->id)->whereIn('platform_status', ['publishing', 'paused', 'active'])->exists(),
            409, 'This campaign already has a test. Stop it before preparing another.');

        $campaign = MarketingCampaign::create([
            'prototype_id' => $prototype->id,
            'platform' => 'meta',
            'status' => 'approved',
            'strategy' => ['kind' => 'validation', 'countries' => array_values(array_unique($data['countries'])), 'days' => $data['days'],
                'landing_url' => LandingController::url($site).'?utm_source=meta&utm_medium=paid&utm_campaign=validation-'.substr($prototype->id, 0, 8)],
            'spend_cap_eur' => $data['budget_eur'],
            'ends_at' => now()->addDays($data['days']),
            'platform_status' => 'publishing',
        ]);
        $dir = rtrim((string) config('services.media.uploads_path'), '/').'/validation';
        @mkdir($dir, 0775, true);
        $path = "$dir/{$campaign->id}.{$picture['ext']}";
        file_put_contents($path, $picture['bytes']);
        $campaign->update(['creative_path' => $path]);
        $campaign->creatives()->createMany([
            ['kind' => 'headline', 'content' => $data['headline']],
            ['kind' => 'ad_copy', 'content' => $data['text']],
        ]);

        if (($problems = $preflight->check($campaign->fresh(['creatives']))) !== []) {
            $campaign->update(['platform_status' => 'failed', 'publish_error' => implode(' · ', $problems)]);

            return response()->json(['problems' => $problems], 422);
        }
        PublishCampaign::dispatch($campaign->id);

        return response()->json(self::row($campaign->fresh()), 202);
    }

    /** The spend gate for a test: a person, and the guard's word. */
    public function activate(Request $request, MarketingCampaign $campaign, PublisherRegistry $registry, SpendGuard $guard): JsonResponse
    {
        abort_unless($campaign->platform_status === 'paused', 409, 'Only a published, paused campaign can be started.');
        if (($blockers = $guard->blockers($campaign)) !== []) {
            return response()->json(['error' => $blockers[0], 'blockers' => $blockers], 422);
        }
        $registry->for($campaign->platform)->activate($campaign);
        $campaign->update(['platform_status' => 'active', 'activated_at' => now(), 'stopped_reason' => null]);
        app(\App\Services\Notify::class)->money("Ad #{$campaign->id} started", sprintf('%s started ad campaign #%d (%s): up to %s EUR, until %s.',
            $request->user()->email, $campaign->id, $campaign->platform, $campaign->spend_cap_eur ?? '?', $campaign->ends_at?->toDateString() ?? '?'));

        return response()->json(self::row($campaign->fresh()));
    }

    public function pause(Request $request, MarketingCampaign $campaign, SpendGuard $guard): JsonResponse
    {
        abort_unless($campaign->platform_status === 'active', 409, 'The campaign is not running.');
        $guard->stop($campaign, 'operator:'.$request->user()->email);

        return response()->json(self::row($campaign->fresh()));
    }

    /** The kill switch, on or off. */
    public function kill(Request $request, SpendGuard $guard): JsonResponse
    {
        $data = $request->validate(['on' => 'required|boolean']);
        $stopped = $guard->kill((bool) $data['on'], $request->user()->email);

        return response()->json(['limits' => $guard->limits(), 'stopped' => $stopped]);
    }

    /** The two limits of the guard's second wall. */
    public function limits(Request $request, SpendGuard $guard): JsonResponse
    {
        $data = $request->validate(['max_campaign_eur' => 'required|integer|min:20|max:10000',
            'max_daily_total_eur' => 'required|integer|min:5|max:5000']);
        \App\Models\Setting::write(SpendGuard::MAX_CAMPAIGN, $data['max_campaign_eur'], $request->user()->email);
        \App\Models\Setting::write(SpendGuard::MAX_DAILY_TOTAL, $data['max_daily_total_eur'], $request->user()->email);

        return response()->json(['limits' => $guard->limits()]);
    }

    /** @return array{0:?Prototype,1:?Prototype} the landing page and the ad */
    private function parts(Prototype $campaign): array
    {
        $parts = Prototype::where('parent_id', $campaign->id)->get();

        return [$parts->firstWhere('kind', 'site'), $parts->firstWhere('kind', 'ads')];
    }

    /** @return array<string,mixed> */
    private static function row(MarketingCampaign $c): array
    {
        return [
            'id' => $c->id, 'platform' => $c->platform, 'status' => $c->platform_status,
            'budget_eur' => $c->spend_cap_eur, 'spent_eur' => $c->spent_eur, 'spent_today_eur' => $c->spent_today_eur,
            'checked_at' => $c->spend_checked_at?->toIso8601String(), 'ends_at' => $c->ends_at?->toIso8601String(),
            'stopped_reason' => $c->stopped_reason, 'error' => $c->publish_error,
            'countries' => $c->strategy['countries'] ?? [], 'landing_url' => $c->strategy['landing_url'] ?? null,
        ];
    }

    /**
     * The ad's picture, the largest image in the prototype's page. A Codex ad is one picture with
     * its words in it; a hybrid ad's scene is the first large picture.
     *
     * @return ?array{bytes:string,ext:string}
     */
    public static function adPicture(string $html): ?array
    {
        preg_match_all('~data:image/(png|jpe?g|webp);base64,([A-Za-z0-9+/=]+)~', $html, $m, PREG_SET_ORDER);
        usort($m, fn ($a, $b) => strlen($b[2]) <=> strlen($a[2]));
        foreach ($m as $hit) {
            $bytes = base64_decode($hit[2], true);
            if ($bytes !== false && strlen($bytes) > 20000) {
                return ['bytes' => $bytes, 'ext' => $hit[1] === 'jpeg' ? 'jpg' : $hit[1]];
            }
        }

        return null;
    }

    /** The first paragraph of the landing page that says something, as the ad's primary text. */
    private static function primaryText(?Prototype $site): string
    {
        if ($site === null) {
            return '';
        }
        if (preg_match('~<meta\s+name=["\']description["\']\s+content=["\']([^"\']{20,})~i', (string) $site->html, $m) === 1) {
            return mb_substr(html_entity_decode($m[1]), 0, 300);
        }
        preg_match_all('~<p[^>]*>(.*?)</p>~is', (string) $site->html, $ps);
        foreach ($ps[1] as $p) {
            $text = trim(html_entity_decode(strip_tags($p)));
            if (mb_strlen($text) >= 60) {
                return mb_substr($text, 0, 300);
            }
        }

        return '';
    }
}
