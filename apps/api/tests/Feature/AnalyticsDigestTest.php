<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AnalyticsDigestTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/digest-'.uniqid();
        mkdir($this->dir.'/requests', 0777, true);
        config(['services.buzz.alert_dir' => $this->dir, 'services.openclaw.hook_url' => null]);
        AnalyticsEvent::create(['name' => 'page_view', 'visitor' => 'a1', 'path' => '/de', 'utm_source' => 'google', 'device' => 'mobile']);
        AnalyticsEvent::create(['name' => 'page_view', 'visitor' => 'b2', 'path' => '/de/create', 'device' => 'desktop']);
        AnalyticsEvent::create(['name' => 'quote_created', 'visitor' => 'a1']);
    }

    private function posted(): array
    {
        return array_map(fn ($f) => json_decode(file_get_contents($f), true), glob($this->dir.'/*.json'));
    }

    public function test_the_digest_lists_the_funnel_with_step_rates(): void
    {
        $this->assertSame(0, Artisan::call('factory:analytics', ['--days' => 7]));
        $out = Artisan::output();

        $this->assertStringContainsString('Visitors 2, page views 2', $out);
        $this->assertStringContainsString('- interest: 1 (50% of the step before)', $out, 'a quote counts as interest');
        $this->assertStringContainsString('- quote: 1 (100% of the step before)', $out);
        $this->assertStringContainsString('Campaigns: google 1', $out);
        $this->assertSame([], $this->posted(), 'printing posts nothing');
    }

    public function test_the_weekly_post_goes_to_the_agents_channel(): void
    {
        $this->artisan('factory:analytics --post')->assertSuccessful();

        $posted = $this->posted();
        $this->assertCount(1, $posted);
        $this->assertSame('agents', $posted[0]['channel']);
        $this->assertStringStartsWith('Appwerk analytics, last 7 days', $posted[0]['message']);
        $this->assertStringNotContainsString('—', $posted[0]['message']);
    }

    public function test_a_stats_request_from_the_bot_is_answered_once_with_its_period(): void
    {
        file_put_contents($this->dir.'/requests/stats-abc.json', json_encode(['days' => 30]));
        file_put_contents($this->dir.'/requests/stats-bad.json', json_encode(['days' => 5000]));

        $this->artisan('factory:analytics --requests')->assertSuccessful();
        $this->artisan('factory:analytics --requests')->assertSuccessful();

        $messages = array_column($this->posted(), 'message');
        sort($messages);
        $this->assertCount(2, $messages);
        $this->assertStringStartsWith('Appwerk analytics, last 30 days', $messages[0]);
        $this->assertStringStartsWith('Appwerk analytics, last 7 days', $messages[1], 'an out-of-range period falls back to 7');
        $this->assertSame([], glob($this->dir.'/requests/*.json'));
    }
}
