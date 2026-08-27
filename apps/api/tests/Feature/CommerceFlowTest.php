<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\OrderFulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommerceFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // markPaid may start the pipeline; the worker is faked here.
        Http::fake(['*/run' => Http::response(['accepted' => true], 202)]);
    }

    private function makeQuote(array $overrides = []): string
    {
        $res = $this->postJson('/api/quotes', $overrides + [
            'idea' => 'A booking app for my dance school',
            'audience' => 'b2b',
            'platform' => 'mobile',
            'features' => ['auth', 'notif'],
            'locale' => 'de',
        ]);
        $res->assertCreated();

        return $res->json('id');
    }

    public function test_custom_quote_is_priced_server_side(): void
    {
        $res = $this->postJson('/api/quotes', [
            'idea' => 'x',
            'audience' => 'consumer',
            'platform' => 'web',
            'features' => [],
        ]);
        $res->assertCreated()
            ->assertJsonPath('price_eur', 1600)
            ->assertJsonPath('app_type', 'A')
            ->assertJsonPath('hosting_monthly_eur', 0);
    }

    public function test_listing_quote_uses_catalog_price(): void
    {
        $res = $this->postJson('/api/quotes', ['listing_slug' => 'countbee']);
        $res->assertCreated()
            ->assertJsonPath('price_eur', 300)
            ->assertJsonPath('app_type', 'A');
    }

    public function test_unknown_listing_is_rejected(): void
    {
        $this->postJson('/api/quotes', ['listing_slug' => 'nope'])->assertNotFound();
    }

    public function test_checkout_without_stripe_creates_order_and_reports_staging(): void
    {
        config(['services.stripe.secret' => null]);
        $quoteId = $this->makeQuote();

        $res = $this->postJson('/api/checkout', [
            'quote_id' => $quoteId,
            'email' => 'patrick@example.com',
            'packages' => ['storePublishing' => true],
            'ad_budget_monthly_eur' => 500,
            'fagg_waiver' => false,
        ]);
        $res->assertStatus(503)->assertJsonPath('payment', 'unconfigured');

        $order = Order::firstOrFail();
        $this->assertSame('pending', $order->status);
        $this->assertFalse($order->fagg_waiver);
        $this->assertNull($order->fagg_waiver_at);
        // b2b mobile with auth+notif: (1200+400)*1.15=1840 → price 3200 + 300 package
        $this->assertSame(3500, $order->total_one_time_eur);
        $this->assertSame(19, $order->hosting_monthly_eur);
    }

    public function test_fulfillment_creates_project_and_defers_build_without_waiver(): void
    {
        config(['services.stripe.secret' => null]);
        $quoteId = $this->makeQuote();
        $this->postJson('/api/checkout', [
            'quote_id' => $quoteId,
            'email' => 'patrick@example.com',
            'fagg_waiver' => false,
        ])->assertStatus(503);

        $order = Order::firstOrFail();
        $project = app(OrderFulfillment::class)->markPaid($order, 'pi_test', 2900, ['type' => 'test']);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('converted', $order->quote->fresh()->status);
        $this->assertSame('PAID', $project->status);
        $this->assertSame('expo', $project->stack);
        // No waiver → withdrawal period respected: build starts in ~14 days.
        $this->assertTrue($project->build_starts_at->greaterThan(now()->addDays(13)));
        $this->assertDatabaseHas('project_events', ['project_id' => $project->id, 'type' => 'project.created']);

        // A quote can only be converted once.
        $this->postJson('/api/checkout', [
            'quote_id' => $quoteId,
            'email' => 'patrick@example.com',
            'fagg_waiver' => true,
        ])->assertStatus(409);
    }

    public function test_immediate_start_waiver_is_recorded_with_timestamp(): void
    {
        config(['services.stripe.secret' => null]);
        $quoteId = $this->makeQuote();
        $this->postJson('/api/checkout', [
            'quote_id' => $quoteId,
            'email' => 'p2@example.com',
            'fagg_waiver' => true,
        ])->assertStatus(503);

        $order = Order::firstOrFail();
        $this->assertTrue($order->fagg_waiver);
        $this->assertNotNull($order->fagg_waiver_at);
        $this->assertNotNull($order->fagg_waiver_ip);

        $project = app(OrderFulfillment::class)->markPaid($order, 'pi_x', 2900, []);
        $this->assertTrue($project->build_starts_at->lessThan(now()->addDay()));
    }

    public function test_portal_requires_auth_and_returns_projects(): void
    {
        config(['services.stripe.secret' => null]);
        $this->getJson('/api/me/projects')->assertUnauthorized();

        $quoteId = $this->makeQuote();
        $this->postJson('/api/checkout', [
            'quote_id' => $quoteId, 'email' => 'p3@example.com', 'fagg_waiver' => true,
        ]);
        $order = Order::firstOrFail();
        // No explicit choice → every supported store-listing language.
        $this->assertSame(Order::SUPPORTED_STORE_LOCALES, $order->store_locales);
        app(OrderFulfillment::class)->markPaid($order, 'pi_y', 2900, []);

        $token = $order->customer->createToken('portal')->plainTextToken;
        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/me/projects')
            ->assertOk()
            ->assertJsonPath('email', 'p3@example.com')
            // waiver=true → the pipeline starts immediately after payment
            ->assertJsonPath('projects.0.status', 'SPECIFICATION');
    }

    public function test_checkout_stores_the_chosen_store_listing_languages(): void
    {
        config(['services.stripe.secret' => null]);
        $quote = fn () => $this->postJson('/api/quotes', ['listing_slug' => 'countbee', 'locale' => 'de'])->json('id');

        $this->postJson('/api/checkout', [
            'quote_id' => $quote(), 'email' => 'loc@example.com', 'fagg_waiver' => false, 'store_locales' => ['de', 'de'],
        ])->assertStatus(503);
        $this->assertSame(['de'], Order::latest('created_at')->firstOrFail()->store_locales);

        $this->postJson('/api/checkout', [
            'quote_id' => $quote(), 'email' => 'loc@example.com', 'fagg_waiver' => false, 'store_locales' => ['fr'],
        ])->assertUnprocessable();
        $this->postJson('/api/checkout', [
            'quote_id' => $quote(), 'email' => 'loc@example.com', 'fagg_waiver' => false, 'store_locales' => [],
        ])->assertUnprocessable();
    }
}
