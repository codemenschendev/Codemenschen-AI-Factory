<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\Customer;
use App\Models\MarketingCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The traffic screen: Google's reports, read through the API, so nobody needs a Google login. */
class AdminTrafficTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Customer
    {
        return Customer::create(['email' => 'chef@example.com', 'locale' => 'de', 'is_admin' => true]);
    }

    private function google(): void
    {
        config(['services.ads.google' => [
            'developer_token' => '', 'customer_id' => '1234567890', 'login_customer_id' => '',
            'manager_id' => '', 'client_id' => 'cid', 'client_secret' => 'sec',
            'refresh_token' => 'rt', 'api_version' => 'v25', 'service_account_json' => '',
        ]]);
    }

    private function published(): MarketingCampaign
    {
        return MarketingCampaign::create(['platform' => 'google', 'platform_status' => 'active',
            'strategy' => ['name' => 'Websites AT'], 'published_at' => now(),
            'platform_ref' => ['campaign_id' => 'customers/1234567890/campaigns/77']]);
    }

    /** One answer per report, picked by what the query reads. */
    private function fakeGoogle(): void
    {
        $today = now()->toDateString();
        Http::fake(function (Request $r) use ($today) {
            if (str_contains($r->url(), 'oauth2')) {
                return Http::response(['access_token' => 'at', 'expires_in' => 3600]);
            }
            $q = (string) $r['query'];
            $m = fn (int $i, int $c, int $micros) => ['impressions' => (string) $i, 'clicks' => (string) $c, 'costMicros' => (string) $micros, 'conversions' => 0];

            $rows = match (true) {
                str_contains($q, 'keyword_view') => [
                    ['adGroupCriterion' => ['keyword' => ['text' => 'website erstellen', 'matchType' => 'PHRASE'], 'status' => 'ENABLED', 'qualityInfo' => ['qualityScore' => 7]], 'metrics' => $m(300, 12, 9_000_000)],
                ],
                str_contains($q, 'search_term_view') => [
                    ['searchTermView' => ['searchTerm' => 'website erstellen lassen graz', 'status' => 'NONE'], 'metrics' => $m(40, 3, 2_000_000)],
                ],
                str_contains($q, 'segments.device') => [
                    ['segments' => ['device' => 'MOBILE'], 'metrics' => $m(250, 10, 7_000_000)],
                    ['segments' => ['device' => 'DESKTOP'], 'metrics' => $m(50, 2, 2_000_000)],
                ],
                str_contains($q, 'geographic_view') => [
                    ['geographicView' => ['countryCriterionId' => '2040', 'locationType' => 'LOCATION_OF_PRESENCE'], 'metrics' => $m(300, 12, 9_000_000)],
                ],
                str_contains($q, 'segments.hour') => [
                    ['segments' => ['hour' => 9], 'metrics' => $m(100, 5, 3_000_000)],
                ],
                default => [
                    ['segments' => ['date' => $today], 'metrics' => $m(300, 12, 9_000_000)],
                ],
            };

            return Http::response(['results' => $rows]);
        });
    }

    public function test_the_console_shows_googles_numbers_without_a_google_login(): void
    {
        $this->google();
        $this->fakeGoogle();
        $campaign = $this->published();
        AnalyticsEvent::create(['name' => 'page_view', 'visitor' => 'v1', 'utm_campaign' => $campaign->trackingTag(), 'created_at' => now()]);
        AnalyticsEvent::create(['name' => 'page_view', 'visitor' => 'v2', 'utm_campaign' => $campaign->trackingTag(), 'created_at' => now()]);

        $res = $this->actingAs($this->admin(), 'sanctum')->getJson("/api/admin/marketing/{$campaign->id}/traffic?days=7")->assertOk();

        $res->assertJsonPath('totals.impressions', 300)
            ->assertJsonPath('totals.clicks', 12)
            ->assertJsonPath('totals.cost_eur', 9)
            ->assertJsonPath('totals.ctr', 4)
            ->assertJsonPath('totals.cpc_eur', 0.75)
            ->assertJsonPath('totals.visits', 2)
            ->assertJsonCount(7, 'daily')
            ->assertJsonPath('daily.6.clicks', 12)
            ->assertJsonPath('daily.6.visits', 2)
            ->assertJsonPath('keywords.0.text', 'website erstellen')
            ->assertJsonPath('keywords.0.quality', 7)
            ->assertJsonPath('search_terms.0.term', 'website erstellen lassen graz')
            ->assertJsonPath('devices.0.key', 'mobile')
            ->assertJsonPath('countries.0.key', 'AT')
            ->assertJsonPath('hours.0.key', '9');

        // Every query is read-only and scoped to this campaign.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'googleAds:search')
            && str_contains((string) $r['query'], "campaign.resource_name = 'customers/1234567890/campaigns/77'"));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'mutate'));
    }

    public function test_a_campaign_not_on_google_has_nothing_to_read(): void
    {
        $this->google();
        $campaign = MarketingCampaign::create(['platform' => 'google', 'platform_status' => 'draft', 'strategy' => []]);

        $this->actingAs($this->admin(), 'sanctum')->getJson("/api/admin/marketing/{$campaign->id}/traffic")->assertStatus(422);
    }

    public function test_a_customer_cannot_read_it(): void
    {
        $campaign = $this->published();
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);

        $this->actingAs($customer, 'sanctum')->getJson("/api/admin/marketing/{$campaign->id}/traffic")->assertForbidden();
    }
}
