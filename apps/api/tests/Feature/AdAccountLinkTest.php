<?php

namespace Tests\Feature;

use App\Domain\Ads\AdSettings;
use App\Domain\Ads\AdTarget;
use App\Models\AdAccountLink;
use App\Models\Customer;
use App\Models\MarketingCampaign;
use App\Models\Order;
use App\Models\Project;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Connecting a customer's own ad account: one paste, one click on the platform.
 *
 * What matters here is that a link is only ever called active because the platform said so, and
 * that a customer never sees or touches another customer's account.
 */
class AdAccountLinkTest extends TestCase
{
    use RefreshDatabase;

    private function google(array $over = []): void
    {
        config(['services.ads.google' => $over + [
            'developer_token' => '', 'customer_id' => '1234567890', 'login_customer_id' => '',
            'manager_id' => '9637225111', 'client_id' => 'cid', 'client_secret' => 'sec',
            'refresh_token' => 'rt', 'api_version' => 'v25',
        ]]);
    }

    private function meta(array $over = []): void
    {
        config(['services.ads.meta' => $over + [
            'token' => 'tok', 'ad_account_id' => 'act_1', 'page_id' => '99',
            'business_id' => '55501', 'api_version' => 'v26.0',
        ]]);
    }

    /** A project with an owner, the shortest real chain: quote, order, project. */
    private function projectOf(Customer $customer): Project
    {
        $quote = Quote::create(['customer_id' => $customer->id, 'breakdown' => [],
            'price_eur' => 1000, 'app_type' => 'A', 'valid_until' => now()->addWeek()]);
        $order = Order::create(['customer_id' => $customer->id, 'quote_id' => $quote->id,
            'packages' => [], 'total_one_time_eur' => 1000, 'status' => 'paid']);

        return Project::create(['customer_id' => $customer->id, 'order_id' => $order->id,
            'name' => 'Huber', 'status' => 'live']);
    }

    public function test_a_google_account_is_asked_to_link_and_stays_pending_until_the_customer_accepts(): void
    {
        $this->google();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at']),
            'googleads.googleapis.com/*/customerClientLinks:mutate' => Http::response([
                'result' => ['resourceName' => 'customers/9637225111/customerClientLinks/3520913982~77'],
            ]),
            'googleads.googleapis.com/*' => Http::response(['results' => [
                ['customerClientLink' => ['status' => 'PENDING', 'managerLinkId' => '77']],
            ]]),
        ]);
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);

        $res = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/me/ad-accounts', ['platform' => 'google', 'external_id' => '352-091-3982'])
            ->assertCreated();

        $res->assertJsonPath('account.status', 'pending');
        $res->assertJsonPath('account.external_id', '352-091-3982');
        $this->assertSame('77', AdAccountLink::first()->manager_link_id);
        // The request goes out from the manager account, whatever the campaign customer id says.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'customers/9637225111/customerClientLinks:mutate')
            && $r['operation']['create']['clientCustomer'] === 'customers/3520913982'
            && $r['operation']['create']['status'] === 'PENDING'
            && $r->header('login-customer-id')[0] === '9637225111');
    }

    public function test_the_link_turns_active_only_when_google_says_so(): void
    {
        $this->google();
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        $link = AdAccountLink::create(['customer_id' => $customer->id, 'platform' => 'google',
            'external_id' => '3520913982', 'status' => 'pending']);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at']),
            'googleads.googleapis.com/*' => Http::sequence()
                ->push(['results' => [['customerClientLink' => ['status' => 'ACTIVE']]]])
                ->push(['results' => [['customer' => ['descriptiveName' => 'Bäckerei Huber', 'currencyCode' => 'EUR']]]]),
        ]);

        $this->actingAs($customer, 'sanctum')->postJson("/api/me/ad-accounts/{$link->id}/refresh")
            ->assertOk()
            ->assertJsonPath('account.status', 'active')
            ->assertJsonPath('account.name', 'Bäckerei Huber (EUR)');
        $this->assertNotNull($link->fresh()->activated_at);
    }

    public function test_a_meta_account_counts_as_linked_when_meta_lets_us_read_it(): void
    {
        $this->meta();
        Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Huber Ads', 'currency' => 'EUR'])]);
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/me/ad-accounts', ['platform' => 'meta', 'external_id' => '1234567890'])
            ->assertCreated()
            ->assertJsonPath('account.status', 'active')
            ->assertJsonPath('account.external_id', 'act_1234567890');
    }

    public function test_meta_stays_pending_while_the_customer_has_not_granted_access(): void
    {
        $this->meta();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'no permission']], 403)]);
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/me/ad-accounts', ['platform' => 'meta', 'external_id' => 'act_1234567890'])
            ->assertCreated()
            ->assertJsonPath('account.status', 'pending');
    }

    public function test_a_wrong_number_is_refused_before_any_call_is_made(): void
    {
        $this->google();
        Http::fake();
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/me/ad-accounts', ['platform' => 'google', 'external_id' => '12345'])
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_a_customer_sees_our_ids_and_only_their_own_accounts(): void
    {
        $this->google();
        $this->meta();
        $mine = Customer::create(['email' => 'mine@example.com', 'locale' => 'de']);
        $theirs = Customer::create(['email' => 'theirs@example.com', 'locale' => 'de']);
        AdAccountLink::create(['customer_id' => $mine->id, 'platform' => 'google', 'external_id' => '1111111111', 'status' => 'active']);
        $other = AdAccountLink::create(['customer_id' => $theirs->id, 'platform' => 'google', 'external_id' => '2222222222', 'status' => 'active']);

        $res = $this->actingAs($mine, 'sanctum')->getJson('/api/me/ad-accounts')->assertOk();
        $res->assertJsonPath('ours.google_manager_id', '963-722-5111');
        $res->assertJsonPath('ours.meta_business_id', '55501');
        $res->assertJsonCount(1, 'accounts');

        $this->actingAs($mine, 'sanctum')->postJson("/api/me/ad-accounts/{$other->id}/refresh")->assertNotFound();
        $this->actingAs($mine, 'sanctum')->deleteJson("/api/me/ad-accounts/{$other->id}")->assertNotFound();
        $this->assertNotNull($other->fresh());
    }

    public function test_a_clients_campaign_runs_on_the_clients_account_and_page(): void
    {
        $this->meta();
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        AdAccountLink::create(['customer_id' => $customer->id, 'platform' => 'meta',
            'external_id' => 'act_777', 'page_id' => '4242', 'status' => 'active']);
        $project = $this->projectOf($customer);
        $campaign = MarketingCampaign::create(['project_id' => $project->id, 'platform' => 'meta', 'status' => 'approved', 'strategy' => []]);

        $target = AdTarget::for($campaign, 'meta');

        $this->assertSame('client', $target['owner']);
        $this->assertSame('act_777', $target['account']);
        $this->assertSame('4242', $target['page']);
    }

    public function test_without_a_connected_account_the_campaign_stays_on_ours(): void
    {
        $this->meta(['ad_account_id' => 'act_1', 'page_id' => '99']);
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        $project = $this->projectOf($customer);
        $campaign = MarketingCampaign::create(['project_id' => $project->id, 'platform' => 'meta', 'status' => 'approved', 'strategy' => []]);

        $target = AdTarget::for($campaign, 'meta');

        $this->assertSame('appwerk', $target['owner']);
        $this->assertSame('act_1', $target['account']);
    }

    public function test_a_connected_meta_account_without_a_page_refuses_to_run(): void
    {
        $this->meta();
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        AdAccountLink::create(['customer_id' => $customer->id, 'platform' => 'meta', 'external_id' => 'act_777', 'status' => 'active']);
        $project = $this->projectOf($customer);
        $campaign = MarketingCampaign::create(['project_id' => $project->id, 'platform' => 'meta', 'status' => 'approved', 'strategy' => []]);

        $this->expectExceptionMessage('Facebook page');
        AdTarget::for($campaign, 'meta');
    }

    public function test_a_google_client_account_is_reached_through_our_manager(): void
    {
        $this->google();
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        AdAccountLink::create(['customer_id' => $customer->id, 'platform' => 'google', 'external_id' => '5550001111', 'status' => 'active']);
        $project = $this->projectOf($customer);
        $campaign = MarketingCampaign::create(['project_id' => $project->id, 'platform' => 'google', 'status' => 'approved', 'strategy' => []]);

        $target = AdTarget::for($campaign, 'google');

        $this->assertSame('5550001111', $target['account']);
        $this->assertSame('9637225111', $target['login'], 'a customer account is never its own login-customer-id');
    }

    public function test_an_admin_number_wins_over_the_server_env_and_an_empty_field_clears_it(): void
    {
        $this->meta(['ad_account_id' => 'act_env']);
        $admin = Customer::create(['email' => 'chef@example.com', 'locale' => 'de', 'is_admin' => true]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/ads/settings', ['meta_ad_account_id' => 'act_panel'])
            ->assertOk()->assertJsonPath('settings.meta_ad_account_id.value', 'act_panel')
            ->assertJsonPath('settings.meta_ad_account_id.source', 'panel');
        $this->assertSame('act_panel', AdSettings::get('meta_ad_account_id'));

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/ads/settings', ['meta_ad_account_id' => ''])
            ->assertOk()->assertJsonPath('settings.meta_ad_account_id.value', 'act_env')
            ->assertJsonPath('settings.meta_ad_account_id.source', 'env');
    }

    public function test_only_an_admin_reads_or_writes_our_numbers(): void
    {
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        $this->actingAs($customer, 'sanctum')->getJson('/api/admin/ads/settings')->assertForbidden();
        $this->actingAs($customer, 'sanctum')->postJson('/api/admin/ads/settings', ['meta_page_id' => '1'])->assertForbidden();
    }
}
