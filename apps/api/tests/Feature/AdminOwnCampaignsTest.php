<?php

namespace Tests\Feature;

use App\Domain\Ads\Preflight;
use App\Domain\Ads\PublisherRegistry;
use App\Jobs\PublishCampaign;
use App\Models\CampaignKeyword;
use App\Models\Customer;
use App\Models\MarketingCampaign;
use App\Models\Order;
use App\Models\Project;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Appwerk's own campaigns. The lines defended: a customer never reaches them, a customer's
 * campaign never shows up among them, and nothing goes to Google that could not serve.
 */
class AdminOwnCampaignsTest extends TestCase
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

    /** @return array<string,mixed> */
    private function form(array $over = []): array
    {
        return $over + [
            'name' => 'Appwerk Website AT',
            'language' => 'de',
            'countries' => ['AT'],
            'landing_url' => 'https://appwerk.codemenschen.at',
            'message' => 'Appwerk baut kleinen Betrieben eine Website.',
            'daily_eur' => 10,
            'spend_cap_eur' => 100,
            'headlines' => ['Website in Minuten', 'Entwurf gratis ansehen', 'Festpreis für Betriebe'],
            'descriptions' => ['Ein Satz genügt. Sie sehen den Entwurf sofort.', 'Danach ein Festpreis ohne Abo.'],
        ];
    }

    public function test_an_admin_creates_a_campaign_with_its_text_and_day_budget(): void
    {
        $res = $this->actingAs($this->admin(), 'sanctum')->postJson('/api/admin/own-campaigns', $this->form())->assertCreated();

        $res->assertJsonPath('name', 'Appwerk Website AT')
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('editable', true)
            ->assertJsonPath('countries', ['AT'])
            ->assertJsonCount(3, 'headlines')
            ->assertJsonCount(2, 'descriptions');
        $campaign = MarketingCampaign::first();
        $this->assertNull($campaign->project_id);
        $this->assertSame(10.0, $campaign->dailyEur());
        $this->assertSame(100, $campaign->spend_cap_eur);
    }

    public function test_the_list_holds_only_our_own_campaigns(): void
    {
        $owner = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        $quote = Quote::create(['customer_id' => $owner->id, 'breakdown' => [],
            'price_eur' => 1000, 'app_type' => 'A', 'valid_until' => now()->addWeek()]);
        $order = Order::create(['customer_id' => $owner->id, 'quote_id' => $quote->id,
            'packages' => [], 'total_one_time_eur' => 1000, 'status' => 'paid']);
        $project = Project::create(['customer_id' => $owner->id, 'order_id' => $order->id, 'name' => 'Dachdecker', 'status' => 'live']);
        MarketingCampaign::create(['project_id' => $project->id, 'platform' => 'google', 'strategy' => [], 'platform_status' => 'paused']);
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/own-campaigns', $this->form())->assertCreated();

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/own-campaigns')
            ->assertOk()->assertJsonCount(1, 'campaigns')->assertJsonPath('campaigns.0.name', 'Appwerk Website AT');
    }

    public function test_publishing_is_refused_without_keywords_and_queued_with_them(): void
    {
        $this->google();
        Queue::fake();
        $admin = $this->admin();
        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/own-campaigns', $this->form())->json('id');

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/own-campaigns/$id/publish")->assertStatus(422);
        Queue::assertNothingPushed();

        CampaignKeyword::create(['campaign_id' => $id, 'text' => 'website erstellen lassen', 'match_type' => 'phrase',
            'negative' => false, 'status' => 'approved', 'source' => 'admin']);
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/own-campaigns/$id/publish")->assertStatus(202)->assertJsonPath('status', 'publishing');
        Queue::assertPushed(PublishCampaign::class);

        // Once it is on its way, the text is fixed.
        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/own-campaigns/$id", $this->form(['name' => 'Anders']))->assertStatus(409);
    }

    public function test_google_gets_the_countries_the_language_and_the_day_budget(): void
    {
        $this->google();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'googleads.googleapis.com/*' => Http::response(['mutateOperationResponses' => [
                ['campaignBudgetResult' => ['resourceName' => 'customers/1234567890/campaignBudgets/1']],
                ['campaignResult' => ['resourceName' => 'customers/1234567890/campaigns/2']],
                ['adGroupResult' => ['resourceName' => 'customers/1234567890/adGroups/3']],
                ['adGroupAdResult' => ['resourceName' => 'customers/1234567890/adGroupAds/3~4']],
                ['adGroupCriterionResult' => ['resourceName' => 'customers/1234567890/adGroupCriteria/3~5']],
                [], [], [],
            ]]),
        ]);
        $admin = $this->admin();
        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/own-campaigns', $this->form(['countries' => ['AT', 'DE']]))->json('id');
        CampaignKeyword::create(['campaign_id' => $id, 'text' => 'website erstellen lassen', 'match_type' => 'phrase',
            'negative' => false, 'status' => 'approved', 'source' => 'admin']);
        MarketingCampaign::find($id)->update(['platform_status' => 'publishing']);

        (new PublishCampaign($id))->handle(app(PublisherRegistry::class), app(Preflight::class));

        $this->assertSame('paused', MarketingCampaign::find($id)->platform_status);
        $this->assertSame('applied', CampaignKeyword::first()->status);
        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), 'googleAds:mutate')) {
                return false;
            }
            $ops = $r['mutateOperations'];

            return $ops[0]['campaignBudgetOperation']['create']['amountMicros'] === '10000000'
                && $ops[1]['campaignOperation']['create']['geoTargetTypeSetting']['positiveGeoTargetType'] === 'PRESENCE'
                && $ops[5]['campaignCriterionOperation']['create']['location']['geoTargetConstant'] === 'geoTargetConstants/2040'
                && $ops[6]['campaignCriterionOperation']['create']['location']['geoTargetConstant'] === 'geoTargetConstants/2276'
                && $ops[7]['campaignCriterionOperation']['create']['language']['languageConstant'] === 'languageConstants/1001';
        });
    }

    public function test_the_ai_draft_drops_lines_over_googles_limits(): void
    {
        config(['services.ai_image.base_url' => 'https://ai.test', 'services.ai_image.token' => 'tok']);
        Http::fake(['ai.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'headlines' => ['Website in Minuten', 'Diese Zeile ist viel zu lang für Google Ads', 'Entwurf gratis!', 'Festpreis'],
            'descriptions' => ['Ein Satz genügt.', 'Danach ein Festpreis.'],
        ])]]]])]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/own-campaigns/write', ['message' => 'Websites für kleine Betriebe'])
            ->assertOk()
            ->assertJsonPath('headlines', ['Website in Minuten', 'Entwurf gratis', 'Festpreis'])
            ->assertJsonCount(2, 'descriptions');
    }

    public function test_a_customer_cannot_reach_it(): void
    {
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        $this->actingAs($customer, 'sanctum')->getJson('/api/admin/own-campaigns')->assertForbidden();
        $this->actingAs($customer, 'sanctum')->postJson('/api/admin/own-campaigns', $this->form())->assertForbidden();
    }
}
