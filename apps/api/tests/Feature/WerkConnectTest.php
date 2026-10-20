<?php

namespace Tests\Feature;

use App\Domain\Werk\Probe;
use App\Models\AnalyticsEvent;
use App\Models\Customer;
use App\Models\DeskSession;
use App\Models\MarketingCampaign;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Setting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** werk-connect: Werk and werk.ai read appmitki.com with the site's probe token (Werk API.md 7.10). */
class WerkConnectTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'werk-probe-token-0123456789abcdef';

    private const ROUTES = ['probe', 'overview?from=2026-10-01&to=2026-10-05', 'ads', 'business'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.werk.token' => self::TOKEN, 'services.werk.api_url' => 'https://api.werk.codemenschen.at/v1',
            'services.werk.site_url' => 'https://appmitki.com']);
        // No ad platform unless a test sets one up, and no network at all.
        config(['services.ads.google.customer_id' => '', 'services.ads.meta.token' => '']);
        Http::preventStrayRequests();
    }

    private function werk(string $route, ?string $token = self::TOKEN)
    {
        return $this->getJson('/api/werk/v1/'.$route, $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
    }

    public function test_every_route_wants_the_token(): void
    {
        foreach (self::ROUTES as $route) {
            $this->werk($route, null)->assertStatus(401)->assertExactJson(['error' => 'invalid_token']);
            $this->werk($route, 'wrong')->assertStatus(401)->assertExactJson(['error' => 'invalid_token'])
                ->assertHeader('Cache-Control', 'no-store, private');
            $this->werk($route)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $this->getJson('/api/werk/v1/'.$route, ['X-Werk-Token' => self::TOKEN])->assertOk();
        }

        config(['services.werk.token' => '']);
        foreach (self::ROUTES as $route) {
            $this->werk($route)->assertStatus(403)->assertExactJson(['error' => 'no_token_configured']);
        }
    }

    public function test_the_probe_names_the_real_stack_as_a_node_site(): void
    {
        Setting::write(Probe::PING_SETTING, ['ok' => true, 'status' => 200, 'at' => '2026-10-20T08:00:00+00:00']);

        $this->werk('probe')->assertOk()
            ->assertJsonPath('probe_version', Probe::VERSION)
            ->assertJsonPath('cms', 'node')
            ->assertJsonPath('site_url', 'https://appmitki.com')
            ->assertJsonPath('last_ping.status', 200)
            ->assertJsonStructure(['cms_version', 'platform', 'uptime_seconds', 'memory_mb', 'packages' => ['php', 'laravel/framework'], 'checked_at']);
    }

    /** An order with its payment, paid at a given moment (UTC). */
    private function paid(Customer $customer, int $eur, string $atUtc, array $order = [], array $quote = []): Order
    {
        $q = Quote::create($quote + ['customer_id' => $customer->id, 'breakdown' => [], 'price_eur' => $eur, 'app_type' => 'A', 'valid_until' => now()->addWeek()]);
        $o = Order::create($order + ['customer_id' => $customer->id, 'quote_id' => $q->id, 'packages' => [],
            'total_one_time_eur' => $eur, 'status' => 'paid', 'livemode' => true]);
        Payment::create(['order_id' => $o->id, 'amount_eur' => $eur, 'status' => 'succeeded', 'created_at' => $atUtc, 'updated_at' => $atUtc]);

        return $o;
    }

    public function test_the_overview_sums_real_paid_orders_per_vienna_day(): void
    {
        $anna = Customer::create(['email' => 'anna@example.com', 'locale' => 'de']);
        $bert = Customer::create(['email' => 'bert@example.com', 'locale' => 'de']);

        $this->paid($anna, 500, '2026-09-20 10:00:00');                               // before the range: makes Anna returning
        $this->paid($anna, 300, '2026-09-30 22:30:00');                               // 00:30 on 1 October in Vienna
        $bertOrder = $this->paid($bert, 800, '2026-10-03 09:00:00', [], ['listing_slug' => 'fitness']);
        $this->paid($anna, 200, '2026-10-04 12:00:00', ['status' => 'refunded'], ['kind' => 'site']);
        $this->paid($bert, 999, '2026-10-03 10:00:00', ['livemode' => false]);        // Stripe sandbox
        $this->paid($bert, 777, '2026-10-03 11:00:00', ['brand' => 'sofabuilt']);     // the other storefront
        $this->paid($bert, 666, '2026-10-05 22:30:00');                               // 00:30 on 6 October in Vienna
        Project::create(['customer_id' => $bert->id, 'order_id' => $bertOrder->id, 'name' => 'Fit', 'status' => 'BUILDING']);

        $res = $this->werk('overview?from=2026-10-01&to=2026-10-05&since=2026-10-03')->assertOk();

        $res->assertJsonPath('timezone', 'Europe/Vienna')
            ->assertJsonPath('currency', 'EUR')
            ->assertJsonPath('shop.engine', 'node')
            ->assertJsonPath('content', null)
            ->assertJsonPath('seo', null)
            ->assertJsonPath('health.site_health', null)
            ->assertJsonPath('health.updates', null)
            ->assertJsonPath('shop.customers', ['new' => 1, 'returning' => 1])
            ->assertJsonPath('shop.coupons', [])
            ->assertJsonPath('shop.status.processing', 1);
        $this->assertSame([
            ['date' => '2026-10-01', 'revenue' => 300, 'orders' => 1, 'refunds' => 0],
            ['date' => '2026-10-02', 'revenue' => 0, 'orders' => 0, 'refunds' => 0],
            ['date' => '2026-10-03', 'revenue' => 800, 'orders' => 1, 'refunds' => 0],
            ['date' => '2026-10-04', 'revenue' => 200, 'orders' => 1, 'refunds' => 200],
            ['date' => '2026-10-05', 'revenue' => 0, 'orders' => 0, 'refunds' => 0],
        ], $res->json('shop.daily'));
        $this->assertSame([
            ['name' => 'App: fitness', 'quantity' => 1, 'revenue' => 800],
            ['name' => 'Website', 'quantity' => 1, 'revenue' => 200],
        ], $res->json('shop.top_products'));
        $this->assertStringNotContainsString('example.com', $res->getContent());
    }

    public function test_bad_dates_are_refused(): void
    {
        foreach ([
            'overview?from=2026-10-05&to=2026-10-01',
            'overview?from=2026-02-30&to=2026-03-02',
            'overview?from=2026-01-01&to=2026-08-01',              // over 200 days
            'overview?from=2026-10-01&to=2026-10-05&since=2026-09-30',
            'overview',
            'ads?from=2025-01-01&to=2026-10-01',                   // over 400 days
            'business?from=yesterday&to=today',
        ] as $route) {
            $this->werk($route)->assertStatus(422)->assertExactJson(['error' => 'invalid']);
        }
    }

    private function adPlatforms(): void
    {
        config(['services.ads.google' => [
            'developer_token' => '', 'customer_id' => '1234567890', 'login_customer_id' => '', 'manager_id' => '',
            'client_id' => 'cid', 'client_secret' => 'sec', 'refresh_token' => 'rt', 'api_version' => 'v25', 'service_account_json' => '',
        ]]);
        config(['services.ads.meta' => ['token' => 'tok', 'ad_account_id' => 'act_1', 'page_id' => '99', 'api_version' => 'v26.0']]);

        MarketingCampaign::create(['platform' => 'google', 'platform_status' => 'active', 'published_at' => now(),
            'strategy' => ['kind' => 'own', 'name' => 'Appmitki Apps AT'], 'platform_ref' => ['campaign_id' => 'customers/1234567890/campaigns/77']]);
        MarketingCampaign::create(['platform' => 'meta', 'platform_status' => 'paused', 'published_at' => now(),
            'strategy' => ['kind' => 'own', 'name' => 'Appmitki Feed'], 'platform_ref' => ['campaign_id' => '555']]);
        // Not ours to report: a draft that never reached Google, and a customer's campaign.
        MarketingCampaign::create(['platform' => 'google', 'platform_status' => 'draft', 'strategy' => ['kind' => 'own', 'name' => 'Draft']]);
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        $order = $this->paid($customer, 1000, '2026-09-01 10:00:00');
        $project = Project::create(['customer_id' => $customer->id, 'order_id' => $order->id, 'name' => 'Dachdecker', 'status' => 'live']);
        MarketingCampaign::create(['project_id' => $project->id, 'platform' => 'google', 'platform_status' => 'active', 'published_at' => now(),
            'strategy' => ['name' => 'Dachdecker Huber'], 'platform_ref' => ['campaign_id' => 'customers/1234567890/campaigns/88']]);
    }

    private function fakePlatforms(bool $metaFails = false): void
    {
        Http::fake(function (Request $r) use ($metaFails) {
            if (str_contains($r->url(), 'oauth2')) {
                return Http::response(['access_token' => 'at', 'expires_in' => 3600]);
            }
            if (str_contains($r->url(), 'googleAds:search')) {
                $m = fn (int $i, int $c, int $micros, float $conv) => ['impressions' => (string) $i, 'clicks' => (string) $c, 'costMicros' => (string) $micros, 'conversions' => $conv];

                return Http::response(['results' => [
                    ['segments' => ['date' => '2026-10-01'], 'metrics' => $m(1000, 40, 20_000_000, 2.0)],
                    ['segments' => ['date' => '2026-10-03'], 'metrics' => $m(500, 10, 5_500_000, 0.0)],
                ]]);
            }
            if (str_contains($r->url(), 'graph.facebook.com')) {
                if ($metaFails) {
                    return Http::response(['error' => ['message' => 'Invalid OAuth access token']], 400);
                }

                return Http::response(['data' => [
                    ['date_start' => '2026-10-02', 'spend' => '12.50', 'impressions' => '2000', 'inline_link_clicks' => '25',
                        'actions' => [['action_type' => 'lead', 'value' => '3'], ['action_type' => 'offsite_conversion.fb_pixel_lead', 'value' => '3']]],
                ]]);
            }

            return Http::response(['unexpected' => $r->url()], 500);
        });
    }

    public function test_ads_reports_only_appwerks_own_campaigns_from_both_platforms(): void
    {
        $this->adPlatforms();
        $this->fakePlatforms();

        $res = $this->werk('ads?from=2026-10-01&to=2026-10-03')->assertOk();

        $res->assertJsonPath('currency', 'EUR')->assertJsonPath('from', '2026-10-01')->assertJsonPath('to', '2026-10-03');
        $this->assertSame([
            ['platform' => 'google', 'connected' => true, 'spend' => 25.5, 'impressions' => 1500, 'clicks' => 50, 'conversions' => 2,
                'ctr' => 3.33, 'cpc' => 0.51, 'cost_per_conversion' => 12.75, 'error' => null],
            ['platform' => 'meta', 'connected' => true, 'spend' => 12.5, 'impressions' => 2000, 'clicks' => 25, 'conversions' => 3,
                'ctr' => 1.25, 'cpc' => 0.5, 'cost_per_conversion' => 4.17, 'error' => null],
        ], $res->json('platforms'));
        $this->assertSame([
            ['platform' => 'google', 'name' => 'Appmitki Apps AT', 'status' => 'active', 'spend' => 25.5, 'impressions' => 1500, 'clicks' => 50, 'conversions' => 2],
            ['platform' => 'meta', 'name' => 'Appmitki Feed', 'status' => 'paused', 'spend' => 12.5, 'impressions' => 2000, 'clicks' => 25, 'conversions' => 3],
        ], $res->json('campaigns'));
        // Every day of the range, per platform.
        $this->assertCount(6, $res->json('daily'));
        $this->assertSame(['date' => '2026-10-01', 'platform' => 'google', 'spend' => 20, 'clicks' => 40, 'conversions' => 2], $res->json('daily.0'));
        $this->assertSame(['date' => '2026-10-02', 'platform' => 'meta', 'spend' => 12.5, 'clicks' => 25, 'conversions' => 3], $res->json('daily.3'));
        $this->assertStringNotContainsString('Dachdecker', $res->getContent());

        // Reads only, and never the customer's campaign.
        Http::assertNotSent(fn (Request $r) => str_contains($r->body(), 'campaigns/88') || str_contains($r->url(), ':mutate'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com') && $r->method() !== 'GET');

        // A second ask within minutes is answered from the cache.
        $sent = count(Http::recorded());
        $this->werk('ads?from=2026-10-01&to=2026-10-03')->assertOk();
        $this->assertCount($sent, Http::recorded());
    }

    public function test_one_failing_platform_keeps_the_other(): void
    {
        $this->adPlatforms();
        $this->fakePlatforms(metaFails: true);

        $res = $this->werk('ads?from=2026-10-01&to=2026-10-03')->assertOk();

        $res->assertJsonPath('platforms.0.platform', 'google')->assertJsonPath('platforms.0.error', null)->assertJsonPath('platforms.0.spend', 25.5)
            ->assertJsonPath('platforms.1.platform', 'meta')->assertJsonPath('platforms.1.connected', true)->assertJsonPath('platforms.1.spend', 0);
        $this->assertStringContainsString('Invalid OAuth access token', (string) $res->json('platforms.1.error'));
        $this->assertSame(['google'], array_values(array_unique(array_column($res->json('campaigns'), 'platform'))));
    }

    public function test_ads_without_platforms_says_not_connected_and_defaults_to_30_days(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->werk('ads')->assertOk()
            ->assertJsonPath('from', '2026-09-21')->assertJsonPath('to', '2026-10-20')
            ->assertJsonPath('platforms.0.connected', false)->assertJsonPath('platforms.0.error', null)
            ->assertJsonPath('platforms.1.connected', false)
            ->assertJsonPath('campaigns', [])->assertJsonPath('daily', []);
        Http::assertNothingSent();
    }

    public function test_business_counts_the_funnel_and_the_pipeline(): void
    {
        $at = '2026-10-02 10:00:00';
        $event = fn (string $name, ?string $visitor, array $more = []) => AnalyticsEvent::create($more + ['name' => $name, 'visitor' => $visitor, 'created_at' => $at]);
        $event('page_view', 'v1', ['utm_source' => 'meta']);
        $event('page_view', 'v1');
        $event('page_view', 'v2', ['click_id' => 'gclid']);
        $event('page_view', 'v3');
        $event('wizard_step', 'v1', ['props' => ['step' => 'form_start', 'field' => 'idea']]);
        $event('wizard_step', 'v3', ['props' => ['step' => 'proto_build']]);
        $event('prototype_requested', 'v1', ['props' => ['kind' => 'app']]);
        $event('prototype_requested', 'v3', ['props' => ['kind' => 'site']]);
        $event('prototype_requested', 'v2', ['props' => ['kind' => 'app']]);
        $event('quote_created', 'v1');
        $event('checkout_started', 'v1');
        $event('signin_completed', 'v1');
        $event('page_view', 'old', ['created_at' => '2026-09-01 10:00:00']);   // outside the range

        $customer = Customer::create(['email' => 'neu@example.com', 'locale' => 'de', 'created_at' => $at]);
        $order = $this->paid($customer, 400, $at);
        Project::create(['customer_id' => $customer->id, 'order_id' => $order->id, 'name' => 'Neu', 'kind' => 'app', 'status' => 'BUILDING', 'created_at' => $at]);
        $sandbox = $this->paid($customer, 900, $at, ['livemode' => false]);
        Project::create(['customer_id' => $customer->id, 'order_id' => $sandbox->id, 'name' => 'Test', 'status' => 'PAID', 'created_at' => $at]);
        $chat = DeskSession::create(['brand' => 'appmitki', 'ready' => true, 'created_at' => $at]);
        $chat->messages()->create(['role' => 'customer', 'body' => 'Hallo']);
        DeskSession::create(['brand' => 'sofabuilt', 'created_at' => $at]);

        $res = $this->werk('business?from=2026-10-01&to=2026-10-05')->assertOk();

        $res->assertJsonPath('from', '2026-10-01')->assertJsonPath('to', '2026-10-05')
            ->assertJsonPath('traffic', ['visitors' => 3, 'page_views' => 4, 'by_source' => ['meta' => 1, 'google' => 1, 'other' => 1]])
            ->assertJsonPath('funnel', ['form_starts' => 1, 'prototypes_requested' => 3, 'quotes_created' => 1, 'checkouts_started' => 1, 'orders_paid' => 1])
            ->assertJsonPath('prototypes_by_kind', ['app' => 2, 'site' => 1])
            ->assertJsonPath('signups', 1)
            ->assertJsonPath('signins', 1)
            ->assertJsonPath('desk', ['chats' => 1, 'with_message' => 1, 'scope_ready' => 1, 'quotes' => 0])
            ->assertJsonPath('projects_new', ['total' => 1, 'by_kind' => ['app' => 1]])
            ->assertJsonPath('projects_by_status', ['BUILDING' => 1])
            ->assertJsonPath('payments', ['count' => 1, 'gross' => 400, 'refunded' => 0, 'currency' => 'EUR']);
        $this->assertStringNotContainsString('example.com', $res->getContent());
        $this->assertStringNotContainsString('Hallo', $res->getContent());
    }

    public function test_the_hourly_ping_sends_the_contract_body_and_keeps_the_beacon_key(): void
    {
        Http::fake(['api.werk.codemenschen.at/v1/probe/ping' => Http::response(['analytics' => ['key' => 'k_appmitki_123', 'script' => 'https://werk.codemenschen.at/a.js']])]);

        $this->artisan('factory:werk-ping')->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://api.werk.codemenschen.at/v1/probe/ping'
            && $r->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && $r['probe_version'] === Probe::VERSION
            && $r['cms'] === 'node'
            && str_starts_with((string) $r['cms_version'], 'Laravel ')
            && $r['site_url'] === 'https://appmitki.com'
            && $r['features'] === ['overview', 'ads', 'business']);
        $this->assertSame(['key' => 'k_appmitki_123', 'script' => 'https://werk.codemenschen.at/a.js'], Probe::analytics());
        $this->getJson('/api/site-config')->assertOk()->assertJsonPath('werk_analytics.key', 'k_appmitki_123');
        $this->werk('probe')->assertJsonPath('last_ping.ok', true);
    }

    public function test_the_ping_is_hourly_and_only_with_a_token(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'factory:werk-ping'));
        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()->expression);

        config(['services.werk.token' => '']);
        Http::fake();
        $this->artisan('factory:werk-ping')->assertSuccessful();
        Http::assertNothingSent();
        $this->getJson('/api/site-config')->assertOk()->assertJsonPath('werk_analytics', null);
    }

    public function test_a_failed_ping_keeps_the_key_and_a_wrong_site_drops_it(): void
    {
        Setting::write(Probe::ANALYTICS_SETTING, ['key' => 'k_kept_123', 'script' => 'https://werk.codemenschen.at/a.js']);

        $answer = Http::response('down', 503);
        Http::fake(function () use (&$answer) {
            return $answer;
        });
        $this->artisan('factory:werk-ping')->assertSuccessful();
        $this->assertSame('k_kept_123', Probe::analytics()['key']);

        $answer = Http::response(['error' => 'wrong_site'], 409);
        $this->artisan('factory:werk-ping')->assertSuccessful();
        $this->assertNull(Probe::analytics());
    }

    public function test_a_script_from_elsewhere_is_never_handed_to_the_storefront(): void
    {
        $answer = Http::response(['analytics' => ['key' => 'k_appmitki_123', 'script' => 'https://evil.example/a.js']]);
        Http::fake(function () use (&$answer) {
            return $answer;
        });
        $this->artisan('factory:werk-ping')->assertSuccessful();
        $this->assertSame('https://werk.codemenschen.at/a.js', Probe::analytics()['script']);

        // Analytics switched off for the site in Werk: no key, no beacon.
        $answer = Http::response(['analytics' => ['key' => null, 'script' => 'https://werk.codemenschen.at/a.js']]);
        $this->artisan('factory:werk-ping')->assertSuccessful();
        $this->assertNull(Probe::analytics());
    }
}
