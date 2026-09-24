<?php

namespace Tests\Feature;

use App\Models\AdAccountLink;
use App\Models\AuditLog;
use App\Models\ChangeMessage;
use App\Models\Customer;
use App\Models\LandingSignup;
use App\Models\Order;
use App\Models\Project;
use App\Models\Prototype;
use App\Models\Quote;
use App\Services\OrderFulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GdprTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret' => null, 'services.worker.token' => 't']);
        Http::fake(['*/run' => Http::response(['accepted' => true], 202)]);
    }

    /** A customer who ordered: quote, order, paid project with a chat line, and a waitlist sign-up elsewhere. */
    private function buyer(): Project
    {
        $quote = $this->postJson('/api/quotes', ['idea' => 'club app', 'audience' => 'b2b', 'platform' => 'mobile', 'features' => []])->json('id');
        $this->postJson('/api/checkout', ['quote_id' => $quote, 'email' => 'Kunde@Example.com', 'fagg_waiver' => true, 'terms' => true]);
        $project = app(OrderFulfillment::class)->markPaid(Order::firstOrFail(), 'pi', 100, []);
        ChangeMessage::create(['project_id' => $project->id, 'role' => 'customer', 'body' => 'Bitte Logo größer', 'author' => 'kunde@example.com']);

        return $project;
    }

    private function signupOn(string $email): void
    {
        $page = Prototype::create(['status' => 'ready', 'kind' => 'site', 'prompt' => 'someone else', 'expires_at' => now()->addDay()]);
        LandingSignup::create(['prototype_id' => $page->id, 'email' => $email, 'consent' => 'ja']);
    }

    public function test_export_holds_the_account_its_orders_chat_and_signups(): void
    {
        $this->buyer();
        $this->signupOn('kunde@example.com');

        Artisan::call('factory:gdpr', ['action' => 'export', 'email' => 'KUNDE@example.com ']);
        $data = json_decode(Artisan::output(), true);

        $this->assertSame('kunde@example.com', $data['account']['email']);
        $this->assertCount(1, $data['orders']);
        $this->assertSame('club app', $data['quotes'][0]['idea']);
        $this->assertSame('Bitte Logo größer', $data['projects'][0]['chat'][0]['body']);
        $this->assertCount(1, $data['waitlist_signups']);
        $this->assertArrayNotHasKey('two_factor_secret', $data['account']);
    }

    public function test_delete_is_a_dry_run_until_applied(): void
    {
        $this->buyer();

        $this->artisan('factory:gdpr', ['action' => 'delete', 'email' => 'kunde@example.com'])->assertSuccessful();

        $this->assertTrue(Customer::where('email', 'kunde@example.com')->exists());
        $this->assertSame(1, ChangeMessage::count());
    }

    public function test_a_buyer_is_anonymised_and_the_order_is_kept(): void
    {
        $project = $this->buyer();
        $this->signupOn('kunde@example.com');
        $customer = Customer::where('email', 'kunde@example.com')->first();
        $customer->createToken('portal');
        AdAccountLink::create(['customer_id' => $customer->id, 'platform' => 'google', 'external_id' => '1234567890']);
        $unordered = Quote::create(['customer_id' => $customer->id, 'idea' => 'second idea', 'breakdown' => [], 'price_eur' => 1, 'app_type' => 'A', 'valid_until' => now()->addWeek()]);

        $this->artisan('factory:gdpr', ['action' => 'delete', 'email' => 'kunde@example.com', '--apply' => true])->assertSuccessful();

        $customer->refresh();
        $this->assertSame("deleted-{$customer->id}@deleted.invalid", $customer->email);
        $this->assertNull($customer->name);
        $this->assertSame(0, $customer->tokens()->count());
        $this->assertSame(1, Order::count(), 'orders stay for § 132 BAO');
        $this->assertTrue(Project::whereKey($project->id)->exists());
        $this->assertSame(0, ChangeMessage::count());
        $this->assertSame(0, LandingSignup::count());
        $this->assertSame(0, AdAccountLink::count());
        $this->assertNull(Quote::find($unordered->id));

        $proof = AuditLog::where('action', 'gdpr.delete')->first();
        $this->assertSame(hash('sha256', 'kunde@example.com'), $proof->data['email_sha256']);
        $this->assertStringNotContainsString('kunde@example.com', json_encode($proof->data));
    }

    public function test_somebody_who_never_ordered_disappears(): void
    {
        $c = Customer::create(['email' => 'nur@example.com', 'locale' => 'de']);
        $proto = Prototype::create(['status' => 'ready', 'kind' => 'site', 'prompt' => 'Friseur', 'customer_id' => $c->id, 'ip' => '1.2.3.4', 'expires_at' => now()->addDay()]);

        $this->artisan('factory:gdpr', ['action' => 'delete', 'email' => 'nur@example.com', '--apply' => true])->assertSuccessful();

        $this->assertNull(Customer::find($c->id));
        $this->assertNull(Prototype::find($proto->id));
    }

    public function test_an_admin_is_refused(): void
    {
        Customer::create(['email' => 'chef@example.com', 'locale' => 'de', 'is_admin' => true]);

        $this->artisan('factory:gdpr', ['action' => 'delete', 'email' => 'chef@example.com', '--apply' => true])->assertFailed();
        $this->assertTrue(Customer::where('email', 'chef@example.com')->exists());
    }
}
