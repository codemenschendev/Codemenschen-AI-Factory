<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LandingSignup;
use App\Models\Prototype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** A campaign's landing page, live, with a double opt-in waitlist. */
class LandingWaitlistTest extends TestCase
{
    use RefreshDatabase;

    private Customer $owner;

    private function landing(array $attrs = []): Prototype
    {
        $this->owner = Customer::create(['email' => 'owner@example.com', 'locale' => 'de']);
        $campaign = Prototype::create(['status' => 'ready', 'kind' => 'campaign', 'prompt' => 'Brot-Abo', 'ip' => '203.0.113.7',
            'customer_id' => $this->owner->id, 'expires_at' => now()->addDays(3)]);

        return Prototype::create($attrs + ['parent_id' => $campaign->id, 'status' => 'ready', 'kind' => 'site', 'prompt' => 'Brot-Abo',
            'title' => 'Bäckerei Korn: Brot an die Tür', 'customer_id' => $this->owner->id, 'ip' => '203.0.113.7',
            'html' => '<!doctype html><html lang="de"><head><title>Korn</title></head><body><form><input type="email"><button>Los</button></form></body></html>',
            'expires_at' => now()->addDays(3)]);
    }

    public function test_the_page_is_not_public_until_the_owner_switches_it_on(): void
    {
        $page = $this->landing();
        $this->get("/l/{$page->id}")->assertNotFound();

        $this->actingAs(Customer::create(['email' => 'other@example.com', 'locale' => 'de']), 'sanctum')
            ->postJson("/api/prototypes/{$page->id}/publish")->assertForbidden();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/prototypes/{$page->id}/publish")
            ->assertOk()->assertJsonPath('url', url("/l/{$page->id}"));

        $this->assertTrue($page->fresh()->expires_at->gt(now()->addDays(29)));
        $this->assertTrue(Prototype::find($page->parent_id)->expires_at->gt(now()->addDays(29)));
        $res = $this->get("/l/{$page->id}?utm_source=facebook")->assertOk();
        $res->assertSee('aw-consent', false)->assertSee('Mit der Anmeldung bekommst du E-Mails von Bäckerei Korn', false)
            ->assertSee('"source":"facebook"', false);
        $this->assertStringContainsString("connect-src 'self'", $res->headers->get('Content-Security-Policy'));
    }

    public function test_a_sign_up_waits_for_the_link_and_the_link_confirms_it(): void
    {
        $page = $this->landing(['published_at' => now()]);
        config(["mail.default" => "log"]);
        Log::spy();

        $this->postJson("/api/landing/{$page->id}/signup", ['email' => 'Anna@Example.com', 'source' => 'facebook'])->assertOk();
        $row = LandingSignup::sole();
        $this->assertSame(['anna@example.com', 'pending', 'facebook'], [$row->email, $row->status, $row->source]);
        $this->assertStringContainsString('Codemenschen GmbH', $row->consent);
        Log::shouldHaveReceived('info')->withArgs(fn ($m) => $m === 'landing.confirm_link')->once();

        // Again at once: no second mail, the same answer.
        $this->postJson("/api/landing/{$page->id}/signup", ['email' => 'anna@example.com'])->assertOk();
        Log::shouldHaveReceived('info')->withArgs(fn ($m) => $m === 'landing.confirm_link')->once();

        $this->get(URL::temporarySignedRoute('landing.confirm', now()->addDay(), ['signup' => $row->id]))->assertOk()->assertSee('bestätigt');
        $this->assertSame('confirmed', $row->fresh()->status);
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/prototypes/{$page->id}/signups")
            ->assertOk()->assertJson(['confirmed' => 1, 'pending' => 0]);

        $this->get(URL::signedRoute('landing.remove', ['signup' => $row->id]))->assertOk();
        $this->assertSame(0, LandingSignup::count());
    }

    public function test_a_bot_filling_the_hidden_field_is_told_it_worked_and_nothing_is_kept(): void
    {
        $page = $this->landing(['published_at' => now()]);

        $this->postJson("/api/landing/{$page->id}/signup", ['email' => 'bot@example.com', 'hp' => 'http://spam'])->assertOk();

        $this->assertSame(0, LandingSignup::count());
    }

    public function test_the_confirmation_mail_goes_out_with_a_one_click_unsubscribe(): void
    {
        config(['mail.default' => 'array']);
        $page = $this->landing(['published_at' => now()]);

        $this->postJson("/api/landing/{$page->id}/signup", ['email' => 'anna@example.com'])->assertOk();

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $mail = $sent[0]->getOriginalMessage();
        $this->assertSame('Bitte bestätige deine Anmeldung bei Bäckerei Korn', $mail->getSubject());
        $this->assertStringContainsString('/api/landing/signups/', $mail->getHeaders()->get('List-Unsubscribe')->getBodyAsString());
    }

    public function test_links_without_a_valid_signature_do_nothing(): void
    {
        $page = $this->landing(['published_at' => now()]);
        $row = LandingSignup::create(['prototype_id' => $page->id, 'email' => 'a@example.com', 'consent' => 'x']);

        $this->get("/api/landing/signups/{$row->id}/confirm")->assertForbidden();
        $this->get("/api/landing/signups/{$row->id}/remove")->assertForbidden();
        $this->assertSame('pending', $row->fresh()->status);
    }

    public function test_only_a_campaign_landing_page_can_go_live(): void
    {
        $this->owner = Customer::create(['email' => 'owner@example.com', 'locale' => 'de']);
        $site = Prototype::create(['status' => 'ready', 'kind' => 'site', 'prompt' => 'x', 'html' => '<html></html>', 'ip' => '1.2.3.4',
            'customer_id' => $this->owner->id, 'expires_at' => now()->addDays(3)]);

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/prototypes/{$site->id}/publish")->assertStatus(422);
    }
}
