<?php

namespace Tests\Feature;

use App\Domain\Ads\Conversions;
use App\Jobs\SendAdConversion;
use App\Models\AdConversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Funnel steps for Meta: the pixel's twin through the Conversions API, one event id for both. */
class MetaFunnelEventTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = ['consent' => true, 'event' => 'ChatOpen', 'event_id' => '5b7d3c1e-9f00-4a1b-8c2d-0e1f2a3b4c5d',
        'url' => 'https://appmitki.com/de/prototype', 'fbp' => 'fb.1.1793000000000.1234567890'];

    public function test_a_consented_step_is_queued_with_the_browsers_event_id(): void
    {
        Queue::fake();
        $this->postJson('/api/meta-event', self::BODY)->assertStatus(202)->assertJson(['queued' => true]);
        $row = AdConversion::sole();
        $this->assertSame(['meta', 'ChatOpen', self::BODY['event_id'], null], [$row->platform, $row->event, $row->event_id, $row->click_id]);
        $this->assertSame(self::BODY['fbp'], $row->match['fbp']);
        Queue::assertPushed(SendAdConversion::class, 1);

        // The same event twice (a retried beacon) is one row and one call.
        $this->postJson('/api/meta-event', self::BODY)->assertStatus(202);
        $this->assertSame(1, AdConversion::count());
        Queue::assertPushed(SendAdConversion::class, 1);
    }

    public function test_without_consent_or_with_an_unknown_step_nothing_is_kept(): void
    {
        Queue::fake();
        $this->postJson('/api/meta-event', ['consent' => false] + self::BODY)->assertStatus(422);
        $this->postJson('/api/meta-event', ['event' => 'Purchase'] + self::BODY)->assertStatus(422);
        // No browser id and no click: nothing Meta could match, so no row.
        $this->postJson('/api/meta-event', array_diff_key(self::BODY, ['fbp' => 1]))->assertStatus(202)->assertJson(['queued' => false]);
        $this->assertSame(0, AdConversion::count());
    }

    public function test_the_step_goes_to_meta_under_its_own_name_with_the_shared_event_id(): void
    {
        config(['services.ads.meta.pixel_id' => '408896953700952', 'services.ads.meta.capi_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
        Queue::fake();
        $this->postJson('/api/meta-event', ['event' => 'Scroll75', 'fbclid' => 'IwAR1234567890abc', 'clicked_at' => 1793000000000] + self::BODY)->assertStatus(202);

        app(Conversions::class)->send(AdConversion::sole());
        $this->assertSame('sent', AdConversion::sole()->status);
        Http::assertSent(function ($r) {
            $e = json_decode($r['data'], true)[0];

            return $e['event_name'] === 'Scroll75' && $e['event_id'] === self::BODY['event_id']
                && str_starts_with($e['user_data']['fbc'], 'fb.1.') && $e['user_data']['fbp'] === self::BODY['fbp'];
        });
    }
}
