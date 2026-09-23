<?php

namespace Tests\Feature;

use App\Domain\Ads\Conversions;
use App\Jobs\SendAdConversion;
use App\Models\AdConversion;
use App\Models\Customer;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Conversion tracking. The lines defended: nothing is reported for a visitor who did not allow it,
 * a platform that is not set up keeps the result waiting instead of losing it, and the matching
 * data is gone once a result was sent.
 */
class AdConversionsTest extends TestCase
{
    use RefreshDatabase;

    private const FBCLID = 'IwAR0abcDEF_123-xyz';

    private const GCLID = 'Cj0KCQjw_abc-DEF123';

    private function click(array $over = []): string
    {
        return json_encode($over + ['consent' => true, 'fbclid' => self::FBCLID, 'gclid' => self::GCLID,
            'ts' => (now()->subHour()->getTimestamp()) * 1000, 'url' => 'https://appwerk.codemenschen.at/de?fbclid='.self::FBCLID]);
    }

    private function quote(array $headers = []): Quote
    {
        $id = $this->withHeaders($headers)->postJson('/api/quotes', ['listing_slug' => 'countbee'])
            ->assertCreated()->json('id');

        return Quote::find($id);
    }

    private function meta(): void
    {
        config(['services.ads.meta.pixel_id' => '1234567890', 'services.ads.meta.capi_token' => 'capi', 'services.ads.meta.api_version' => 'v26.0']);
    }

    private function google(): void
    {
        config(['services.ads.google' => [
            'developer_token' => '', 'customer_id' => '1234567890', 'login_customer_id' => '',
            'manager_id' => '', 'client_id' => 'cid', 'client_secret' => 'sec',
            'refresh_token' => 'rt', 'api_version' => 'v25', 'service_account_json' => '',
            'conversion_lead' => '111', 'conversion_purchase' => '222',
        ]]);
    }

    public function test_nothing_is_kept_or_reported_without_consent(): void
    {
        Queue::fake();
        $plain = $this->quote();
        $refused = $this->quote(['X-Ad-Click' => $this->click(['consent' => false])]);

        $this->assertNull($plain->ad_click);
        $this->assertNull($refused->ad_click);
        $this->assertSame(0, AdConversion::count());
        Queue::assertNothingPushed();
    }

    public function test_a_consented_click_is_kept_with_the_quote_and_reported_as_a_lead(): void
    {
        Queue::fake();
        $quote = $this->quote(['X-Ad-Click' => $this->click()]);

        $this->assertSame(self::FBCLID, $quote->ad_click['fbclid']);
        $this->assertNotEmpty($quote->ad_click['ip']);
        $this->assertEqualsCanonicalizing(['meta', 'google'], AdConversion::where('event', 'lead')->pluck('platform')->all());
        Queue::assertPushed(SendAdConversion::class, 2);
    }

    public function test_a_platform_not_set_up_keeps_the_result_waiting(): void
    {
        Queue::fake();
        $this->quote(['X-Ad-Click' => $this->click(['gclid' => null])]);
        $row = AdConversion::first();

        app(Conversions::class)->send($row);

        $row->refresh();
        $this->assertSame('pending', $row->status);
        $this->assertSame(0, $row->attempts);
        $this->assertStringContainsString('META_PIXEL_ID', $row->error);
    }

    public function test_meta_gets_the_lead_and_the_purchase_with_its_value(): void
    {
        Queue::fake();
        $this->meta();
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
        $quote = $this->quote(['X-Ad-Click' => $this->click(['gclid' => null])]);
        app(Conversions::class)->purchase($quote, 900, 'Kunde@Example.com ');

        foreach (AdConversion::all() as $row) {
            app(Conversions::class)->send($row);
        }

        $this->assertSame(2, AdConversion::where('status', 'sent')->whereNull('match')->count());
        Http::assertSent(function ($r) {
            $event = json_decode($r['data'], true)[0];

            return str_contains($r->url(), '/v26.0/1234567890/events')
                && $event['event_name'] === 'Purchase'
                && $event['custom_data'] === ['currency' => 'EUR', 'value' => 900]
                && $event['user_data']['em'] === [hash('sha256', 'kunde@example.com')]
                && str_starts_with($event['user_data']['fbc'], 'fb.1.')
                && str_ends_with($event['user_data']['fbc'], '.'.self::FBCLID);
        });
    }

    public function test_google_gets_a_click_conversion_with_consent_and_a_refusal_is_kept(): void
    {
        Queue::fake();
        $this->google();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'googleads.googleapis.com/*' => Http::sequence()
                ->push(['results' => [[]]])
                ->push(['partialFailureError' => ['message' => 'The click is too old.']]),
        ]);
        $this->quote(['X-Ad-Click' => $this->click(['fbclid' => null])]);
        $this->quote(['X-Ad-Click' => $this->click(['fbclid' => null])]);
        [$first, $second] = AdConversion::orderBy('id')->get()->all();

        app(Conversions::class)->send($first);
        app(Conversions::class)->send($second);

        $this->assertSame('sent', $first->fresh()->status);
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame(1, $second->fresh()->attempts);
        $this->assertStringContainsString('too old', $second->fresh()->error);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'customers/1234567890:uploadClickConversions')
            && $r['conversions'][0]['gclid'] === self::GCLID
            && $r['conversions'][0]['conversionAction'] === 'customers/1234567890/conversionActions/111'
            && $r['conversions'][0]['consent']['adUserData'] === 'GRANTED');
    }

    public function test_a_result_past_the_platforms_window_expires(): void
    {
        Queue::fake();
        $this->meta();
        $this->quote(['X-Ad-Click' => $this->click(['gclid' => null])]);
        $row = AdConversion::first();
        $row->update(['happened_at' => now()->subDays(8)]);

        app(Conversions::class)->send($row);

        $this->assertSame('expired', $row->fresh()->status);
        $this->assertNull($row->fresh()->match);
    }

    public function test_the_console_shows_setup_and_rows_to_an_admin_only(): void
    {
        Queue::fake();
        $this->quote(['X-Ad-Click' => $this->click()]);
        $admin = Customer::create(['email' => 'chef@example.com', 'locale' => 'de', 'is_admin' => true]);
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/conversions')->assertOk()
            ->assertJsonPath('setup.meta.lead', 'META_PIXEL_ID')
            ->assertJsonCount(2, 'rows')
            ->assertJsonMissingPath('rows.0.match');
        $this->actingAs($customer, 'sanctum')->getJson('/api/admin/conversions')->assertForbidden();
    }
}
