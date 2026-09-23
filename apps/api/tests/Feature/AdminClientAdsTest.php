<?php

namespace Tests\Feature;

use App\Models\AdAccountLink;
use App\Models\Customer;
use App\Models\MarketingCampaign;
use App\Models\Order;
use App\Models\Project;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The screen with every client's advertising.
 *
 * What it must never do is mix Codemenschen's own spend into a client's, or a client's into
 * another's. And it has to put what needs a person at the top.
 */
class AdminClientAdsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Customer
    {
        return Customer::create(['email' => 'chef@example.com', 'locale' => 'de', 'is_admin' => true]);
    }

    private function projectOf(Customer $customer, string $name): Project
    {
        $quote = Quote::create(['customer_id' => $customer->id, 'breakdown' => [],
            'price_eur' => 1000, 'app_type' => 'A', 'valid_until' => now()->addWeek()]);
        $order = Order::create(['customer_id' => $customer->id, 'quote_id' => $quote->id,
            'packages' => [], 'total_one_time_eur' => 1000, 'status' => 'paid']);

        return Project::create(['customer_id' => $customer->id, 'order_id' => $order->id, 'name' => $name, 'status' => 'live']);
    }

    private function campaign(?Project $project, array $over = []): MarketingCampaign
    {
        return MarketingCampaign::create($over + ['project_id' => $project?->id, 'platform' => 'google',
            'status' => 'approved', 'platform_status' => 'paused', 'strategy' => [], 'ad_budget_monthly_eur' => 300]);
    }

    public function test_each_client_sees_only_their_own_spend_and_ours_is_left_out(): void
    {
        $huber = Customer::create(['email' => 'huber@example.com', 'locale' => 'de']);
        $lang = Customer::create(['email' => 'lang@example.com', 'locale' => 'de']);
        $this->campaign($this->projectOf($huber, 'Huber Dach'), ['platform_status' => 'active', 'spent_eur' => 40, 'spent_today_eur' => 5, 'spend_checked_at' => now()]);
        $this->campaign($this->projectOf($lang, 'Lang Bäckerei'), ['spent_eur' => 12]);
        // Appwerk advertising itself: no project, so not a client's.
        $this->campaign(null, ['platform_status' => 'active', 'spent_eur' => 900, 'spent_today_eur' => 90, 'spend_checked_at' => now()]);

        $res = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/client-ads')->assertOk();

        $res->assertJsonPath('totals.clients', 2);
        $res->assertJsonPath('totals.campaigns', 2);
        $res->assertJsonPath('totals.running', 1);
        $this->assertEquals(52, $res->json('totals.spent_eur'));
        $this->assertEquals(5, $res->json('totals.spent_today_eur'));
        $byEmail = collect($res->json('clients'))->keyBy('email');
        $this->assertEquals(40, $byEmail['huber@example.com']['spent_eur']);
        $this->assertEquals(12, $byEmail['lang@example.com']['spent_eur']);
    }

    public function test_it_says_whose_account_a_campaign_runs_on(): void
    {
        $huber = Customer::create(['email' => 'huber@example.com', 'locale' => 'de']);
        $lang = Customer::create(['email' => 'lang@example.com', 'locale' => 'de']);
        AdAccountLink::create(['customer_id' => $huber->id, 'platform' => 'google', 'external_id' => '3520913982', 'status' => 'active']);
        // Connected for Meta only, so a Google campaign still runs on ours.
        AdAccountLink::create(['customer_id' => $lang->id, 'platform' => 'meta', 'external_id' => 'act_1', 'page_id' => '9', 'status' => 'active']);
        $this->campaign($this->projectOf($huber, 'Huber'));
        $this->campaign($this->projectOf($lang, 'Lang'));

        $res = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/client-ads')->assertOk();

        $byEmail = collect($res->json('clients'))->keyBy('email');
        $this->assertSame('client', $byEmail['huber@example.com']['campaigns'][0]['runs_on']);
        $this->assertSame('352-091-3982', $byEmail['huber@example.com']['campaigns'][0]['account']);
        $this->assertSame('appwerk', $byEmail['lang@example.com']['campaigns'][0]['runs_on']);
    }

    public function test_what_needs_a_person_comes_first(): void
    {
        $quiet = Customer::create(['email' => 'quiet@example.com', 'locale' => 'de']);
        $broken = Customer::create(['email' => 'broken@example.com', 'locale' => 'de']);
        $stale = Customer::create(['email' => 'stale@example.com', 'locale' => 'de']);
        $this->campaign($this->projectOf($quiet, 'Quiet'), ['spent_eur' => 500]);
        $this->campaign($this->projectOf($broken, 'Broken'), ['platform_status' => 'failed', 'publish_error' => 'Google Ads API: bad headline']);
        // Running, but nobody has read its spend in three hours.
        $this->campaign($this->projectOf($stale, 'Stale'), ['platform_status' => 'active', 'spend_checked_at' => now()->subHours(3)]);
        // A client who only connected an account, which Meta then refused.
        $refused = Customer::create(['email' => 'refused@example.com', 'locale' => 'de']);
        AdAccountLink::create(['customer_id' => $refused->id, 'platform' => 'meta', 'external_id' => 'act_2', 'status' => 'refused']);

        $res = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/client-ads')->assertOk();

        $order = collect($res->json('clients'))->pluck('email')->all();
        $this->assertSame('quiet@example.com', end($order));
        $this->assertContains('refused@example.com', $order);
        $this->assertSame(3, $res->json('totals.problems'));
        $broken = collect($res->json('clients'))->firstWhere('email', 'broken@example.com')['campaigns'][0]['problems'][0];
        $this->assertSame('publish_failed', $broken['code']);
        // The platform's own words travel untouched: they are what somebody will search for.
        $this->assertStringContainsString('bad headline', $broken['detail']);
        $this->assertSame(1, $res->json('totals.accounts.refused'));
    }

    public function test_the_screen_is_closed_to_a_customer(): void
    {
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        $link = AdAccountLink::create(['customer_id' => $customer->id, 'platform' => 'google', 'external_id' => '1234567890', 'status' => 'pending']);

        $this->actingAs($customer, 'sanctum')->getJson('/api/admin/client-ads')->assertForbidden();
        $this->actingAs($customer, 'sanctum')->postJson("/api/admin/ad-accounts/{$link->id}/refresh")->assertForbidden();
    }
}
