<?php

namespace Tests\Feature;

use App\Domain\Analytics\ValidationReport;
use App\Models\AnalyticsEvent;
use App\Models\Customer;
use App\Models\LandingSignup;
use App\Models\MarketingCampaign;
use App\Models\Prototype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The validation report: the funnel of one campaign and a verdict against goals set beforehand. */
class ValidationReportTest extends TestCase
{
    use RefreshDatabase;

    private Customer $owner;

    private Prototype $campaign;

    private Prototype $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = Customer::create(['email' => 'owner@example.com', 'locale' => 'de']);
        $this->campaign = Prototype::create(['kind' => 'campaign', 'status' => 'ready', 'prompt' => 'x', 'ip' => '1.2.3.4', 'title' => 'Brot-Abo',
            'customer_id' => $this->owner->id, 'expires_at' => now()->addDays(30)]);
        $this->site = Prototype::create(['parent_id' => $this->campaign->id, 'kind' => 'site', 'status' => 'ready', 'prompt' => 'x', 'ip' => '1.2.3.4',
            'html' => '<html lang="de"></html>', 'customer_id' => $this->owner->id, 'published_at' => now()->subDays(8), 'expires_at' => now()->addDays(30)]);
    }

    /** $visitors people a day ago, $confirmed of them confirmed, $pending signed up and did not. */
    private function traffic(int $visitors, int $confirmed, int $pending = 0, bool $fromAds = true): void
    {
        foreach (range(1, $visitors) as $n) {
            AnalyticsEvent::create(['name' => 'landing_view', 'visitor' => 'v'.$n, 'utm_source' => $fromAds ? 'meta' : null,
                'props' => ['prototype' => $this->site->id], 'created_at' => now()->subDay()]);
        }
        foreach (range(1, $confirmed + $pending) as $n) {
            if ($n > $confirmed + $pending || $confirmed + $pending === 0) {
                break;
            }
            LandingSignup::create(['prototype_id' => $this->site->id, 'email' => "p$n@example.com", 'consent' => 'x',
                'status' => $n <= $confirmed ? 'confirmed' : 'pending', 'confirmed_at' => $n <= $confirmed ? now()->subDay() : null]);
        }
    }

    private function test(float $spent, array $goals = ['rate' => 0.10, 'cpl' => 5.0]): MarketingCampaign
    {
        return MarketingCampaign::create(['prototype_id' => $this->campaign->id, 'platform' => 'meta', 'status' => 'approved',
            'platform_status' => 'paused', 'strategy' => ['kind' => 'validation', 'goals' => $goals], 'spend_cap_eur' => 150,
            'spent_eur' => $spent, 'impressions' => 20000, 'link_clicks' => 240, 'ends_at' => now()->subHours(2)]);
    }

    public function test_both_goals_met_is_a_go(): void
    {
        $this->traffic(200, 30, 5);
        $this->test(120);

        $r = app(ValidationReport::class)->build($this->campaign);

        $this->assertSame('go', $r['verdict']);
        $this->assertSame([200, 200, 35, 30, 0.15, 4.0, 0.012], [$r['funnel']['visitors'], $r['funnel']['visitors_from_ads'],
            $r['funnel']['signups'], $r['funnel']['confirmed'], $r['funnel']['rate'], $r['funnel']['cost_per_signup_eur'], $r['funnel']['ctr']]);
        [$lo, $hi] = $r['funnel']['rate_range'];
        $this->assertTrue($lo > 0.10 && $lo < 0.15 && $hi > 0.15 && $hi < 0.22);
    }

    public function test_the_verdicts(): void
    {
        $g = ['rate' => 0.10, 'cpl' => 5.0];
        $this->assertSame('too_early', ValidationReport::verdict(30, 0.5, 0, 15, null, $g));
        $this->assertSame('no_go', ValidationReport::verdict(300, 0.03, 150, 9, 16.67, $g));
        $this->assertSame('unclear', ValidationReport::verdict(300, 0.12, 300, 36, 8.33, $g));
        $this->assertSame('unclear', ValidationReport::verdict(300, 0.08, 50, 24, 2.08, $g));
        $this->assertSame('go', ValidationReport::verdict(300, 0.12, 0, 36, null, $g));
        $this->assertSame('no_go', ValidationReport::verdict(300, 0.0, 150, 0, null, $g));
    }

    public function test_only_the_owner_reads_the_report(): void
    {
        $this->traffic(10, 1);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/prototypes/{$this->campaign->id}/report")->assertOk()
            ->assertJson(['verdict' => 'too_early', 'funnel' => ['visitors' => 10, 'confirmed' => 1]]);
        $this->actingAs(Customer::create(['email' => 'x@example.com', 'locale' => 'de']), 'sanctum')
            ->getJson("/api/prototypes/{$this->campaign->id}/report")->assertForbidden();
    }

    public function test_the_report_is_mailed_once_when_the_test_is_over(): void
    {
        config(['mail.default' => 'array']);
        $this->traffic(100, 4);
        $test = $this->test(150);
        $test->update(['ends_at' => now()->addDay()]);

        $this->artisan('factory:validation-reports')->expectsOutput('sent 0');

        $test->update(['ends_at' => now()->subHour()]);
        $this->artisan('factory:validation-reports')->expectsOutput('sent 1');
        $this->artisan('factory:validation-reports')->expectsOutput('sent 0');

        $mail = app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage();
        $this->assertSame('Dein Kampagnen-Bericht: NO-GO', $mail->getSubject());
        $body = $mail->getTextBody();
        $this->assertStringContainsString('Anmelderate: 4,0 %', $body);
        $this->assertStringContainsString('Kosten pro bestätigter Anmeldung: 37,50 €', $body);
        $this->assertStringNotContainsString('—', $body);
    }

    public function test_without_a_test_the_report_comes_after_a_week_live(): void
    {
        config(['mail.default' => 'array']);
        $this->site->update(['published_at' => now()->subDays(3)]);
        $this->artisan('factory:validation-reports')->expectsOutput('sent 0');

        $this->site->update(['published_at' => now()->subDays(7)->subHour()]);
        $this->artisan('factory:validation-reports')->expectsOutput('sent 1');
    }
}
