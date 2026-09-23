<?php

namespace Tests\Feature;

use App\Models\AdAccountLink;
use App\Models\Customer;
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
}
