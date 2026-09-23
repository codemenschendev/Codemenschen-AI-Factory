<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Signing in to the console from the console (2026-09-23).
 *
 * An operator used to be sent to the customer's account page to sign in and then find their way
 * back. The link now lands where it was asked for, but only an admin is ever sent to the console,
 * and the console form never tells a stranger that a console exists.
 */
class AdminSignInTest extends TestCase
{
    use RefreshDatabase;

    private function linkFor(Customer $customer, array $extra = []): string
    {
        return URL::temporarySignedRoute('auth.verify', now()->addMinutes(30),
            ['customer' => $customer->id, 'locale' => 'de'] + $extra);
    }

    public function test_an_admin_link_lands_in_the_console(): void
    {
        config(['services.frontend_url' => 'https://appwerk.test']);
        $admin = Customer::create(['email' => 'chef@example.com', 'locale' => 'de', 'is_admin' => true]);

        $res = $this->get($this->linkFor($admin, ['to' => 'admin']));

        $res->assertRedirect();
        $this->assertStringStartsWith('https://appwerk.test/de/admin#token=', $res->headers->get('Location'));
        $this->assertSame('ops', $admin->tokens()->first()->name);
    }

    public function test_a_customer_is_never_sent_to_the_console_even_with_a_signed_to(): void
    {
        // A link that was signed while they were an admin, clicked after they stopped being one.
        config(['services.frontend_url' => 'https://appwerk.test']);
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);

        $res = $this->get($this->linkFor($customer, ['to' => 'admin']));

        $this->assertStringStartsWith('https://appwerk.test/de/account#token=', $res->headers->get('Location'));
        $this->assertSame('portal', $customer->tokens()->first()->name);
    }

    public function test_the_target_cannot_be_edited_after_signing(): void
    {
        $admin = Customer::create(['email' => 'chef@example.com', 'locale' => 'de', 'is_admin' => true]);

        // An ordinary link with to=admin glued on afterwards: the signature no longer matches.
        $this->get($this->linkFor($admin).'&to=admin')->assertForbidden();
    }

    public function test_the_console_form_names_the_console_only_to_an_admin(): void
    {
        config(['mail.default' => 'log']);
        Customer::create(['email' => 'chef@example.com', 'locale' => 'de', 'is_admin' => true]);
        Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        $urls = [];
        Log::listen(function ($event) use (&$urls) {
            if ($event->message === 'auth.magic_link') {
                $urls[$event->context['email']] = $event->context['url'];
            }
        });

        foreach (['chef@example.com', 'kunde@example.com', 'nobody@example.com'] as $email) {
            // The same answer for all three, so the form cannot be used to find the admins.
            $this->postJson('/api/auth/magic-link', ['email' => $email, 'locale' => 'de', 'to' => 'admin'])
                ->assertOk()->assertExactJson(['sent' => true]);
        }

        $this->assertStringContainsString('to=admin', $urls['chef@example.com']);
        $this->assertStringNotContainsString('to=admin', $urls['kunde@example.com']);
        $this->assertArrayNotHasKey('nobody@example.com', $urls);
    }
}
