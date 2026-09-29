<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A sign-in link in an e-mail carries the storefront's domain (appmitki.com passes /api/ on to the
 * API), not the API's technical host, and it still signs in when it arrives that way.
 */
class MailLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_link_names_the_storefront_and_signs_in_through_it(): void
    {
        config(['mail.default' => 'log', 'services.mail_link_url' => 'https://appmitki.com', 'services.frontend_url' => 'https://appmitki.com']);
        Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        $url = null;
        Log::listen(function ($event) use (&$url) {
            if ($event->message === 'auth.magic_link') {
                $url = $event->context['url'];
            }
        });

        // Asked for from the API's own host, as the storefront's browser does.
        $this->postJson('http://api.appwerk.test/api/auth/magic-link', ['email' => 'kunde@example.com', 'locale' => 'de'])->assertOk();

        $this->assertStringStartsWith('https://appmitki.com/api/auth/verify/', (string) $url);
        $this->get($url)->assertRedirect();
    }

    public function test_only_the_mail_link_moves_the_rest_keeps_its_host(): void
    {
        config(['services.mail_link_url' => 'https://appmitki.com']);

        $link = \App\Support\MailLink::signed('auth.join', now()->addMinutes(5), ['email' => 'a@example.com']);

        $this->assertStringStartsWith('https://appmitki.com/api/auth/join', $link);
        $this->assertStringStartsWith('http://localhost', url('/x'));
    }
}
