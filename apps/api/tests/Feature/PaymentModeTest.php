<?php

namespace Tests\Feature;

use App\Domain\Payments\StripeKeys;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentModeTest extends TestCase
{
    use RefreshDatabase;

    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.stripe.secret' => 'sk_test_sandbox', 'services.stripe.webhook_secret' => 'whsec_test',
            'services.stripe.live_secret' => null, 'services.stripe.live_webhook_secret' => null,
            'services.buzz.alert_dir' => null, 'services.openclaw.hook_url' => null,
        ]);
        $admin = Customer::create(['email' => 'ops@example.com', 'locale' => 'de', 'is_admin' => true]);
        $this->admin = ['Authorization' => 'Bearer '.$admin->createToken('portal')->plainTextToken];
    }

    public function test_sandbox_is_the_default_and_uses_the_test_key(): void
    {
        $keys = app(StripeKeys::class);

        $this->assertSame('sandbox', $keys->mode());
        $this->assertSame('sk_test_sandbox', $keys->secret());
    }

    public function test_live_needs_both_live_keys_and_the_typed_confirmation(): void
    {
        $this->postJson('/api/admin/payments/mode', ['mode' => 'live', 'confirm' => 'LIVE'], $this->admin)
            ->assertStatus(422)->assertJsonPath('message', 'Live payments are not configured: STRIPE_LIVE_SECRET, STRIPE_LIVE_WEBHOOK_SECRET');

        config(['services.stripe.live_secret' => 'sk_test_oops', 'services.stripe.live_webhook_secret' => 'whsec_live']);
        $this->postJson('/api/admin/payments/mode', ['mode' => 'live', 'confirm' => 'LIVE'], $this->admin)
            ->assertStatus(422)->assertJsonPath('message', 'Live payments are not configured: STRIPE_LIVE_SECRET (not a live key)');

        config(['services.stripe.live_secret' => 'sk_live_real']);
        $this->postJson('/api/admin/payments/mode', ['mode' => 'live'], $this->admin)->assertStatus(422);
        $this->assertSame('sandbox', app(StripeKeys::class)->mode(), 'nothing changed without the confirmation');

        $this->postJson('/api/admin/payments/mode', ['mode' => 'live', 'confirm' => 'LIVE'], $this->admin)
            ->assertOk()->assertJsonPath('mode', 'live');
        $this->assertSame('sk_live_real', app(StripeKeys::class)->secret());

        $this->postJson('/api/admin/payments/mode', ['mode' => 'sandbox'], $this->admin)->assertOk()->assertJsonPath('mode', 'sandbox');
        $this->assertSame('sk_test_sandbox', app(StripeKeys::class)->secret(), 'back to sandbox needs no confirmation');
    }

    public function test_a_customer_cannot_switch_payments(): void
    {
        $customer = Customer::create(['email' => 'c@example.com', 'locale' => 'de']);

        $this->postJson('/api/admin/payments/mode', ['mode' => 'sandbox'], ['Authorization' => 'Bearer '.$customer->createToken('portal')->plainTextToken])
            ->assertForbidden();
    }

    public function test_the_webhook_accepts_events_signed_for_either_mode(): void
    {
        config(['services.stripe.live_webhook_secret' => 'whsec_live']);
        $payload = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'ping', 'data' => ['object' => ['id' => 'x', 'object' => 'thing']]]);
        $sign = fn (string $secret) => 't='.time().',v1='.hash_hmac('sha256', time().'.'.$payload, $secret);

        foreach (['whsec_test', 'whsec_live'] as $secret) {
            $this->call('POST', '/api/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sign($secret), 'CONTENT_TYPE' => 'application/json'], $payload)
                ->assertSuccessful();
        }
        $this->call('POST', '/api/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sign('whsec_other'), 'CONTENT_TYPE' => 'application/json'], $payload)
            ->assertStatus(400);
    }
}
