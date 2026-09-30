<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A sign-in link in an e-mail carries the storefront's domain (appmitki.com passes /api/ on to the
 * API), not the API's technical host. It opens a page there, and only the page's POST signs in.
 */
class MailLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_link_opens_the_storefront_page_and_only_its_post_signs_in(): void
    {
        $this->storefront();
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        $url = $this->mailedLink('auth.magic_link', fn () => $this->postJson('http://api.appwerk.test/api/auth/magic-link', ['email' => 'kunde@example.com', 'locale' => 'de'])->assertOk());

        $this->assertStringStartsWith("https://appmitki.com/de/signin/auth/verify/{$customer->id}?", $url);
        $api = str_replace('https://appmitki.com/de/signin/', 'https://appmitki.com/api/', $url);

        // A scanner or the old GET gets the page, and no session is made.
        $this->get($api)->assertRedirect($url);
        $this->assertSame(0, $customer->tokens()->count());

        $this->postJson($api)->assertOk()->assertJsonPath('to', '/de/account')->assertJsonStructure(['token']);
        $this->assertSame(1, $customer->tokens()->count());
    }

    public function test_an_edited_link_is_refused_on_the_post_too(): void
    {
        $this->storefront();
        Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        $url = $this->mailedLink('auth.magic_link', fn () => $this->postJson('/api/auth/magic-link', ['email' => 'kunde@example.com'])->assertOk());
        $api = str_replace('https://appmitki.com/de/signin/', 'https://appmitki.com/api/', $url);

        $this->postJson(str_replace('locale=de', 'locale=en', $api))->assertForbidden();
    }

    public function test_the_join_link_goes_through_the_page_as_well(): void
    {
        $this->storefront();
        $url = $this->mailedLink('auth.join_link', fn () => $this->postJson('/api/auth/magic-link', ['email' => 'neu@example.com', 'join' => true, 'locale' => 'en'])->assertOk());

        $this->assertStringStartsWith('https://appmitki.com/en/signin/auth/join?', $url);
        $api = str_replace('https://appmitki.com/en/signin/', 'https://appmitki.com/api/', $url);
        $this->get($api)->assertRedirect($url);
        $this->assertSame(0, Customer::where('email', 'neu@example.com')->count());

        $this->postJson($api)->assertOk()->assertJsonPath('to', '/en/account');
        $this->assertSame(1, Customer::where('email', 'neu@example.com')->count());
    }

    public function test_without_a_shared_host_the_link_stays_a_plain_get(): void
    {
        config(['mail.default' => 'log', 'services.mail_link_url' => 'https://api.example.com', 'services.frontend_url' => 'https://shop.example.com']);
        Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        $url = $this->mailedLink('auth.magic_link', fn () => $this->postJson('/api/auth/magic-link', ['email' => 'kunde@example.com'])->assertOk());

        $this->assertStringStartsWith('https://api.example.com/api/auth/verify/', $url);
        $this->get($url)->assertRedirectContains('https://shop.example.com/de/account#token=');
    }

    private function storefront(): void
    {
        config(['mail.default' => 'log', 'services.mail_link_url' => 'https://appmitki.com', 'services.frontend_url' => 'https://appmitki.com']);
    }

    private function mailedLink(string $message, callable $send): string
    {
        $url = null;
        Log::listen(function ($event) use (&$url, $message) {
            if ($event->message === $message) {
                $url = $event->context['url'];
            }
        });
        $send();

        return (string) $url;
    }

    public function test_only_the_mail_link_moves_the_rest_keeps_its_host(): void
    {
        config(['services.mail_link_url' => 'https://appmitki.com']);

        $link = \App\Support\MailLink::signed('auth.join', now()->addMinutes(5), ['email' => 'a@example.com']);

        $this->assertStringStartsWith('https://appmitki.com/api/auth/join', $link);
        $this->assertStringStartsWith('http://localhost', url('/x'));
    }
}
