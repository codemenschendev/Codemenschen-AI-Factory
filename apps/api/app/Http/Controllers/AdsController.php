<?php

namespace App\Http\Controllers;

use App\Domain\Ads\Preflight;
use App\Domain\Ads\PublisherRegistry;
use App\Jobs\PublishCampaign;
use App\Models\MarketingCampaign;
use App\Models\ProjectAd;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Runs a campaign on Codemenschen's ad accounts. Scoped to the customer's own projects.
 *
 * Publishing creates the campaign paused; it never spends. Activation is separate and is the one
 * action that starts spend, so it is guarded: only a published, paused campaign whose ad budget
 * is funded can be activated, and it is only ever reached by a person pressing the button.
 */
class AdsController extends Controller
{
    public function index(Request $request, PublisherRegistry $registry, Preflight $preflight): JsonResponse
    {
        $campaigns = MarketingCampaign::query()
            ->whereIn('project_id', $request->user()->projects()->select('id'))
            ->with(['creatives', 'project:id,name', 'projectAd:id,kind,status'])
            ->latest()
            ->get()
            ->map(fn (MarketingCampaign $c) => [
                'id' => $c->id,
                'platform' => $c->platform,
                'content_status' => $c->status,           // customer sign-off
                'platform_status' => $c->platform_status,  // life on the platform
                'budget_monthly_eur' => (int) $c->ad_budget_monthly_eur,
                'ad' => $c->projectAd ? ['id' => $c->projectAd->id, 'kind' => $c->projectAd->kind] : null,
                'error' => $c->publish_error,
                'problems' => $preflight->check($c),
                'project' => ['id' => $c->project->id, 'name' => $c->project->name],
            ]);

        return response()->json([
            'campaigns' => $campaigns,
            'platforms' => $registry->configured(),
        ]);
    }

    /** Attach a rendered ad file to a campaign, then queue publishing it paused. */
    public function publish(Request $request, MarketingCampaign $campaign, Preflight $preflight): JsonResponse
    {
        $this->authorize($request, $campaign);

        $data = $request->validate(['project_ad_id' => 'nullable|integer']);

        if (! empty($data['project_ad_id'])) {
            $ad = ProjectAd::find($data['project_ad_id']);
            // The creative must belong to the same project, and be finished rendering.
            abort_unless($ad && $ad->project_id === $campaign->project_id, 422, 'Creative không thuộc project này.');
            abort_unless($ad->status === 'ready', 422, 'Creative chưa render xong.');
            $campaign->update(['project_ad_id' => $ad->id]);
            $campaign->load('projectAd');
        }

        $problems = $preflight->check($campaign);
        if ($problems !== []) {
            return response()->json(['problems' => $problems], 422);
        }

        abort_if(in_array($campaign->platform_status, ['publishing', 'active'], true), 409, 'Campaign đang xử lý hoặc đã chạy.');

        $campaign->update(['platform_status' => 'publishing', 'publish_error' => null]);
        PublishCampaign::dispatch($campaign->id);

        return response()->json(['platform_status' => 'publishing'], 202);
    }

    /** The spend gate. A person calls this; nothing automatic does. */
    public function activate(Request $request, MarketingCampaign $campaign, PublisherRegistry $registry): JsonResponse
    {
        $this->authorize($request, $campaign);

        abort_unless($campaign->platform_status === 'paused', 409, 'Chỉ bật được campaign đã đăng và đang tạm dừng.');
        abort_unless((int) $campaign->ad_budget_monthly_eur > 0, 422, 'Ngân sách bằng 0, khách chưa thanh toán.');

        $registry->for($campaign->platform)->activate($campaign);
        $campaign->update(['platform_status' => 'active', 'activated_at' => now()]);
        optional($campaign->project)->recordEvent('marketing.activated', [
            'campaign_id' => $campaign->id, 'actor' => 'customer:'.$request->user()->email,
        ]);

        return response()->json(['platform_status' => 'active']);
    }

    public function pause(Request $request, MarketingCampaign $campaign, PublisherRegistry $registry): JsonResponse
    {
        $this->authorize($request, $campaign);

        abort_unless($campaign->platform_status === 'active', 409, 'Campaign không đang chạy.');
        $registry->for($campaign->platform)->pause($campaign);
        $campaign->update(['platform_status' => 'paused']);

        return response()->json(['platform_status' => 'paused']);
    }

    private function authorize(Request $request, MarketingCampaign $campaign): void
    {
        abort_unless(optional($campaign->project)->customer_id === $request->user()->id, 404);
    }
}
