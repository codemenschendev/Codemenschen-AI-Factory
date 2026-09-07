<?php

namespace Tests\Feature;

use App\Mail\CustomerNotice;
use App\Models\Order;
use App\Services\Notify;
use App\Services\OrderFulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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
            ->assertJsonPath('price_eur', 250)
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
            'fagg_waiver' => false, 'terms' => true,
        ]);
        $res->assertStatus(503)->assertJsonPath('payment', 'unconfigured');

        $order = Order::firstOrFail();
        $this->assertSame('pending', $order->status);
        $this->assertFalse($order->fagg_waiver);
        $this->assertNull($order->fagg_waiver_at);
        // b2b mobile with auth+notif: (300+20)*1.15=368 → *1.2 = 441.6 → 450 + 79 package
        $this->assertSame(529, $order->total_one_time_eur);
        $this->assertSame(19, $order->hosting_monthly_eur);
    }

    public function test_an_order_without_accepted_terms_is_refused_and_acceptance_is_recorded(): void
    {
        config(['services.stripe.secret' => null]);
        $quoteId = $this->makeQuote();
        $payload = ['quote_id' => $quoteId, 'email' => 'patrick@example.com', 'fagg_waiver' => false];

        $this->postJson('/api/checkout', $payload)->assertStatus(422)->assertJsonValidationErrors('terms');
        $this->postJson('/api/checkout', $payload + ['terms' => false])->assertStatus(422);
        $this->assertNull(Order::first());

        $this->postJson('/api/checkout', $payload + ['terms' => true])->assertStatus(503);
        $order = Order::firstOrFail();
        $this->assertNotNull($order->terms_accepted_at);
        $this->assertNotNull($order->terms_accepted_ip);
    }

    public function test_fulfillment_creates_project_and_defers_build_without_waiver(): void
    {
        config(['services.stripe.secret' => null]);
        $quoteId = $this->makeQuote();
        $this->postJson('/api/checkout', [
            'quote_id' => $quoteId,
            'email' => 'patrick@example.com',
            'fagg_waiver' => false, 'terms' => true,
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
            'fagg_waiver' => true, 'terms' => true,
        ])->assertStatus(409);
    }

    public function test_immediate_start_waiver_is_recorded_with_timestamp(): void
    {
        config(['services.stripe.secret' => null]);
        $quoteId = $this->makeQuote();
        $this->postJson('/api/checkout', [
            'quote_id' => $quoteId,
            'email' => 'p2@example.com',
            'fagg_waiver' => true, 'terms' => true,
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
            'quote_id' => $quoteId, 'email' => 'p3@example.com', 'fagg_waiver' => true, 'terms' => true,
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
            'quote_id' => $quote(), 'email' => 'loc@example.com', 'fagg_waiver' => false, 'terms' => true, 'store_locales' => ['de', 'de'],
        ])->assertStatus(503);
        $this->assertSame(['de'], Order::latest('created_at')->firstOrFail()->store_locales);

        $this->postJson('/api/checkout', [
            'quote_id' => $quote(), 'email' => 'loc@example.com', 'fagg_waiver' => false, 'terms' => true, 'store_locales' => ['fr'],
        ])->assertUnprocessable();
        $this->postJson('/api/checkout', [
            'quote_id' => $quote(), 'email' => 'loc@example.com', 'fagg_waiver' => false, 'terms' => true, 'store_locales' => [],
        ])->assertUnprocessable();
    }

    public function test_a_paid_customer_hears_from_us_with_a_way_in(): void
    {
        // The success page promised "an e-mail with a sign-in link" from the first order on, and
        // the webhook created the project in silence while the operator got every mail.
        Mail::fake();
        config(['services.stripe.secret' => null]);
        $quoteId = $this->makeQuote();
        $orderId = $this->postJson('/api/checkout', [
            'quote_id' => $quoteId, 'email' => 'patrick@example.com', 'name' => 'Patrick',
            'fagg_waiver' => true, 'terms' => true, 'locale' => 'de',
        ])->json('order_id');

        app(OrderFulfillment::class)->markPaid(Order::find($orderId), 'pi_test', 400, []);

        Mail::assertSent(CustomerNotice::class, function (CustomerNotice $m) {
            return $m->hasTo('patrick@example.com')
                && str_starts_with($m->subjectLine, 'Deine Bestellung bei Appwerk')
                && str_contains($m->body, 'Hallo Patrick,')
                && str_contains($m->body, '/api/auth/verify/')
                && str_contains($m->body, 'Baustart: sofort')
                && ! str_contains($m->body, '\u{2014}') && ! str_contains($m->body, ' \u{2013} ');
        });
    }

    public function test_a_customer_is_told_when_the_preview_is_ready_and_when_it_failed(): void
    {
        Mail::fake();
        config(['services.stripe.secret' => null]);
        $orderId = $this->postJson('/api/checkout', [
            'quote_id' => $this->makeQuote(['locale' => 'en']), 'email' => 'anna@example.com',
            'fagg_waiver' => true, 'terms' => true, 'locale' => 'en',
        ])->json('order_id');
        $project = app(OrderFulfillment::class)->markPaid(Order::find($orderId), 'pi_test', 400, []);

        app(Notify::class)->projectStatus($project, 'TESTING', 'REVIEW');
        app(Notify::class)->projectStatus($project, 'REVIEW', 'READY');
        app(Notify::class)->projectStatus($project, 'BUILDING', 'FAILED');
        app(Notify::class)->projectStatus($project, 'PAID', 'SPECIFICATION');

        $subjects = [];
        Mail::assertSent(CustomerNotice::class, function (CustomerNotice $m) use (&$subjects) {
            if ($m->hasTo('anna@example.com')) {
                $subjects[] = $m->subjectLine;
            }

            return true;
        });
        $this->assertSame(4, count($subjects), 'paid, review, ready, failed; nothing for specification');
        $this->assertStringStartsWith('Your Appwerk order', $subjects[0]);
        $this->assertStringStartsWith('Your preview is ready', $subjects[1]);
        $this->assertStringStartsWith('Approval received', $subjects[2]);
        $this->assertStringStartsWith('We saw a problem', $subjects[3]);
    }

    public function test_project_mails_speak_the_language_of_the_order(): void
    {
        // The customer record said English, the order said German, and the preview mail came in
        // English. The order is what the customer chose this time.
        Mail::fake();
        config(['services.stripe.secret' => null]);
        $orderId = $this->postJson('/api/checkout', [
            'quote_id' => $this->makeQuote(), 'email' => 'lena@example.com', 'fagg_waiver' => true, 'terms' => true, 'locale' => 'de',
        ])->json('order_id');
        $project = app(OrderFulfillment::class)->markPaid(Order::find($orderId), 'pi_test', 400, []);
        $project->customer->update(['locale' => 'en']);

        app(Notify::class)->projectStatus($project->fresh(), 'TESTING', 'REVIEW');

        Mail::assertSent(CustomerNotice::class, fn (CustomerNotice $m) => $m->hasTo('lena@example.com')
            && str_starts_with($m->subjectLine, 'Deine Vorschau ist fertig'));
    }
}
