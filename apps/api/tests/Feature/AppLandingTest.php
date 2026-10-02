<?php

namespace Tests\Feature;

use App\Domain\Sites\AppLanding;
use App\Jobs\BuildPrototype;
use App\Models\Order;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\Prototype;
use App\Services\OrderFulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AppLandingTest extends TestCase
{
    use RefreshDatabase;

    private function paidApp(array $packages): Project
    {
        config(['services.stripe.secret' => null, 'services.worker.token' => 't']);
        Http::fake(['*/run' => Http::response(['accepted' => true], 202)]);
        $quote = $this->postJson('/api/quotes', [
            'idea' => 'Tisch reservieren im Wiener Kaffeehaus', 'audience' => 'consumer', 'platform' => 'mobile', 'features' => ['notif'],
        ])->json('id');
        $this->postJson('/api/checkout', [
            'quote_id' => $quote, 'email' => 'cafe@example.com', 'fagg_waiver' => true, 'terms' => true, 'packages' => $packages,
        ]);

        return app(OrderFulfillment::class)->markPaid(Order::firstOrFail(), 'pi', 100, []);
    }

    private function completeProduct(Project $project): void
    {
        $run = PipelineRun::where('project_id', $project->id)->where('stage', 'product')->firstOrFail();
        $run->update(['status' => 'running']); // the worker's start, faked
        $this->postJson("/api/internal/runs/{$run->id}/complete", [
            'status' => 'succeeded',
            'output' => ['criteria' => [['key' => 'stamp-card', 'criterion' => 'Guests collect a stamp per coffee', 'kind' => 'automated']]],
        ], ['Authorization' => 'Bearer '.$run->getAttributes()['callback_token']])->assertOk();
    }

    public function test_a_bought_landing_page_is_written_from_the_spec(): void
    {
        Queue::fake();
        $project = $this->paidApp(['landingPage' => true]);
        $this->assertNull($project->prototype, 'nothing before the spec exists');

        $this->completeProduct($project);

        $page = $project->fresh()->prototype;
        $this->assertSame('site', $page->kind);
        $this->assertNull($page->expires_at);
        $this->assertStringContainsString('Guests collect a stamp per coffee', $page->prompt);
        $this->assertStringContainsString('Push-Benachrichtigungen', $page->prompt);
        Queue::assertPushed(BuildPrototype::class, fn ($j) => $j->prototypeId === $page->id);

        // A second product run (a retry) does not write a second page.
        app(AppLanding::class)->start($project->fresh());
        $this->assertSame(1, Prototype::where('project_id', $project->id)->count());
    }

    public function test_without_the_package_no_page_is_written(): void
    {
        Queue::fake();
        $project = $this->paidApp([]);
        $this->completeProduct($project);
        $this->assertNull($project->fresh()->prototype);
    }

    public function test_the_ready_page_goes_online_with_the_marketing_kit(): void
    {
        Queue::fake();
        $project = $this->paidApp(['landingPage' => true, 'marketingLaunch' => true]);
        $this->completeProduct($project);
        $page = $project->fresh()->prototype;
        $page->update(['status' => 'ready', 'html' => '<html lang="de"><head><title>Ladner</title></head><body><form><input type="email"></form></body></html>']);
        $project->update(['ai_visibility' => [
            'llms_txt' => "# Ladner\n> Tisch reservieren\n\nLanding page: {LANDING_URL}",
            'json_ld' => ['@type' => 'MobileApplication', 'name' => 'Ladner'],
            'faq' => [['locale' => 'de', 'q' => 'Welche App?', 'a' => 'Ladner.'], ['locale' => 'en', 'q' => 'Which app?', 'a' => 'Ladner.']],
        ]]);

        AppLanding::ready($page->fresh());
        $this->assertNotNull($page->fresh()->published_at);
        $this->assertSame('SPECIFICATION', $project->fresh()->status, 'the app project keeps its own status');

        $res = $this->get("/l/{$page->id}")->assertOk();
        $html = $res->getContent();
        $this->assertStringContainsString('"@type":"MobileApplication"', $html);
        $this->assertStringContainsString('"@type":"FAQPage"', $html);
        $this->assertStringContainsString('Welche App?', $html);
        $this->assertStringNotContainsString('Which app?', $html);
        $this->assertNull($res->headers->get('X-Robots-Tag'));

        $this->get("/l/{$page->id}/llms.txt")->assertOk()
            ->assertSee('Landing page: '.url("/l/{$page->id}"), false);
    }

    public function test_a_design_picture_is_built_into_a_page_before_it_goes_online(): void
    {
        Queue::fake();
        $project = $this->paidApp(['landingPage' => true]);
        $this->completeProduct($project);
        $page = $project->fresh()->prototype;
        $page->update(['status' => 'ready', 'html' => '<img class="mockup" src="data:image/png;base64,AA">', 'qa' => ['mockup' => true]]);

        AppLanding::ready($page->fresh());

        $this->assertNull($page->fresh()->published_at);
        Queue::assertPushed(\App\Jobs\BuildSiteFromMockup::class, fn ($j) => $j->prototypeId === $page->id);
    }
}
