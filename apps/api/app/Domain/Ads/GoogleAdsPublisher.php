<?php

namespace App\Domain\Ads;

use App\Models\MarketingCampaign;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Publishes to Google Ads on Codemenschen's own account.
 *
 * Since 2026-09-09 Google no longer issues developer tokens: API access belongs to the Google
 * Cloud project of the OAuth client (Test, Explorer, Basic, Standard, managed in the Cloud
 * console under Google Ads API, access levels). A token that is still set is sent and ignored.
 * Production accounts need at least Explorer; below that the API refuses, and verify() reports
 * the refusal as it comes.
 *
 * Two ways to sign in. Preferred: a service account (GOOGLE_ADS_SERVICE_ACCOUNT_JSON, a key file
 * mounted read-only), added as a user in the Google Ads account; it never expires. Fallback: an
 * OAuth refresh token of a person, which dies after 7 days while the consent screen is in
 * Testing, and publishing it needs a privacy policy link Appwerk did not have (2026-09-15).
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

    /** Env names behind the config keys, so a missing one can be named without being read. */
    private const OAUTH_ENV = [
        'client_id' => 'GOOGLE_ADS_CLIENT_ID',
        'client_secret' => 'GOOGLE_ADS_CLIENT_SECRET',
        'refresh_token' => 'GOOGLE_ADS_REFRESH_TOKEN',
    ];

    public function isConfigured(): bool
    {
        return $this->missing() === [];
    }

    public function missing(): array
    {
        $out = $this->cfg('customer_id') === '' ? ['GOOGLE_ADS_CUSTOMER_ID'] : [];
        if ($this->cfg('service_account_json') !== '') {
            // Named, not read: a path that is set but not there is the usual mistake (mount missing).
            return $this->serviceAccount() === null ? [...$out, 'GOOGLE_ADS_SERVICE_ACCOUNT_JSON'] : $out;
        }
        foreach (self::OAUTH_ENV as $k => $env) {
            if ($this->cfg($k) === '') {
                $out[] = $env;
            }
        }

        return $out;
    }

    /**
     * One search on the configured customer. It exercises everything at once: the OAuth pair and
     * refresh token (access token), login-customer-id (header), and the customer id (the resource).
     * A Cloud project below Explorer access, or a Google account without access, fails here
     * with the refusal Google gives, which is the answer the operator actually needs.
     */
    public function verify(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'account' => null, 'detail' => 'missing '.implode(', ', $this->missing())];
        }

        try {
            $cid = $this->cfg('customer_id');
            $res = Http::withToken($this->accessToken())
                ->withHeaders($this->headers($cid))
                ->timeout(30)
                ->post($this->endpoint("customers/{$cid}/googleAds:search"), [
                    'query' => 'SELECT customer.descriptive_name, customer.currency_code, customer.test_account FROM customer LIMIT 1',
                ]);

            if (! $res->successful()) {
                return ['ok' => false, 'account' => null, 'detail' => self::errorDetail($res->status(), (string) $res->body())];
            }

            $c = $res->json('results.0.customer') ?? [];
            $name = (string) ($c['descriptiveName'] ?? $cid);

            return [
                'ok' => true,
                'account' => $name.' ('.($c['currencyCode'] ?? '?').')'.(! empty($c['testAccount']) ? ' [test account]' : ''),
                'detail' => null,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'account' => null, 'detail' => mb_substr($e->getMessage(), 0, 300)];
        }
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
                'manualCpc' => new \stdClass,
                // Required for every new campaign since the EU political advertising rules.
                'containsEuPoliticalAdvertising' => 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING',
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
            ->withHeaders($this->headers($cid))
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
            throw new RuntimeException('Google: campaign has not been published.');
        }
        $cid = $this->cfg('customer_id');

        $res = Http::withToken($this->accessToken())
            ->withHeaders($this->headers($cid))
            ->timeout(30)
            ->post($this->endpoint("customers/{$cid}/campaigns:mutate"), [
                'operations' => [['updateMask' => 'status', 'update' => ['resourceName' => $resource, 'status' => $status]]],
            ]);

        if (! $res->successful()) {
            throw new RuntimeException('Google Ads API: '.mb_substr((string) $res->body(), 0, 300));
        }
    }

    /** A short-lived access token, from the service account when there is one, else the refresh token. */
    private function accessToken(): string
    {
        $sa = $this->serviceAccount();
        if ($sa !== null) {
            return Cache::remember('google-ads:sa-token:'.md5($sa['client_email']), 3000, fn () => $this->serviceAccountToken($sa));
        }

        $res = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->cfg('client_id'),
            'client_secret' => $this->cfg('client_secret'),
            'refresh_token' => $this->cfg('refresh_token'),
            'grant_type' => 'refresh_token',
        ]);
        $token = (string) $res->json('access_token');
        if ($token === '') {
            throw new RuntimeException('Google OAuth: no access token ('.($res->json('error') ?: 'HTTP '.$res->status()).')');
        }

        return $token;
    }

    /**
     * Google's own words for a refused call, short enough for a table cell: the error code first
     * (USER_PERMISSION_DENIED, DEVELOPER_TOKEN_NOT_APPROVED, CUSTOMER_NOT_FOUND ...), because that
     * is what says who has to fix it, then the message. The raw body is 2 KB of nesting.
     */
    public static function errorDetail(int $http, string $body): string
    {
        $json = json_decode($body, true);
        if (! is_array($json)) {
            return "HTTP {$http}: ".mb_substr(trim($body), 0, 200);
        }

        $codes = [];
        $message = null;
        foreach ((array) ($json['error']['details'] ?? []) as $detail) {
            foreach ((array) ($detail['errors'] ?? []) as $err) {
                foreach ((array) ($err['errorCode'] ?? []) as $group => $code) {
                    $codes[] = "{$group}.{$code}";
                }
                $message ??= $err['message'] ?? null;
            }
        }
        $message ??= $json['error']['message'] ?? null;
        $status = $json['error']['status'] ?? null;

        $head = $codes !== [] ? implode(', ', array_unique($codes)) : ($status ?: "HTTP {$http}");

        return mb_substr($head.($message ? ": {$message}" : ''), 0, 300);
    }

    /** @return array{client_email:string, private_key:string, token_uri:string}|null */
    private function serviceAccount(): ?array
    {
        $path = $this->cfg('service_account_json');
        if ($path === '' || ! is_readable($path)) {
            return null;
        }
        $key = json_decode((string) file_get_contents($path), true);
        if (! is_array($key) || ($key['type'] ?? '') !== 'service_account' || empty($key['client_email']) || empty($key['private_key'])) {
            return null;
        }

        return [
            'client_email' => (string) $key['client_email'],
            'private_key' => (string) $key['private_key'],
            'token_uri' => (string) ($key['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
        ];
    }

    /** JWT bearer grant (RFC 7523): a signed assertion for the adwords scope, exchanged for an access token. */
    private function serviceAccountToken(array $sa): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now = time();
        $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode([
            'iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/adwords',
            'aud' => $sa['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        if (! openssl_sign($unsigned, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Google service account: the key file cannot sign.');
        }

        $res = Http::asForm()->timeout(20)->post($sa['token_uri'], [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsigned.'.'.$b64($signature),
        ]);
        $token = (string) $res->json('access_token');
        if ($token === '') {
            throw new RuntimeException('Google service account: no access token ('.mb_substr((string) $res->json('error_description', $res->body()), 0, 160).').');
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
            throw new RuntimeException("Google: at least {$min} {$kind} required.");
        }

        return $texts->map(fn ($t) => ['text' => $t])->all();
    }

    /** login-customer-id always; developer-token only while one is still configured (Google ignores it). */
    private function headers(string $cid): array
    {
        $headers = ['login-customer-id' => $this->cfg('login_customer_id') ?: $cid];
        if ($this->cfg('developer_token') !== '') {
            $headers['developer-token'] = $this->cfg('developer_token');
        }

        return $headers;
    }

    private function endpoint(string $path): string
    {
        $v = $this->cfg('api_version') ?: 'v25';

        return "https://googleads.googleapis.com/{$v}/{$path}";
    }

    private function cfg(string $k): string
    {
        return (string) config("services.ads.google.$k");
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Google Ads is not configured (GOOGLE_ADS_CUSTOMER_ID / CLIENT_ID / CLIENT_SECRET / REFRESH_TOKEN).');
        }
    }
}
