<?php

namespace Tests\Feature;

use App\Domain\Sites\MockupSite;
use App\Jobs\BuildSiteFromMockup;
use App\Models\Order;
use App\Models\Prototype;
use App\Services\OrderFulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A bought prototype that is a design picture (the Codex switch) is built into the website after
 * payment: Claude writes the page from the picture, Codex renders each photograph, and only then
 * does the page go live.
 */
class MockupSiteTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = '<!doctype html><html lang="de"><head><title>AC Nautik</title><style>.shot{height:300px}</style></head><body>'
        .'<h1>Dein Küstenpatent</h1><div class="shot hero" data-shape="wide" data-render="couple steering a motorboat near a rocky island"></div>'
        .'<div class="shot" data-shape="square" data-render="instructor with a VHF radio"></div></body></html>';

    private function png(): string
    {
        $im = imagecreatetruecolor(30, 20);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function mockup(): Prototype
    {
        return Prototype::create(['status' => 'ready', 'kind' => 'site', 'prompt' => 'kuestenpatent-kroatien.at neu gestalten', 'title' => 'Prototyp',
            'ip' => '203.0.113.7', 'expires_at' => now()->addDays(3), 'qa' => ['writer' => 'codex', 'mockup' => true, 'brief' => 'A boat school.'],
            'html' => '<!doctype html><html><body><main><img class="mockup" src="data:image/png;base64,'.base64_encode($this->png()).'" alt=""></main></body></html>']);
    }

    private function fake(): void
    {
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't',
            'services.ai_image.codex_url' => 'http://imagegen.test', 'services.ai_image.codex_token' => 'c']);
        Http::fake([
            'sidecar.test/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => self::PAGE]]]]),
            'imagegen.test/v1/images' => Http::response(['base64' => base64_encode($this->png()), 'mime' => 'image/png']),
        ]);
    }

    /** @return array{0:Order,1:Prototype} */
    private function order(Prototype $preview): array
    {
        config(['services.stripe.secret' => null]);
        $quoteId = $this->postJson('/api/quotes', ['prototype_id' => $preview->id, 'locale' => 'de'])->assertCreated()->json('id');
        $this->postJson('/api/checkout', ['quote_id' => $quoteId, 'email' => 'nautik@example.com', 'fagg_waiver' => true, 'terms' => true])->assertStatus(503);

        return [Order::firstOrFail(), $preview];
    }

    public function test_claude_builds_the_page_from_the_picture_and_codex_renders_each_photo(): void
    {
        $this->fake();

        $out = app(MockupSite::class)->build($this->mockup());

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sidecar.test')
            && $r['messages'][1]['content'][1]['type'] === 'image_url'
            && str_starts_with($r['messages'][1]['content'][1]['image_url']['url'], 'data:image/png;base64,'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'imagegen.test/v1/images')
            && str_contains($r['prompt'], 'couple steering a motorboat') && $r['size'] === '1536x1024' && ! isset($r['mockup']));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'imagegen.test/v1/images') && $r['size'] === '1024x1024');
        $this->assertSame(2, preg_match_all('~data-render="[^"]*"><img src="data:image/~', $out['html']));
        $this->assertFalse($out['qa']['mockup']);
        $this->assertSame(2, $out['qa']['from_mockup']['shots']);
        $this->assertStringNotContainsString('class="mockup"', $out['html']);
    }

    public function test_paying_queues_the_build_and_the_picture_never_goes_live(): void
    {
        Mail::fake();
        Queue::fake();
        [$order, $preview] = $this->order($this->mockup());

        $project = app(OrderFulfillment::class)->markPaid($order, 'pi_test', 29900, ['type' => 'test']);

        Queue::assertPushed(BuildSiteFromMockup::class, fn ($job) => $job->prototypeId === $preview->id);
        $this->assertSame('PAID', $project->fresh()->status);
        $this->assertNull($preview->fresh()->published_at);
        $this->get("/l/{$preview->id}")->assertNotFound();
    }

    public function test_the_build_puts_the_finished_page_live(): void
    {
        Mail::fake();
        Queue::fake();
        [$order, $preview] = $this->order($this->mockup());
        $project = app(OrderFulfillment::class)->markPaid($order, 'pi_test', 29900, ['type' => 'test']);
        $this->fake();

        (new BuildSiteFromMockup($preview->id))->handle(app(MockupSite::class), app(\App\Domain\Sites\SiteService::class));

        $this->assertSame('PUBLISHED', $project->fresh()->status);
        $this->assertDatabaseHas('project_events', ['project_id' => $project->id, 'type' => 'site.built']);
        $this->get("/l/{$preview->id}")->assertOk()->assertSee('Dein Küstenpatent', false)->assertDontSee('class="mockup"', false);
    }
}
