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

    private const ENV = [
        'token' => 'META_ADS_TOKEN',
        'ad_account_id' => 'META_ADS_ACCOUNT_ID',
        'page_id' => 'META_ADS_PAGE_ID',
    ];

    public function isConfigured(): bool
    {
        return $this->missing() === [];
    }

    public function missing(): array
    {
        $out = [];
        foreach (self::ENV as $k => $env) {
            if ($this->cfg($k) === '') {
                $out[] = $env;
            }
        }

        return $out;
    }

    /** Reads the ad account and the page. Both must open with this token or nothing can publish. */
    public function verify(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'account' => null, 'detail' => 'missing '.implode(', ', $this->missing())];
        }

        try {
            $account = $this->get($this->cfg('ad_account_id'), 'name,currency,account_status');
            $page = $this->get($this->cfg('page_id'), 'name');

            // account_status 1 is ACTIVE; anything else (2 disabled, 3 unsettled, ...) will refuse spend.
            $status = (int) ($account['account_status'] ?? 0);

            return [
                'ok' => $status === 1,
                'account' => ($account['name'] ?? $this->cfg('ad_account_id')).' ('.($account['currency'] ?? '?').'), Seite: '.($page['name'] ?? $this->cfg('page_id')),
                'detail' => $status === 1 ? null : "account_status={$status}",
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'account' => null, 'detail' => mb_substr($e->getMessage(), 0, 300)];
        }
    }

    public function publish(MarketingCampaign $campaign): array
    {
        $this->assertConfigured();
        $act = $this->cfg('ad_account_id');
        $path = $campaign->creativePath();
        if ($path === null) {
            throw new RuntimeException('Meta: no creative file to publish.');
        }

        $ref = [];

        // 1. the creative file. Image => image_hash, video => video_id.
        if ($campaign->creativeKind() === 'video') {
            $up = $this->post("{$act}/advideos", [
                'source' => fopen($path, 'r'),
            ], multipart: true);
            $ref['video_id'] = $up['id'] ?? null;
        } else {
            $up = $this->post("{$act}/adimages", [
                'bytes' => base64_encode((string) file_get_contents($path)),
            ]);
            // adimages answers {images:{<name>:{hash}}}
            $images = $up['images'] ?? [];
            $ref['image_hash'] = $images[array_key_first($images)]['hash'] ?? null;
        }

        // 2. the campaign, paused. With a total, Meta itself refuses to spend past it (the first
        // wall of the spend guard). Meta takes no spend cap under 100 EUR; a smaller test is held
        // by its lifetime budget below.
        $params = [
            'name' => $this->name($campaign),
            'objective' => 'OUTCOME_TRAFFIC',
            'status' => 'PAUSED',
            'special_ad_categories' => json_encode([]),
            // No campaign budget: each ad set spends its own. The API wants that said out loud.
            'is_adset_budget_sharing_enabled' => 'false',
        ];
        if ($campaign->spend_cap_eur !== null && $campaign->spend_cap_eur >= 100) {
            $params['spend_cap'] = $campaign->spend_cap_eur * 100;
        }
        $created = $this->post("{$act}/campaigns", $params);
        $ref['campaign_id'] = $created['id'] ?? null;

        // 3. the ad set. A test with a total and an end runs on a lifetime budget with an end
        // time, so Meta stops on its own when either is reached. Otherwise the customer's monthly
        // budget, as a daily cap in minor units (cents).
        $budget = $campaign->spend_cap_eur !== null && $campaign->ends_at !== null
            ? ['lifetime_budget' => $campaign->spend_cap_eur * 100, 'start_time' => now()->toIso8601String(),
                'end_time' => $campaign->ends_at->toIso8601String()]
            : ['daily_budget' => max(100, (int) round($campaign->ad_budget_monthly_eur * 100 / 30))];
        $countries = array_values(array_filter((array) ($campaign->strategy['countries'] ?? []))) ?: ['AT', 'DE'];
        $adset = $this->post("{$act}/adsets", $budget + [
            'name' => $this->name($campaign).': set',
            'campaign_id' => $ref['campaign_id'],
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => 'LINK_CLICKS',
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'targeting' => json_encode(['geo_locations' => ['countries' => $countries]]),
            'dsa_beneficiary' => $this->cfg('dsa_beneficiary'),
            'dsa_payor' => $this->cfg('dsa_payor'),
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
            'name' => $this->name($campaign).': creative',
            'object_story_spec' => json_encode([
                'page_id' => $this->cfg('page_id'),
                'link_data' => $linkData,
            ]),
        ]);
        $ref['creative_id'] = $creative['id'] ?? null;

        // 5. the ad, paused.
        $adObj = $this->post("{$act}/ads", [
            'name' => $this->name($campaign).': ad',
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
            throw new RuntimeException('Meta: campaign has not been published.');
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

    public function spend(MarketingCampaign $campaign): array
    {
        $this->assertConfigured();
        $id = $campaign->platform_ref['campaign_id'] ?? null;
        if (! $id) {
            throw new RuntimeException('Meta: campaign has not been published.');
        }
        $read = fn (string $preset) => (float) ($this->insights($id, $preset)['data'][0]['spend'] ?? 0);

        return ['total' => $read('maximum'), 'today' => $read('today')];
    }

    /** @return array<string,mixed> */
    private function insights(string $id, string $preset): array
    {
        $res = $this->base()->get("{$id}/insights", ['fields' => 'spend', 'date_preset' => $preset, 'access_token' => $this->cfg('token')]);
        if (! $res->successful()) {
            throw new RuntimeException('Meta API: '.($res->json('error.message') ?? mb_substr((string) $res->body(), 0, 300)));
        }

        return $res->json() ?? [];
    }

    private function name(MarketingCampaign $campaign): string
    {
        return 'Appwerk #'.$campaign->id.' '.mb_substr((string) ($campaign->project?->name ?? $campaign->prototype?->title), 0, 40);
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
            throw new RuntimeException('Meta Ads is not configured (META_ADS_TOKEN / ACCOUNT_ID / PAGE_ID).');
        }
    }

    private function base(): PendingRequest
    {
        $v = $this->cfg('api_version') ?: 'v26.0';

        return Http::baseUrl("https://graph.facebook.com/{$v}")->timeout(60)->connectTimeout(10);
    }

    /** @return array<string,mixed> */
    private function get(string $path, string $fields): array
    {
        $res = $this->base()->get($path, ['fields' => $fields, 'access_token' => $this->cfg('token')]);
        $body = $res->json() ?? [];
        if (! $res->successful()) {
            throw new RuntimeException('Meta API: '.($body['error']['message'] ?? mb_substr((string) $res->body(), 0, 300)));
        }

        return $body;
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
