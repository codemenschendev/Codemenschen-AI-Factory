<?php

namespace Tests\Feature;

use App\Domain\Ads\GoogleAdsPublisher;
use App\Domain\Ads\MetaAdsPublisher;
use App\Domain\Ads\PublisherRegistry;
use App\Models\Creative;
use App\Models\Customer;
use App\Models\MarketingCampaign;
use App\Models\ProjectAd;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Is Facebook connected yet" answered by code rather than by memory.
 *
 * Two layers: what is configured (env names, checked locally, shown on the admin overview) and
 * what actually works (one read call per platform, made by factory:ads-check). The second layer
 * is faked here; what is being tested is that the right call is made and its answer is read.
 */
class AdsConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private function google(array $over = []): void
    {
        config(['services.ads.google' => $over + [
            'developer_token' => 'dev', 'customer_id' => '1234567890', 'login_customer_id' => '',
            'client_id' => 'cid', 'client_secret' => 'sec', 'refresh_token' => 'rt', 'api_version' => 'v25',
        ]]);
    }

    private function meta(array $over = []): void
    {
        config(['services.ads.meta' => $over + [
            'token' => 'tok', 'ad_account_id' => 'act_1', 'page_id' => '99', 'api_version' => 'v26.0',
        ]]);
    }

    public function test_status_names_the_missing_env_keys_and_never_their_values(): void
    {
        config(['services.ads.google' => ['developer_token' => '', 'customer_id' => '1234567890',
            'client_id' => 'cid', 'client_secret' => 'top-secret', 'refresh_token' => '']]);
        config(['services.ads.meta' => ['token' => 'tok', 'ad_account_id' => '', 'page_id' => '']]);

        $status = app(PublisherRegistry::class)->status();

        $this->assertFalse($status['google']['configured']);
        $this->assertSame(['GOOGLE_ADS_REFRESH_TOKEN'], $status['google']['missing'], 'the developer token is no longer required');
        $this->assertSame(['META_ADS_ACCOUNT_ID', 'META_ADS_PAGE_ID'], $status['meta']['missing']);
        $this->assertStringNotContainsString('top-secret', json_encode($status));
    }

    public function test_google_verify_opens_the_customer_with_every_credential_at_once(): void
    {
        $this->google();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at']),
            'googleads.googleapis.com/*' => Http::response(['results' => [['customer' => [
                'descriptiveName' => 'Codemenschen', 'currencyCode' => 'EUR', 'testAccount' => false,
            ]]]]),
        ]);

        $v = app(GoogleAdsPublisher::class)->verify();

        $this->assertTrue($v['ok']);
        $this->assertSame('Codemenschen (EUR)', $v['account']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'customers/1234567890/googleAds:search')
            && $r->hasHeader('developer-token', 'dev') && $r->hasHeader('login-customer-id', '1234567890'));
    }

    public function test_google_works_without_a_developer_token(): void
    {
        $this->google(['developer_token' => '']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at']),
            'googleads.googleapis.com/*' => Http::response(['results' => [['customer' => ['descriptiveName' => 'Appwerk', 'currencyCode' => 'EUR']]]]),
        ]);

        $this->assertTrue(app(GoogleAdsPublisher::class)->verify()['ok']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'googleAds:search') && ! $r->hasHeader('developer-token'));
    }

    public function test_an_api_refusal_is_reported_not_swallowed(): void
    {
        $this->google();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at']),
            'googleads.googleapis.com/*' => Http::response(['error' => ['message' => 'DEVELOPER_TOKEN_NOT_APPROVED']], 403),
        ]);

        $v = app(GoogleAdsPublisher::class)->verify();

        $this->assertFalse($v['ok']);
        $this->assertStringContainsString('DEVELOPER_TOKEN_NOT_APPROVED', $v['detail']);
    }

    public function test_meta_verify_needs_both_the_account_and_the_page(): void
    {
        $this->meta();
        Http::fake([
            'graph.facebook.com/v26.0/act_1*' => Http::response(['name' => 'Codemenschen Ads', 'currency' => 'EUR', 'account_status' => 1]),
            'graph.facebook.com/v26.0/99*' => Http::response(['name' => 'Appwerk']),
        ]);

        $v = app(MetaAdsPublisher::class)->verify();

        $this->assertTrue($v['ok']);
        $this->assertSame('Codemenschen Ads (EUR), Seite: Appwerk', $v['account']);
    }

    public function test_a_disabled_meta_account_is_not_ok(): void
    {
        $this->meta();
        Http::fake([
            'graph.facebook.com/v26.0/act_1*' => Http::response(['name' => 'X', 'currency' => 'EUR', 'account_status' => 2]),
            'graph.facebook.com/v26.0/99*' => Http::response(['name' => 'Appwerk']),
        ]);

        $v = app(MetaAdsPublisher::class)->verify();

        $this->assertFalse($v['ok']);
        $this->assertSame('account_status=2', $v['detail']);
    }

    public function test_verify_makes_no_call_when_nothing_is_configured(): void
    {
        config(['services.ads.meta' => ['token' => '', 'ad_account_id' => '', 'page_id' => '']]);
        Http::fake();

        $v = app(MetaAdsPublisher::class)->verify();

        $this->assertFalse($v['ok']);
        Http::assertNothingSent();
    }

    public function test_the_admin_overview_carries_the_connection_status(): void
    {
        config(['services.ads.meta' => ['token' => '', 'ad_account_id' => '', 'page_id' => '']]);
        $token = Customer::create(['email' => 'ops@example.com', 'locale' => 'de', 'is_admin' => true])
            ->createToken('portal')->plainTextToken;

        $this->getJson('/api/admin/overview', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('connections.meta.configured', false)
            ->assertJsonPath('connections.meta.missing.0', 'META_ADS_TOKEN');
    }

    public function test_the_check_command_reports_one_line_per_platform(): void
    {
        config(['services.ads.meta' => ['token' => '', 'ad_account_id' => '', 'page_id' => '']]);
        $this->google();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at']),
            'googleads.googleapis.com/*' => Http::response(['results' => [['customer' => ['descriptiveName' => 'Codemenschen', 'currencyCode' => 'EUR']]]]),
        ]);

        $this->artisan('factory:ads-check')
            ->expectsOutputToContain('META_ADS_TOKEN')
            ->expectsOutputToContain('Codemenschen (EUR)')
            ->assertExitCode(1); // meta is not configured, so the whole check is not green
    }

    private function campaign(): MarketingCampaign
    {
        $campaign = new MarketingCampaign(['ad_budget_monthly_eur' => 300, 'strategy' => ['landing_url' => 'https://example.com']]);
        $campaign->id = 7;
        $creatives = collect([
            ['headline', 'Tisch reservieren'], ['headline', 'Kaffee und Kuchen'], ['headline', 'Wiener Kaffeehaus'],
            ['ad_copy', 'Reservier deinen Tisch in Sekunden.'], ['ad_copy', 'Karte ansehen und Punkte sammeln.'],
        ])->map(fn ($c) => new Creative(['kind' => $c[0], 'content' => $c[1]]));
        $campaign->setRelation('creatives', $creatives);
        $campaign->setRelation('project', null);

        return $campaign;
    }

    public function test_google_publish_uses_a_live_api_version_and_declares_no_eu_political_ads(): void
    {
        $this->google(['api_version' => '']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at']),
            'googleads.googleapis.com/*' => Http::response(['mutateOperationResponses' => []]),
        ]);

        app(GoogleAdsPublisher::class)->publish($this->campaign());

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://googleads.googleapis.com/v25/')
            && ($r['mutateOperations'][1]['campaignOperation']['create']['containsEuPoliticalAdvertising'] ?? null) === 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING'
            && $r['mutateOperations'][1]['campaignOperation']['create']['status'] === 'PAUSED');
    }

    public function test_meta_publish_names_who_benefits_and_who_pays_for_eu_ad_sets(): void
    {
        $this->meta(['api_version' => '', 'dsa_beneficiary' => 'Codemenschen GmbH', 'dsa_payor' => 'Codemenschen GmbH']);
        $file = tempnam(sys_get_temp_dir(), 'ad').'.png';
        file_put_contents($file, 'png');
        $ad = new class extends ProjectAd
        {
            public string $file = '';

            public function absolutePath(): string
            {
                return $this->file;
            }
        };
        $ad->file = $file;
        $ad->kind = 'image';
        $campaign = $this->campaign();
        $campaign->setRelation('projectAd', $ad);
        Http::fake([
            '*/adimages' => Http::response(['images' => ['a' => ['hash' => 'h1']]]),
            '*' => Http::response(['id' => '123']),
        ]);

        app(MetaAdsPublisher::class)->publish($campaign);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com/v26.0/act_1/campaigns') && $r['is_adset_budget_sharing_enabled'] === 'false');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/act_1/adsets') && $r['dsa_beneficiary'] === 'Codemenschen GmbH' && $r['dsa_payor'] === 'Codemenschen GmbH');
    }

    public function test_google_signs_in_with_a_service_account_instead_of_a_refresh_token(): void
    {
        $pem = '';
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
        $file = tempnam(sys_get_temp_dir(), 'sa');
        file_put_contents($file, json_encode(['type' => 'service_account', 'client_email' => 'appwerk-ads@cm-ops.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']));
        $this->google(['developer_token' => '', 'client_id' => '', 'client_secret' => '', 'refresh_token' => '', 'service_account_json' => $file]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'sa-at']),
            'googleads.googleapis.com/*' => Http::response(['results' => [['customer' => ['descriptiveName' => 'codemenschen gmbh', 'currencyCode' => 'EUR']]]]),
        ]);

        $this->assertSame([], app(GoogleAdsPublisher::class)->missing());
        $this->assertTrue(app(GoogleAdsPublisher::class)->verify()['ok']);

        Http::assertSent(function ($r) use ($pem) {
            if (! str_contains($r->url(), 'oauth2.googleapis.com/token')) {
                return false;
            }
            [$head, $claims, $sig] = explode('.', $r['assertion']);
            $d = fn ($x) => base64_decode(strtr($x, '-_', '+/'));
            $public = openssl_pkey_get_details(openssl_pkey_get_private($pem))['key'];

            return $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && json_decode($d($claims), true)['scope'] === 'https://www.googleapis.com/auth/adwords'
                && openssl_verify($head.'.'.$claims, $d($sig), $public, OPENSSL_ALGO_SHA256) === 1;
        });
        Http::assertSent(fn ($r) => str_contains($r->url(), 'googleAds:search') && $r->hasHeader('Authorization', 'Bearer sa-at'));
    }

    public function test_a_service_account_path_without_a_file_is_named_as_missing(): void
    {
        $this->google(['service_account_json' => '/secrets/not-there.json', 'refresh_token' => '']);

        $this->assertSame(['GOOGLE_ADS_SERVICE_ACCOUNT_JSON'], app(GoogleAdsPublisher::class)->missing());
    }
}
