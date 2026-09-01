<?php

namespace App\Domain\Ads;

use App\Models\MarketingCampaign;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Publishes to Google Ads on Codemenschen's own account.
 *
 * Google gates the Ads API behind a developer token it approves by hand, which takes days to
 * weeks. Until that token exists this stays unconfigured and says so plainly rather than failing
 * deep in an API call. The shape is here so the day the token arrives it is a config change, not
 * a build.
 *
 * A Search campaign is built paused: campaign_budget -> campaign (PAUSED) -> ad_group ->
 * responsive_search_ad. Budgets are in micros (EUR * 1_000_000); the customer's monthly budget
 * is the campaign budget, so Google will not spend past what they paid for.
 */
class GoogleAdsPublisher implements Publisher
{
    public function key(): string
    {
        return 'google';
    }

    public function isConfigured(): bool
    {
        foreach (['developer_token', 'customer_id', 'client_id', 'client_secret', 'refresh_token'] as $k) {
            if ($this->cfg($k) === '') {
                return false;
            }
        }

        return true;
    }

    public function publish(MarketingCampaign $campaign): array
    {
        $this->assertConfigured();

        $cid = $this->cfg('customer_id');
        $token = $this->accessToken();
        $monthlyMicros = (int) round($campaign->ad_budget_monthly_eur * 1_000_000);

        // Google Ads mutates are batched under googleAdsService:mutate. Each operation refers to
        // the previous one by a temporary negative resource id, so budget -> campaign -> ad group
        // -> ad all commit together, and the campaign lands PAUSED.
        $ops = [
            ['campaignBudgetOperation' => ['create' => [
                'resourceName' => "customers/{$cid}/campaignBudgets/-1",
                'name' => 'Appwerk budget #'.$campaign->id.'-'.now()->timestamp,
                'amountMicros' => (string) $monthlyMicros,
                'deliveryMethod' => 'STANDARD',
                'explicitlyShared' => false,
            ]]],
            ['campaignOperation' => ['create' => [
                'resourceName' => "customers/{$cid}/campaigns/-2",
                'name' => 'Appwerk #'.$campaign->id.'-'.now()->timestamp,
                'status' => 'PAUSED',
                'advertisingChannelType' => 'SEARCH',
                'campaignBudget' => "customers/{$cid}/campaignBudgets/-1",
                'networkSettings' => ['targetGoogleSearch' => true, 'targetSearchNetwork' => true],
                'manualCpc' => new \stdClass(),
            ]]],
            ['adGroupOperation' => ['create' => [
                'resourceName' => "customers/{$cid}/adGroups/-3",
                'name' => 'Appwerk #'.$campaign->id.' group',
                'campaign' => "customers/{$cid}/campaigns/-2",
                'status' => 'ENABLED',   // the campaign being PAUSED is what stops spend
                'type' => 'SEARCH_STANDARD',
            ]]],
            ['adGroupAdOperation' => ['create' => [
                'adGroup' => "customers/{$cid}/adGroups/-3",
                'status' => 'ENABLED',
                'ad' => ['responsiveSearchAd' => [
                    'headlines' => $this->assets($campaign, 'headline', 3, 15),
                    'descriptions' => $this->assets($campaign, 'ad_copy', 2, 4),
                ], 'finalUrls' => [(string) ($campaign->strategy['landing_url'] ?? 'https://appwerk.codemenschen.at')]],
            ]]],
        ];

        $res = Http::withToken($token)
            ->withHeaders([
                'developer-token' => $this->cfg('developer_token'),
                'login-customer-id' => $this->cfg('login_customer_id') ?: $cid,
            ])
            ->timeout(60)
            ->post($this->endpoint("customers/{$cid}/googleAds:mutate"), ['mutateOperations' => $ops]);

        $body = $res->json() ?? [];
        if (! $res->successful()) {
            throw new RuntimeException('Google Ads API: '.($body['error']['message'] ?? mb_substr((string) $res->body(), 0, 300)));
        }

        $results = $body['mutateOperationResponses'] ?? [];

        return [
            'budget' => $results[0]['campaignBudgetResult']['resourceName'] ?? null,
            'campaign_id' => $results[1]['campaignResult']['resourceName'] ?? null,
            'ad_group' => $results[2]['adGroupResult']['resourceName'] ?? null,
            'ad' => $results[3]['adGroupAdResult']['resourceName'] ?? null,
        ];
    }

    public function activate(MarketingCampaign $campaign): void
    {
        $this->setStatus($campaign, 'ENABLED');
    }

    public function pause(MarketingCampaign $campaign): void
    {
        $this->setStatus($campaign, 'PAUSED');
    }

    private function setStatus(MarketingCampaign $campaign, string $status): void
    {
        $this->assertConfigured();
        $resource = $campaign->platform_ref['campaign_id'] ?? null;
        if (! $resource) {
            throw new RuntimeException('Google: campaign chưa được đăng.');
        }
        $cid = $this->cfg('customer_id');

        $res = Http::withToken($this->accessToken())
            ->withHeaders([
                'developer-token' => $this->cfg('developer_token'),
                'login-customer-id' => $this->cfg('login_customer_id') ?: $cid,
            ])
            ->timeout(30)
            ->post($this->endpoint("customers/{$cid}/campaigns:mutate"), [
                'operations' => [['updateMask' => 'status', 'update' => ['resourceName' => $resource, 'status' => $status]]],
            ]);

        if (! $res->successful()) {
            throw new RuntimeException('Google Ads API: '.mb_substr((string) $res->body(), 0, 300));
        }
    }

    /** Exchange the long-lived refresh token for a short-lived access token. */
    private function accessToken(): string
    {
        $res = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->cfg('client_id'),
            'client_secret' => $this->cfg('client_secret'),
            'refresh_token' => $this->cfg('refresh_token'),
            'grant_type' => 'refresh_token',
        ]);
        $token = (string) $res->json('access_token');
        if ($token === '') {
            throw new RuntimeException('Google OAuth: không lấy được access token.');
        }

        return $token;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function assets(MarketingCampaign $campaign, string $kind, int $min, int $maxCount): array
    {
        $texts = $campaign->creatives->where('kind', $kind)->pluck('content')
            ->map(fn ($c) => trim((string) $c))->filter()->take($maxCount)->values();

        if ($texts->count() < $min) {
            throw new RuntimeException("Google: cần ít nhất {$min} {$kind}.");
        }

        return $texts->map(fn ($t) => ['text' => $t])->all();
    }

    private function endpoint(string $path): string
    {
        $v = $this->cfg('api_version') ?: 'v18';

        return "https://googleads.googleapis.com/{$v}/{$path}";
    }

    private function cfg(string $k): string
    {
        return (string) config("services.ads.google.$k");
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Google Ads chưa cấu hình (developer token của Google được duyệt tay, cần vài ngày).');
        }
    }
}
