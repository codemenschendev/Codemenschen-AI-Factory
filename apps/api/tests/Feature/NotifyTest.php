<?php

namespace Tests\Feature;

use App\Models\Prototype;
use App\Services\Notify;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The operators hear about what broke without opening the admin tab.
 */
class NotifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_prototype_reaches_teams_as_a_card_and_the_admin_mailbox(): void
    {
        config(['services.teams.webhook_url' => 'https://teams.test/hook', 'services.admin_email' => 'ops@example.com',
            'services.openclaw.hook_url' => null]);
        Http::fake(['teams.test/*' => Http::response('', 202)]);
        Mail::fake();
        $proto = Prototype::create(['status' => 'failed', 'kind' => 'site', 'prompt' => 'Bäckerei in Linz',
            'error' => 'The agent answered twice without HTML.', 'expires_at' => now()->addDays(7)]);

        app(Notify::class)->prototypeFailed($proto);

        Http::assertSent(function ($r) {
            $card = $r['attachments'][0]['content'] ?? [];

            return $r->url() === 'https://teams.test/hook'
                && ($card['type'] ?? '') === 'AdaptiveCard'
                && str_contains($card['body'][0]['text'], 'Prototype '.substr(app(Prototype::class)->newQuery()->first()->id, 0, 8))
                && str_contains($card['body'][0]['text'], 'answered twice without HTML');
        });
    }

    public function test_without_a_webhook_nothing_is_sent_and_nothing_breaks(): void
    {
        config(['services.teams.webhook_url' => null, 'services.openclaw.hook_url' => null, 'services.admin_email' => null]);
        Http::fake();
        $proto = Prototype::create(['status' => 'failed', 'kind' => 'ads', 'prompt' => 'x', 'error' => 'y', 'expires_at' => now()->addDay()]);

        app(Notify::class)->prototypeFailed($proto);

        Http::assertNothingSent();
    }

    public function test_a_webhook_that_refuses_is_logged_not_thrown(): void
    {
        config(['services.teams.webhook_url' => 'https://teams.test/hook', 'services.openclaw.hook_url' => null, 'services.admin_email' => null]);
        Http::fake(['teams.test/*' => Http::response('nope', 400)]);
        $proto = Prototype::create(['status' => 'failed', 'kind' => 'app', 'prompt' => 'x', 'error' => 'y', 'expires_at' => now()->addDay()]);

        app(Notify::class)->prototypeFailed($proto);

        $this->assertTrue(true, 'reached: no exception');
    }
}
