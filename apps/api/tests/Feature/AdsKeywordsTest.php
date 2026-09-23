<?php

namespace Tests\Feature;

use App\Domain\Ads\Preflight;
use App\Domain\Ads\PublisherRegistry;
use App\Jobs\PublishCampaign;
use App\Models\CampaignKeyword;
use App\Models\Customer;
use App\Models\MarketingCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The words a search campaign is bought for.
 *
 * The line this suite defends: the AI proposes and a person decides. Nothing reaches Google that
 * an admin did not tick, and a keyword that already ran is never quietly deleted.
 */
class AdsKeywordsTest extends TestCase
{
    use RefreshDatabase;

    private function google(): void
    {
        config(['services.ads.google' => [
            'developer_token' => '', 'customer_id' => '1234567890', 'login_customer_id' => '',
            'manager_id' => '', 'client_id' => 'cid', 'client_secret' => 'sec',
            'refresh_token' => 'rt', 'api_version' => 'v25', 'service_account_json' => '',
        ]]);
        config(['services.ai_image.base_url' => 'https://ai.test', 'services.ai_image.token' => 'tok']);
    }

    private function admin(): Customer
    {
        return Customer::create(['email' => 'chef@example.com', 'locale' => 'de', 'is_admin' => true]);
    }

    private function campaign(array $over = []): MarketingCampaign
    {
        $c = MarketingCampaign::create($over + ['platform' => 'google', 'status' => 'approved',
            'platform_status' => 'draft', 'strategy' => ['message' => 'Dachreparatur in Graz', 'landing_url' => 'https://appwerk.test/l/1'],
            'ad_budget_monthly_eur' => 300]);
        $c->creatives()->createMany([
            ['kind' => 'headline', 'content' => 'Dach undicht'],
            ['kind' => 'headline', 'content' => 'Reparatur in 48h'],
            ['kind' => 'headline', 'content' => 'Festpreis'],
            ['kind' => 'ad_copy', 'content' => 'Wir reparieren Ihr Dach.'],
            ['kind' => 'ad_copy', 'content' => 'Termin diese Woche.'],
        ]);

        return $c;
    }

    public function test_the_ai_proposes_and_approves_nothing(): void
    {
        $this->google();
        Http::fake(['ai.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'keywords' => [
                ['text' => 'dachreparatur graz', 'match' => 'phrase'],
                ['text' => 'dach undicht hilfe', 'match' => 'exact'],
                // Broad is never accepted, whatever the model answers.
                ['text' => 'dachdecker notdienst', 'match' => 'broad'],
            ],
            'negatives' => ['dachreparatur kostenlos', 'dachdecker job'],
        ])]]]])]);
        $campaign = $this->campaign();

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/admin/marketing/{$campaign->id}/keywords/suggest")
            ->assertOk();

        $res->assertJsonPath('proposed', 5);
        $this->assertSame(5, CampaignKeyword::where('status', 'proposed')->count());
        $this->assertSame(0, CampaignKeyword::where('status', 'approved')->count());
        $this->assertSame('phrase', CampaignKeyword::where('text', 'dachdecker notdienst')->first()->match_type);
        $this->assertSame('exact', CampaignKeyword::where('text', 'dach undicht hilfe')->first()->match_type);
        $this->assertTrue(CampaignKeyword::where('text', 'dachdecker job')->first()->negative);
    }

    public function test_a_second_suggestion_leaves_a_decision_alone(): void
    {
        $this->google();
        Http::fake(['ai.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'keywords' => [['text' => 'dachreparatur graz', 'match' => 'phrase']], 'negatives' => [],
        ])]]]])]);
        $campaign = $this->campaign();
        CampaignKeyword::create(['campaign_id' => $campaign->id, 'text' => 'dachreparatur graz',
            'match_type' => 'exact', 'status' => 'approved', 'source' => 'admin']);

        $res = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/admin/marketing/{$campaign->id}/keywords/suggest")->assertOk();

        $res->assertJsonPath('skipped', 1);
        $row = CampaignKeyword::where('text', 'dachreparatur graz')->first();
        $this->assertSame('approved', $row->status);
        $this->assertSame('exact', $row->match_type);
    }

    public function test_apply_sends_only_what_an_admin_approved(): void
    {
        $this->google();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at']),
            'googleads.googleapis.com/*' => Http::response(['mutateOperationResponses' => [
                ['adGroupCriterionResult' => ['resourceName' => 'customers/1234567890/adGroupCriteria/7~1']],
                ['campaignCriterionResult' => ['resourceName' => 'customers/1234567890/campaignCriteria/9~2']],
            ]]),
        ]);
        $campaign = $this->campaign(['platform_ref' => [
            'campaign_id' => 'customers/1234567890/campaigns/9',
            'ad_group' => 'customers/1234567890/adGroups/7',
        ]]);
        CampaignKeyword::create(['campaign_id' => $campaign->id, 'text' => 'dachreparatur graz', 'status' => 'approved']);
        CampaignKeyword::create(['campaign_id' => $campaign->id, 'text' => 'dachdecker job', 'negative' => true, 'status' => 'approved']);
        // Still waiting for a person. It must not travel.
        CampaignKeyword::create(['campaign_id' => $campaign->id, 'text' => 'dachfenster kaufen', 'status' => 'proposed']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/admin/marketing/{$campaign->id}/keywords/apply")
            ->assertOk()
            ->assertJsonPath('applied', 2);

        $this->assertSame('applied', CampaignKeyword::where('text', 'dachreparatur graz')->first()->status);
        $this->assertSame('proposed', CampaignKeyword::where('text', 'dachfenster kaufen')->first()->status);
        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), 'googleAds:mutate')) {
                return false;
            }
            $ops = $r['mutateOperations'];

            return count($ops) === 2
                && $ops[0]['adGroupCriterionOperation']['create']['keyword']['text'] === 'dachreparatur graz'
                && $ops[0]['adGroupCriterionOperation']['create']['keyword']['matchType'] === 'PHRASE'
                && $ops[1]['campaignCriterionOperation']['create']['negative'] === true;
        });
    }

    public function test_a_live_keyword_is_paused_on_google_and_not_deleted_here(): void
    {
        $this->google();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at']),
            'googleads.googleapis.com/*' => Http::response(['mutateOperationResponses' => [[]]]),
        ]);
        $campaign = $this->campaign(['platform_ref' => [
            'campaign_id' => 'customers/1234567890/campaigns/9',
            'ad_group' => 'customers/1234567890/adGroups/7',
        ]]);
        $keyword = CampaignKeyword::create(['campaign_id' => $campaign->id, 'text' => 'dachreparatur graz',
            'status' => 'applied', 'resource_name' => 'customers/1234567890/adGroupCriteria/7~1']);
        $admin = $this->admin();

        // Deleting a live keyword is refused; pausing is the way out.
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/admin/keywords/{$keyword->id}")->assertStatus(422);
        $this->assertDatabaseHas('campaign_keywords', ['id' => $keyword->id]);

        $this->actingAs($admin, 'sanctum')->patchJson("/api/admin/keywords/{$keyword->id}", ['status' => 'paused'])->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/marketing/{$campaign->id}/keywords/apply")
            ->assertOk()->assertJsonPath('paused', 1);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'googleAds:mutate')
            && $r['mutateOperations'][0]['adGroupCriterionOperation']['update']['status'] === 'PAUSED'
            && $r['mutateOperations'][0]['adGroupCriterionOperation']['updateMask'] === 'status');
    }

    public function test_publishing_carries_the_approved_keywords_in_the_same_batch(): void
    {
        $this->google();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at']),
            'googleads.googleapis.com/*' => Http::response(['mutateOperationResponses' => [
                ['campaignBudgetResult' => ['resourceName' => 'b1']],
                ['campaignResult' => ['resourceName' => 'customers/1234567890/campaigns/9']],
                ['adGroupResult' => ['resourceName' => 'customers/1234567890/adGroups/7']],
                ['adGroupAdResult' => ['resourceName' => 'ad1']],
                ['adGroupCriterionResult' => ['resourceName' => 'customers/1234567890/adGroupCriteria/7~1']],
            ]]),
        ]);
        $campaign = $this->campaign(['platform_status' => 'publishing']);
        CampaignKeyword::create(['campaign_id' => $campaign->id, 'text' => 'dachreparatur graz', 'status' => 'approved']);

        (new PublishCampaign($campaign->id))->handle(app(PublisherRegistry::class), app(Preflight::class));

        $this->assertSame('paused', $campaign->fresh()->platform_status);
        $keyword = CampaignKeyword::first();
        $this->assertSame('applied', $keyword->status);
        $this->assertSame('customers/1234567890/adGroupCriteria/7~1', $keyword->resource_name);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'googleAds:mutate')
            && count($r['mutateOperations']) === 5
            && $r['mutateOperations'][4]['adGroupCriterionOperation']['create']['keyword']['text'] === 'dachreparatur graz');
    }

    public function test_a_search_campaign_without_keywords_is_refused_before_it_is_published(): void
    {
        $this->google();
        $problems = app(Preflight::class)->check($this->campaign());

        $this->assertContains('Google: no approved keyword. A search campaign without keywords shows nothing.', $problems);
    }

    public function test_the_keyword_screen_is_closed_to_a_customer(): void
    {
        $this->google();
        $campaign = $this->campaign();
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);

        $this->actingAs($customer, 'sanctum')->getJson('/api/admin/keywords')->assertForbidden();
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/admin/marketing/{$campaign->id}/keywords/suggest")->assertForbidden();
    }
}
