<?php

namespace Tests\Feature;

use App\Domain\Pricing\Estimator;
use App\Mail\CustomerNotice;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Project;
use App\Models\Prototype;
use App\Services\OrderFulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A website as a product: the site preview is bought as it is, goes live at payment (or after the
 * withdrawal period), never expires, and can sit on the customer's own domain.
 */
class SiteProductTest extends TestCase
{
    use RefreshDatabase;

    private function preview(array $attrs = []): Prototype
    {
        return Prototype::create($attrs + ['status' => 'ready', 'kind' => 'site', 'prompt' => 'Bäckerei Lang in Linz', 'title' => 'Bäckerei Lang: Handgemachte Backwaren',
            'ip' => '203.0.113.7', 'expires_at' => now()->addDays(3),
            'html' => '<!doctype html><html lang="de"><head><title>Lang</title></head><body><h1>Bäckerei Lang</h1><form><input type="email"><button>Los</button></form></body></html>']);
    }

    /** @return array{0:Order,1:Prototype} */
    private function order(bool $waiver = true): array
    {
        config(['services.stripe.secret' => null]);
        $preview = $this->preview();
        $quoteId = $this->postJson('/api/quotes', ['prototype_id' => $preview->id, 'locale' => 'de'])->assertCreated()->json('id');
        $this->postJson('/api/checkout', ['quote_id' => $quoteId, 'email' => 'lang@example.com', 'fagg_waiver' => $waiver, 'terms' => true,
            'packages' => ['storePublishing' => true, 'marketingLaunch' => true]])->assertStatus(503);

        return [Order::firstOrFail(), $preview];
    }

    public function test_a_finished_site_preview_gets_one_price_and_stays_up_while_the_quote_is_valid(): void
    {
        $preview = $this->preview();
        $res = $this->postJson('/api/quotes', ['prototype_id' => $preview->id, 'locale' => 'de'])->assertCreated();
        $res->assertJsonPath('kind', 'site')->assertJsonPath('price_eur', Estimator::SITE_PRICE_EUR)
            ->assertJsonPath('hosting_monthly_eur', Estimator::SITE_HOSTING_MONTHLY_EUR)->assertJsonPath('hosting_free_months', 12)
            ->assertJsonPath('title', 'Bäckerei Lang: Handgemachte Backwaren')
            ->assertJsonPath('packages', ['marketingLaunch' => 129])->assertJsonPath('store_locales', []);
        $this->assertTrue($preview->fresh()->expires_at->gt(now()->addDays(13)));

        // An app preview, an unfinished page, and a campaign's landing page have no price here.
        $this->postJson('/api/quotes', ['prototype_id' => $this->preview(['kind' => 'app'])->id])->assertStatus(422);
        $this->postJson('/api/quotes', ['prototype_id' => $this->preview(['status' => 'building', 'html' => null])->id])->assertStatus(422);
        $this->postJson('/api/quotes', ['prototype_id' => $this->preview(['parent_id' => $preview->id])->id])->assertStatus(422);
        $this->postJson('/api/quotes', ['prototype_id' => '01a0cdb4-a35c-7038-8794-31fc7ba57c36'])->assertStatus(422);
    }

    public function test_paying_puts_the_page_live_for_good_and_only_the_ads_package_is_charged(): void
    {
        Mail::fake();
        [$order, $preview] = $this->order();
        $this->assertSame(299 + 129, $order->total_one_time_eur);
        $this->assertSame(['marketingLaunch' => true], $order->packages);
        $this->get("/l/{$preview->id}")->assertNotFound();

        $project = app(OrderFulfillment::class)->markPaid($order, 'pi_test', 42800, ['type' => 'test']);

        $this->assertSame(['site', 'site', 'PUBLISHED'], [$project->kind, $project->stack, $project->fresh()->status]);
        $preview->refresh();
        $this->assertSame($project->id, $preview->project_id);
        $this->assertSame($order->customer_id, $preview->customer_id);
        $this->assertNull($preview->expires_at);
        $this->assertNotNull($preview->published_at);
        $this->assertDatabaseHas('project_events', ['project_id' => $project->id, 'type' => 'site.live']);

        // Live, indexable, with the sign-up form wired, and still there long after a free preview would be gone.
        $res = $this->get("/l/{$preview->id}")->assertOk()->assertSee('Bäckerei Lang', false)->assertSee('aw-consent', false);
        $this->assertNull($res->headers->get('X-Robots-Tag'));
        $this->travel(400)->days();
        $this->get("/l/{$preview->id}")->assertOk();
        $this->assertTrue($preview->fresh()->isLive());

        // The preview page says so, and the quote cannot be bought twice.
        $this->getJson("/api/prototypes/{$preview->id}")->assertOk()->assertJsonPath('bought', true)->assertJsonPath('live_url', url("/l/{$preview->id}"));
        $this->postJson('/api/quotes', ['prototype_id' => $preview->id])->assertStatus(409);
    }

    public function test_without_the_waiver_the_page_goes_live_after_the_withdrawal_period(): void
    {
        Mail::fake();
        [$order, $preview] = $this->order(waiver: false);
        $project = app(OrderFulfillment::class)->markPaid($order, 'pi_test', 29900, ['type' => 'test']);

        $this->assertSame('PAID', $project->fresh()->status);
        $this->assertNull($preview->fresh()->published_at);
        $this->get("/l/{$preview->id}")->assertNotFound();

        $this->artisan('pipeline:tick')->assertSuccessful();
        $this->assertSame('PAID', $project->fresh()->status);

        $this->travel(15)->days();
        $this->artisan('pipeline:tick')->assertSuccessful();
        $this->assertSame('PUBLISHED', $project->fresh()->status);
        $this->get("/l/{$preview->id}")->assertOk();
    }

    public function test_the_order_mail_tells_the_customer_where_the_site_is(): void
    {
        config(['mail.default' => 'log']);
        [$order] = $this->order();
        Mail::fake();
        $project = app(OrderFulfillment::class)->markPaid($order, 'pi_test', 42800, ['type' => 'test']);
        Mail::assertSent(CustomerNotice::class, function ($m) use ($project) {
            return str_contains($m->body, 'Deine Website ist online: '.url("/l/{$project->prototype->id}"))
                && str_contains($m->body, 'die ersten 12 Monate sind inklusive')
                && str_contains($m->body, 'trag im Dashboard dein Impressum ein');
        });
    }

    public function test_the_customer_names_a_domain_and_the_page_answers_on_it(): void
    {
        Mail::fake();
        [$order, $preview] = $this->order();
        $project = app(OrderFulfillment::class)->markPaid($order, 'pi_test', 42800, ['type' => 'test']);
        $owner = $order->customer;

        $this->actingAs($owner, 'sanctum')->getJson('/api/me/projects')->assertOk()
            ->assertJsonPath('projects.0.kind', 'site')->assertJsonPath('projects.0.site_url', url("/l/{$preview->id}"));
        $this->actingAs($owner, 'sanctum')->getJson("/api/me/projects/{$project->id}")->assertOk()
            ->assertJsonPath('site.url', url("/l/{$preview->id}"))->assertJsonPath('site.domain', null)
            ->assertJsonPath('site.server_ip', '65.108.206.249');

        $this->actingAs($owner, 'sanctum')->postJson("/api/me/projects/{$project->id}/domain", ['domain' => 'not a domain'])->assertStatus(422);
        $this->actingAs($owner, 'sanctum')->postJson("/api/me/projects/{$project->id}/domain", ['domain' => 'https://www.Baeckerei-Lang.at/'])
            ->assertOk()->assertJsonPath('domain', 'baeckerei-lang.at');
        $this->assertDatabaseHas('project_events', ['project_id' => $project->id, 'type' => 'site.domain_requested']);

        // Somebody else's project is not theirs to name.
        $this->actingAs(Customer::create(['email' => 'other@example.com', 'locale' => 'de']), 'sanctum')
            ->postJson("/api/me/projects/{$project->id}/domain", ['domain' => 'x.at'])->assertNotFound();

        // Once our team has the vhost up, the request arrives with the customer's host name.
        $this->get('http://www.baeckerei-lang.at/')->assertOk()->assertSee('Bäckerei Lang', false);
        $this->get('http://baeckerei-lang.at/')->assertOk();
        $this->get('http://nobody.example/')->assertOk()->assertDontSee('Bäckerei Lang', false);

        $this->actingAs($owner, 'sanctum')->postJson("/api/me/projects/{$project->id}/domain", ['domain' => ''])->assertOk()->assertJsonPath('domain', null);
        $this->get('http://baeckerei-lang.at/')->assertDontSee('Bäckerei Lang', false);
        $this->assertNull(Project::find($project->id)->domain);
    }

    /** @return array<string,string> */
    private function imprintForm(array $over = []): array
    {
        return $over + ['name' => 'Bäckerei Lang e.U.', 'owner' => 'Anna Lang', 'street' => 'Hauptplatz 1', 'zip_city' => '4020 Linz',
            'country' => 'Österreich', 'email' => 'office@baeckerei-lang.at', 'vat_id' => 'ATU12345678',
            'register' => 'FN 123456a, Landesgericht Linz', 'chamber' => 'WKO Oberösterreich', 'authority' => 'Magistrat Linz', 'trade' => 'Bäcker'];
    }

    public function test_the_owner_fills_in_the_impressum_and_the_page_links_it(): void
    {
        Mail::fake();
        [$order, $preview] = $this->order();
        $project = app(OrderFulfillment::class)->markPaid($order, 'pi_test', 42800, ['type' => 'test']);
        $owner = $order->customer;

        // Not there until it is filled in; the page says nothing about it yet.
        $this->get("/l/{$preview->id}/impressum")->assertNotFound();
        $this->get("/l/{$preview->id}")->assertOk()->assertDontSee('Impressum</a>', false);
        $this->actingAs($owner, 'sanctum')->getJson("/api/me/projects/{$project->id}")
            ->assertJsonPath('site.imprint_complete', false)->assertJsonPath('site.imprint_url', null);

        // The fields every business needs are required.
        $this->actingAs($owner, 'sanctum')->postJson("/api/me/projects/{$project->id}/imprint", $this->imprintForm(['street' => '', 'email' => 'nope']))
            ->assertStatus(422)->assertJsonValidationErrors(['street', 'email']);
        $this->actingAs(Customer::create(['email' => 'other@example.com', 'locale' => 'de']), 'sanctum')
            ->postJson("/api/me/projects/{$project->id}/imprint", $this->imprintForm())->assertNotFound();

        $this->actingAs($owner, 'sanctum')->postJson("/api/me/projects/{$project->id}/imprint", $this->imprintForm(['extra' => '<script>x</script>']))
            ->assertOk()->assertJsonPath('imprint_complete', true)->assertJsonPath('imprint_url', url("/l/{$preview->id}/impressum"))
            ->assertJsonPath('imprint.vat_id', 'ATU12345678');

        $res = $this->get("/l/{$preview->id}/impressum")->assertOk()
            ->assertSee('Bäckerei Lang e.U.', false)->assertSee('4020 Linz', false)->assertSee('ATU12345678', false)
            ->assertSee('§ 5 ECG', false)->assertDontSee('<script>x</script>', false);
        $this->assertStringContainsString("default-src 'none'", $res->headers->get('Content-Security-Policy'));
        $this->get("/l/{$preview->id}")->assertOk()->assertSee('href="/l/'.$preview->id.'/impressum"', false);

        // On the customer's own domain the footer and the page live at /impressum.
        $project->update(['domain' => 'baeckerei-lang.at']);
        $this->get('http://www.baeckerei-lang.at/')->assertOk()->assertSee('href="/impressum"', false);
        $this->get('http://baeckerei-lang.at/impressum')->assertOk()->assertSee('Anna Lang', false);
        $this->get('http://nobody.example/impressum')->assertNotFound();
    }

    public function test_an_app_order_is_untouched(): void
    {
        config(['services.stripe.secret' => null]);
        $quoteId = $this->postJson('/api/quotes', ['idea' => 'Booking app', 'audience' => 'consumer', 'platform' => 'web'])->assertCreated()
            ->assertJsonPath('kind', 'app')->json('id');
        $this->postJson('/api/checkout', ['quote_id' => $quoteId, 'email' => 'app@example.com', 'fagg_waiver' => false, 'terms' => true])->assertStatus(503);
        $project = app(OrderFulfillment::class)->markPaid(Order::firstOrFail(), 'pi_test', 14900, ['type' => 'test']);
        $this->assertSame(['app', 'nextjs', 'PAID'], [$project->kind, $project->stack, $project->status]);
    }
}
