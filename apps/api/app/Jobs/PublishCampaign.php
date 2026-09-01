<?php

namespace App\Jobs;

use App\Domain\Ads\Preflight;
use App\Domain\Ads\PublisherRegistry;
use App\Models\MarketingCampaign;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates the campaign on its platform, PAUSED. Runs on the queue because it makes several
 * external API calls; the row carries the state the portal polls.
 *
 * This job never activates anything. It leaves the campaign paused and spending nothing; a person
 * turns it on afterwards through the activate endpoint. tries = 1 so a half-created campaign is
 * not silently attempted twice, which on an ad platform means duplicate objects.
 */
class PublishCampaign implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $campaignId) {}

    public function handle(PublisherRegistry $registry, Preflight $preflight): void
    {
        $campaign = MarketingCampaign::with(['creatives', 'project', 'projectAd'])->find($this->campaignId);
        if (! $campaign || $campaign->platform_status === 'active') {
            return;
        }

        $problems = $preflight->check($campaign);
        if ($problems !== []) {
            $campaign->update(['platform_status' => 'failed', 'publish_error' => implode(' · ', $problems)]);

            return;
        }

        $campaign->update(['platform_status' => 'publishing', 'publish_error' => null]);

        try {
            $ref = $registry->for($campaign->platform)->publish($campaign);
            $campaign->update([
                'platform_status' => 'paused',
                'platform_ref' => $ref,
                'external_campaign_id' => $ref['campaign_id'] ?? null,
                'published_at' => now(),
            ]);
            optional($campaign->project)->recordEvent('marketing.published', [
                'campaign_id' => $campaign->id, 'platform' => $campaign->platform,
            ]);
        } catch (Throwable $e) {
            Log::error('publish campaign failed', ['campaign' => $campaign->id, 'error' => $e->getMessage()]);
            $campaign->update(['platform_status' => 'failed', 'publish_error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }
}
