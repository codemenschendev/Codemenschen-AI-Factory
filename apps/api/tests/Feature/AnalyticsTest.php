<?php

namespace Tests\Feature;

use App\Domain\Analytics\AnalyticsReport;
use App\Models\AnalyticsEvent;
use App\Models\Customer;
use App\Models\Order;
use App\Services\OrderFulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Safari/604.1';

    private function beacon(array $body, string $ua = self::UA)
    {
        return $this->call('POST', '/api/t', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => $ua, 'REMOTE_ADDR' => '203.0.113.7'], json_encode($body));
    }

    public function test_a_page_view_is_stored_with_its_source_and_without_address_or_browser(): void
    {
        $this->beacon([
            'name' => 'page_view', 'path' => '/de/create', 'locale' => 'de', 'referrer' => 'https://www.google.com/search?q=app+bauen',
            'utm_source' => 'newsletter', 'utm_medium' => 'email', 'click_id' => 'gclid',
        ])->assertNoContent();

        $e = AnalyticsEvent::sole();
        $this->assertSame('page_view', $e->name);
        $this->assertSame('google.com', $e->referrer, 'only the host of the referrer');
        $this->assertSame('newsletter', $e->utm_source);
        $this->assertSame('gclid', $e->click_id);
        $this->assertSame('mobile', $e->device);
        $this->assertSame(16, strlen($e->visitor));
        $this->assertStringNotContainsString('203.0.113.7', json_encode($e->getAttributes()));
        $this->assertStringNotContainsString('iPhone', json_encode($e->getAttributes()));
    }

    public function test_unknown_events_and_bots_are_dropped(): void
    {
        $this->beacon(['name' => 'order_paid', 'path' => '/'])->assertNoContent();
        $this->beacon(['name' => 'page_view', 'path' => '/'], 'Googlebot/2.1')->assertNoContent();
        $this->beacon(['nonsense' => true])->assertNoContent();

        $this->assertSame(0, AnalyticsEvent::count());
    }

    public function test_the_funnel_follows_one_visitor_from_page_to_paid_order_and_names_the_source(): void
    {
        $this->beacon(['name' => 'page_view', 'path' => '/de', 'utm_source' => 'google']);
        $headers = ['HTTP_USER_AGENT' => self::UA, 'REMOTE_ADDR' => '203.0.113.7'];
        $quote = $this->withServerVariables($headers)->postJson('/api/quotes', [
            'idea' => 'club app', 'audience' => 'b2b', 'platform' => 'mobile', 'features' => ['auth'],
        ])->json('id');
        config(['services.stripe.secret' => null, 'queue.default' => 'sync', 'services.buzz.alert_dir' => null, 'services.openclaw.hook_url' => null]);
        Http::fake(['*' => Http::response(['accepted' => true], 202)]);
        $this->withServerVariables($headers)->postJson('/api/checkout', ['quote_id' => $quote, 'email' => 'c@example.com', 'fagg_waiver' => true, 'terms' => true]);
        app(OrderFulfillment::class)->markPaid(Order::latest('created_at')->firstOrFail(), 'pi', 400, []);

        $names = AnalyticsEvent::orderBy('id')->pluck('name')->all();
        $this->assertSame(['page_view', 'quote_created', 'checkout_started', 'order_paid'], $names);
        $this->assertSame(1, AnalyticsEvent::whereIn('name', ['page_view', 'quote_created', 'checkout_started'])->distinct()->count('visitor'), 'one visitor through the day');

        $report = app(AnalyticsReport::class)->summary(7);
        $this->assertSame([1, 0, 1, 1, 1], array_column($report['funnel'], 'visitors'));
        $this->assertSame(400, $report['totals']['revenue_eur']);
        $this->assertSame([['key' => 'google', 'orders' => 1]], $report['paid_sources']);
    }

    public function test_prototype_visitors_who_go_on_to_a_real_app_are_counted(): void
    {
        $this->beacon(['name' => 'prototype_view', 'path' => '/de/p/x', 'props' => ['kind' => 'app']]);
        $this->beacon(['name' => 'cta_click', 'path' => '/de/p/x', 'props' => ['cta' => 'prototype_make_real']]);
        $this->beacon(['name' => 'cta_click', 'path' => '/de/p/x', 'props' => ['cta' => 'prototype_another', 'nested' => ['no']]]);

        $report = app(AnalyticsReport::class)->summary(1);
        $this->assertSame(1, $report['prototypes']['viewed']);
        $this->assertSame(1, $report['prototypes']['made_real']);
        $this->assertArrayNotHasKey('nested', AnalyticsEvent::latest('id')->first()->props, 'only flat values are kept');
    }

    public function test_only_admins_read_the_report(): void
    {
        $customer = Customer::create(['email' => 'c@example.com', 'locale' => 'de']);
        $this->withHeaders(['Authorization' => 'Bearer '.$customer->createToken('portal')->plainTextToken])
            ->getJson('/api/admin/analytics')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $admin = Customer::create(['email' => 'ops@example.com', 'locale' => 'de', 'is_admin' => true]);
        $this->withHeaders(['Authorization' => 'Bearer '.$admin->createToken('portal')->plainTextToken])
            ->getJson('/api/admin/analytics?days=7')->assertOk()->assertJsonPath('days', 7);
    }
}
