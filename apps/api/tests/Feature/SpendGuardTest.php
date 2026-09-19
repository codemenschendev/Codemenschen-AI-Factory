<?php

namespace Tests\Feature;

use App\Domain\Ads\SpendGuard;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\ValidationController;
use App\Jobs\PublishCampaign;
use App\Models\Customer;
use App\Models\MarketingCampaign;
use App\Models\Prototype;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The three walls between an ad and a bill nobody agreed to, and the validation test behind them. */
class SpendGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ads.meta' => ['token' => 't', 'ad_account_id' => 'act_1', 'page_id' => 'p1', 'api_version' => 'v26.0',
            'dsa_beneficiary' => 'Codemenschen GmbH', 'dsa_payor' => 'Codemenschen GmbH']]);
    }

    private function running(array $attrs = []): MarketingCampaign
    {
        return MarketingCampaign::create($attrs + ['platform' => 'meta', 'strategy' => ['kind' => 'validation'], 'status' => 'approved',
            'platform_status' => 'active', 'spend_cap_eur' => 150, 'ends_at' => now()->addDays(5),
            'platform_ref' => ['campaign_id' => 'c1']]);
    }

    private function metaSpends(float $total, float $today): void
    {
        Http::fake([
            '*/c1/insights*date_preset=maximum*' => Http::response(['data' => [['spend' => (string) $total]]]),
            '*/c1/insights*date_preset=today*' => Http::response(['data' => [['spend' => (string) $today]]]),
            'graph.facebook.com/*' => Http::response(['success' => true]),
        ]);
    }

    public function test_the_watch_stops_a_campaign_at_its_total(): void
    {
        $this->metaSpends(151.20, 20);
        $c = $this->running();

        $out = app(SpendGuard::class)->watch();

        $this->assertSame([$c->id], $out['stopped']);
        $c->refresh();
        $this->assertSame(['paused', 'cap_reached', 151.2], [$c->platform_status, $c->stopped_reason, $c->spent_eur]);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/c1') && $r['status'] === 'PAUSED');
    }

    public function test_the_watch_stops_a_day_far_over_its_share_and_leaves_a_normal_day_alone(): void
    {
        // 150 over 5 days is 30 a day.
        $today = 31;
        Http::fake(function ($r) use (&$today) {
            return Http::response(str_contains($r->url(), 'insights')
                ? ['data' => [['spend' => (string) (str_contains($r->url(), 'today') ? $today : 80)]]] : ['success' => true]);
        });
        $c = $this->running();
        app(SpendGuard::class)->watch();
        $this->assertSame('active', $c->fresh()->platform_status);

        $today = 70;
        app(SpendGuard::class)->watch();
        $this->assertSame('day_overspent', $c->fresh()->stopped_reason);
    }

    public function test_the_kill_switch_stops_everything_and_nothing_starts_while_it_is_on(): void
    {
        $this->metaSpends(0, 0);
        $a = $this->running();
        $admin = Customer::create(['email' => 'chef@example.com', 'locale' => 'de', 'is_admin' => true]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/ads/kill', ['on' => true])->assertOk()->assertJson(['stopped' => [$a->id]]);
        $this->assertSame('kill_switch', $a->fresh()->stopped_reason);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/marketing/{$a->id}/activate")
            ->assertStatus(422)->assertJsonFragment(['error' => 'The kill switch is on: no ad may start until an operator turns it off.']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/ads/kill', ['on' => false])->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/marketing/{$a->id}/activate")->assertOk()->assertJson(['status' => 'active']);
    }

    public function test_the_second_wall_holds_the_total_of_all_running_ads_and_one_campaigns_size(): void
    {
        Setting::write(SpendGuard::MAX_DAILY_TOTAL, 50);
        $this->running();                                   // 30 a day already running
        $next = $this->running(['platform_status' => 'paused', 'spend_cap_eur' => 125]);   // 25 a day more

        $blockers = app(SpendGuard::class)->blockers($next);
        $this->assertStringContainsString('over the limit of 50 EUR', $blockers[0]);

        Setting::write(SpendGuard::MAX_DAILY_TOTAL, 100);
        Setting::write(SpendGuard::MAX_CAMPAIGN, 100);
        $this->assertStringContainsString('over the limit of 100 EUR for one campaign', app(SpendGuard::class)->blockers($next)[0]);
    }

    public function test_a_test_is_published_with_a_lifetime_budget_an_end_and_its_countries(): void
    {
        Http::fake([
            '*/act_1/adimages' => Http::response(['images' => ['x' => ['hash' => 'h1']]]),
            '*/act_1/campaigns' => Http::response(['id' => 'c9']),
            '*/act_1/adsets' => Http::response(['id' => 's9']),
            '*/act_1/adcreatives' => Http::response(['id' => 'cr9']),
            '*/act_1/ads' => Http::response(['id' => 'a9']),
        ]);
        $path = sys_get_temp_dir().'/guard-ad.png';
        file_put_contents($path, str_repeat('x', 100));
        $c = MarketingCampaign::create(['platform' => 'meta', 'status' => 'approved', 'platform_status' => 'publishing',
            'strategy' => ['countries' => ['AT'], 'landing_url' => 'https://api.example/l/1'], 'spend_cap_eur' => 150,
            'ends_at' => now()->addDays(7), 'creative_path' => $path]);
        $c->creatives()->createMany([['kind' => 'headline', 'content' => 'Gutscheine verkaufen'], ['kind' => 'ad_copy', 'content' => 'Teste gratis.']]);

        (new PublishCampaign($c->id))->handle(app(\App\Domain\Ads\PublisherRegistry::class), app(\App\Domain\Ads\Preflight::class));

        $this->assertSame('paused', $c->fresh()->platform_status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/campaigns') && $r['spend_cap'] == 15000 && $r['status'] === 'PAUSED');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/adsets') && $r['lifetime_budget'] == 15000 && isset($r['end_time'])
            && ! isset($r['daily_budget']) && json_decode($r['targeting'], true)['geo_locations']['countries'] === ['AT']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/adcreatives') && str_contains($r['object_story_spec'], 'api.example'));
    }

    public function test_an_operator_prepares_a_test_from_a_campaign_prototype(): void
    {
        Queue::fake();
        config(['services.media.uploads_path' => sys_get_temp_dir().'/guard-media']);
        $admin = Customer::create(['email' => 'chef@example.com', 'locale' => 'de', 'is_admin' => true]);
        $campaign = Prototype::create(['kind' => 'campaign', 'status' => 'ready', 'prompt' => 'x', 'ip' => '1.2.3.4', 'expires_at' => now()->addDays(30)]);
        $png = base64_encode(str_repeat('p', 30000));
        Prototype::create(['parent_id' => $campaign->id, 'kind' => 'ads', 'status' => 'ready', 'prompt' => 'x', 'ip' => '1.2.3.4',
            'html' => "<img src=\"data:image/png;base64,$png\">", 'expires_at' => now()->addDays(30)]);
        $site = Prototype::create(['parent_id' => $campaign->id, 'kind' => 'site', 'status' => 'ready', 'prompt' => 'x', 'ip' => '1.2.3.4',
            'title' => 'Korn: Brot an die Tür', 'html' => '<html lang="de"><p>Frisches Brot jeden Morgen an deiner Tür, gebacken in der Nacht von unserer Bäckerei in Graz.</p></html>',
            'published_at' => now(), 'expires_at' => now()->addDays(30)]);

        $this->actingAs($admin, 'sanctum')->getJson("/api/admin/prototypes/{$campaign->id}/validation")->assertOk()
            ->assertJson(['ready' => true, 'defaults' => ['headline' => 'Korn', 'budget_eur' => 150]])
            ->assertJsonPath('defaults.text', 'Frisches Brot jeden Morgen an deiner Tür, gebacken in der Nacht von unserer Bäckerei in Graz.');

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/prototypes/{$campaign->id}/validation", ['budget_eur' => 900, 'days' => 7,
            'countries' => ['AT'], 'headline' => 'Korn', 'text' => 'Brot'])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/prototypes/{$campaign->id}/validation", ['budget_eur' => 150, 'days' => 7,
            'countries' => ['AT', 'DE'], 'headline' => 'Korn', 'text' => 'Frisches Brot an deiner Tür.'])->assertStatus(202);

        $test = MarketingCampaign::sole();
        $this->assertSame([150, ['AT', 'DE']], [$test->spend_cap_eur, $test->strategy['countries']]);
        $this->assertStringStartsWith(LandingController::url($site).'?utm_source=meta', $test->strategy['landing_url']);
        $this->assertSame(30000, filesize($test->creativePath()));
        Queue::assertPushed(PublishCampaign::class);

        $this->actingAs(Customer::create(['email' => 'k@example.com', 'locale' => 'de']), 'sanctum')
            ->getJson("/api/admin/prototypes/{$campaign->id}/validation")->assertForbidden();
    }

    public function test_the_ad_picture_is_the_largest_image(): void
    {
        $small = base64_encode(str_repeat('s', 25000));
        $big = base64_encode(str_repeat('b', 40000));
        $pic = ValidationController::adPicture("<img src=\"data:image/png;base64,$small\"><img src=\"data:image/jpeg;base64,$big\">");

        $this->assertSame(['jpg', 40000], [$pic['ext'], strlen($pic['bytes'])]);
    }
}
