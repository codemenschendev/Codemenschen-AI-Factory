<?php

namespace App\Domain\Ads;

use App\Models\MarketingCampaign;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Publishes to a Meta (Facebook/Instagram) ad account owned by Codemenschen.
 *
 * Every object is created with status PAUSED, so publishing spends nothing: the campaign, its ad
 * set and the ad all sit paused until someone calls activate(). The customer pays, so the monthly
 * budget they bought becomes the ad set's daily cap (monthly / 30), which Meta will not exceed.
 *
 * Graph API objects, in order: adimage/advideo (the creative file) -> campaign -> adset (budget,
 * targeting) -> adcreative (copy + creative file + link) -> ad. Each id is kept in platform_ref
 * so activate/pause can find them again.
 */
class MetaAdsPublisher implements Publisher
{
    public function key(): string
    {
        return 'meta';
    }

    public function isConfigured(): bool
    {
        return $this->cfg('token') !== '' && $this->cfg('ad_account_id') !== '' && $this->cfg('page_id') !== '';
    }

    public function publish(MarketingCampaign $campaign): array
    {
        $this->assertConfigured();
        $act = $this->cfg('ad_account_id');
        $ad = $campaign->projectAd;

        if (! $ad || ! is_file($ad->absolutePath())) {
            throw new RuntimeException('Meta: không có file creative để đăng.');
        }

        $ref = [];

        // 1. the creative file. Image => image_hash, video => video_id.
        if ($ad->kind === 'video') {
            $up = $this->post("{$act}/advideos", [
                'source' => fopen($ad->absolutePath(), 'r'),
            ], multipart: true);
            $ref['video_id'] = $up['id'] ?? null;
        } else {
            $up = $this->post("{$act}/adimages", [
                'bytes' => base64_encode((string) file_get_contents($ad->absolutePath())),
            ]);
            // adimages answers {images:{<name>:{hash}}}
            $images = $up['images'] ?? [];
            $ref['image_hash'] = $images[array_key_first($images)]['hash'] ?? null;
        }

        // 2. the campaign, paused.
        $created = $this->post("{$act}/campaigns", [
            'name' => $this->name($campaign),
            'objective' => 'OUTCOME_TRAFFIC',
            'status' => 'PAUSED',
            'special_ad_categories' => json_encode([]),
        ]);
        $ref['campaign_id'] = $created['id'] ?? null;

        // 3. the ad set: the customer's monthly budget, as a daily cap in minor units (cents).
        $daily = max(100, (int) round($campaign->ad_budget_monthly_eur * 100 / 30));
        $adset = $this->post("{$act}/adsets", [
            'name' => $this->name($campaign).' – set',
            'campaign_id' => $ref['campaign_id'],
            'daily_budget' => $daily,
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => 'LINK_CLICKS',
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'targeting' => json_encode(['geo_locations' => ['countries' => ['AT', 'DE']]]),
            'status' => 'PAUSED',
        ]);
        $ref['adset_id'] = $adset['id'] ?? null;

        // 4. the creative: first headline as the link name, first ad_copy as the message.
        $link = (string) ($campaign->strategy['landing_url'] ?? 'https://appwerk.codemenschen.at');
        $linkData = [
            'message' => $this->firstCreative($campaign, 'ad_copy'),
            'name' => $this->firstCreative($campaign, 'headline'),
            'link' => $link,
        ];
        if (! empty($ref['image_hash'])) {
            $linkData['image_hash'] = $ref['image_hash'];
        }
        if (! empty($ref['video_id'])) {
            $linkData['video_id'] = $ref['video_id'];
        }
        $creative = $this->post("{$act}/adcreatives", [
            'name' => $this->name($campaign).' – creative',
            'object_story_spec' => json_encode([
                'page_id' => $this->cfg('page_id'),
                'link_data' => $linkData,
            ]),
        ]);
        $ref['creative_id'] = $creative['id'] ?? null;

        // 5. the ad, paused.
        $adObj = $this->post("{$act}/ads", [
            'name' => $this->name($campaign).' – ad',
            'adset_id' => $ref['adset_id'],
            'creative' => json_encode(['creative_id' => $ref['creative_id']]),
            'status' => 'PAUSED',
        ]);
        $ref['ad_id'] = $adObj['id'] ?? null;

        return $ref;
    }

    public function activate(MarketingCampaign $campaign): void
    {
        $id = $campaign->platform_ref['campaign_id'] ?? null;
        if (! $id) {
            throw new RuntimeException('Meta: campaign chưa được đăng.');
        }
        $this->post($id, ['status' => 'ACTIVE']);
    }

    public function pause(MarketingCampaign $campaign): void
    {
        $id = $campaign->platform_ref['campaign_id'] ?? null;
        if ($id) {
            $this->post($id, ['status' => 'PAUSED']);
        }
    }

    private function name(MarketingCampaign $campaign): string
    {
        return 'Appwerk #'.$campaign->id.' '.mb_substr((string) optional($campaign->project)->name, 0, 40);
    }

    private function firstCreative(MarketingCampaign $campaign, string $kind): string
    {
        $c = $campaign->creatives->firstWhere('kind', $kind);

        return $c ? mb_substr((string) $c->content, 0, 500) : '';
    }

    private function cfg(string $k): string
    {
        return (string) config("services.ads.meta.$k");
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Meta Ads chưa cấu hình (META_ADS_TOKEN / ACCOUNT_ID / PAGE_ID).');
        }
    }

    private function base(): PendingRequest
    {
        $v = $this->cfg('api_version') ?: 'v21.0';

        return Http::baseUrl("https://graph.facebook.com/{$v}")->timeout(60)->connectTimeout(10);
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function post(string $path, array $params, bool $multipart = false): array
    {
        $params['access_token'] = $this->cfg('token');
        $req = $this->base();

        if ($multipart) {
            foreach ($params as $k => $v) {
                $req = is_resource($v)
                    ? $req->attach($k, $v, 'upload')
                    : $req->attach($k, (string) $v);
            }
            $res = $req->post($path);
        } else {
            $res = $req->asForm()->post($path, $params);
        }

        $body = $res->json() ?? [];
        if (! $res->successful()) {
            $msg = $body['error']['message'] ?? mb_substr((string) $res->body(), 0, 300);
            throw new RuntimeException('Meta API: '.$msg);
        }

        return $body;
    }
}
