<?php

namespace Tests\Feature;

use App\Domain\Ads\GoogleAdsPublisher;
use App\Domain\Ads\MetaAdsPublisher;
use App\Domain\Ads\PublisherRegistry;
use App\Models\Customer;
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
            'client_id' => 'cid', 'client_secret' => 'sec', 'refresh_token' => 'rt', 'api_version' => 'v18',
        ]]);
    }

    private function meta(array $over = []): void
    {
        config(['services.ads.meta' => $over + [
            'token' => 'tok', 'ad_account_id' => 'act_1', 'page_id' => '99', 'api_version' => 'v21.0',
        ]]);
    }

    public function test_status_names_the_missing_env_keys_and_never_their_values(): void
    {
        config(['services.ads.google' => ['developer_token' => '', 'customer_id' => '1234567890',
            'client_id' => 'cid', 'client_secret' => 'top-secret', 'refresh_token' => 'rt']]);
        config(['services.ads.meta' => ['token' => 'tok', 'ad_account_id' => '', 'page_id' => '']]);

        $status = app(PublisherRegistry::class)->status();

        $this->assertFalse($status['google']['configured']);
        $this->assertSame(['GOOGLE_ADS_DEVELOPER_TOKEN'], $status['google']['missing']);
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

    public function test_an_unapproved_developer_token_is_reported_not_swallowed(): void
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
            'graph.facebook.com/v21.0/act_1*' => Http::response(['name' => 'Codemenschen Ads', 'currency' => 'EUR', 'account_status' => 1]),
            'graph.facebook.com/v21.0/99*' => Http::response(['name' => 'Appwerk']),
        ]);

        $v = app(MetaAdsPublisher::class)->verify();

        $this->assertTrue($v['ok']);
        $this->assertSame('Codemenschen Ads (EUR), Seite: Appwerk', $v['account']);
    }

    public function test_a_disabled_meta_account_is_not_ok(): void
    {
        $this->meta();
        Http::fake([
            'graph.facebook.com/v21.0/act_1*' => Http::response(['name' => 'X', 'currency' => 'EUR', 'account_status' => 2]),
            'graph.facebook.com/v21.0/99*' => Http::response(['name' => 'Appwerk']),
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
}
