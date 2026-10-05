<?php

namespace Tests\Feature;

use App\Mail\CustomerNotice;
use App\Models\DeskSession;
use App\Models\Order;
use App\Models\PipelineRun;
use App\Services\OrderFulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SofabuiltCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function readySession(bool $ready = true): DeskSession
    {
        return DeskSession::create(['door' => 'premium', 'locale' => 'en', 'ready' => $ready, 'scope' => [
            'name' => 'Extra Options', 'purpose' => 'Text fields and checkboxes on products.', 'features' => ['fields'],
            'not_included' => [], 'requires' => ['woocommerce' => true, 'wordpress' => '6.4', 'php' => '8.1'],
            'modules' => [['key' => 'woo', 'qty' => 1], ['key' => 'data', 'qty' => 1]],
        ]]);
    }

    public function test_a_ready_scope_becomes_a_plugin_quote_with_the_launch_options(): void
    {
        $session = $this->readySession();
        $res = $this->postJson("/api/desk/{$session->id}/quote")->assertCreated();
        $this->assertSame(290 + 150 + 90, $res->json('price_eur'));
        $this->assertSame(['salesPage' => 299, 'listing' => 79, 'sellReady' => 199, 'ads' => 129], $res->json('packages'));
        $this->assertSame($res->json('quote_id'), $session->fresh()->quote_id);

        $this->postJson('/api/desk/'.$this->readySession(false)->id.'/quote')->assertStatus(409);
    }

    public function test_paying_a_plugin_order_starts_the_plugin_pipeline_with_the_agreed_scope(): void
    {
        config(['services.stripe.secret' => null, 'services.worker.token' => 't']);
        Http::fake(['*/run' => Http::response(['accepted' => true], 202)]);
        Mail::fake();
        $quote = $this->postJson('/api/desk/'.$this->readySession()->id.'/quote')->json('quote_id');
        $this->postJson('/api/checkout', [
            'quote_id' => $quote, 'email' => 'shop@example.com', 'fagg_waiver' => true, 'terms' => true, 'locale' => 'en',
            // An app package is not on offer for a plugin and is dropped.
            'packages' => ['listing' => true, 'sellReady' => true, 'storePublishing' => true],
        ])->assertStatus(503);
        $order = Order::firstOrFail();
        $this->assertSame('sofabuilt', $order->brand);
        $this->assertSame(['listing' => true, 'sellReady' => true], $order->packages);
        $this->assertSame(530 + 79 + 199, $order->total_one_time_eur);

        $project = app(OrderFulfillment::class)->markPaid($order, 'pi', 609, []);

        $this->assertSame('plugin', $project->kind);
        $this->assertSame('wp-plugin', $project->stack);
        $this->assertSame('Extra Options', $project->name);
        $run = PipelineRun::where('project_id', $project->id)->sole();
        $this->assertSame('product', $run->stage);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/run') && ($r['context']['stack'] ?? null) === 'wp-plugin'
            && ($r['context']['scope']['name'] ?? null) === 'Extra Options' && ($r['context']['sell_ready'] ?? null) === true);
        Mail::assertSent(CustomerNotice::class, fn ($m) => str_contains($m->subjectLine, 'Sofabuilt') && $m->fromName === 'Sofabuilt');
    }

    public function test_a_built_plugin_opens_in_playground_from_its_blueprint(): void
    {
        config(['services.stripe.secret' => null, 'services.worker.token' => 't', 'services.worker.artifacts_path' => sys_get_temp_dir().'/sb-artifacts']);
        Http::fake(['*/run' => Http::response(['accepted' => true], 202)]);
        Mail::fake();
        $quote = $this->postJson('/api/desk/'.$this->readySession()->id.'/quote')->json('quote_id');
        $this->postJson('/api/checkout', ['quote_id' => $quote, 'email' => 'shop@example.com', 'fagg_waiver' => true, 'terms' => true]);
        $project = app(OrderFulfillment::class)->markPaid(Order::firstOrFail(), 'pi', 530, []);
        $this->assertNull($project->previewUrl());

        @mkdir(sys_get_temp_dir()."/sb-artifacts/{$project->id}", 0777, true);
        file_put_contents(sys_get_temp_dir()."/sb-artifacts/{$project->id}/extra-options-0.1.0.zip", 'PK');
        $project->builds()->create(['platform' => 'plugin', 'version' => '0.1.0', 'artifact_path' => "{$project->id}/extra-options-0.1.0.zip", 'status' => 'preview']);

        $this->assertStringStartsWith('https://playground.wordpress.net/?blueprint-url=', $project->previewUrl());
        $steps = $this->getJson("/api/plugin/{$project->id}/blueprint.json")->assertOk()->json('steps');
        // WooCommerce first (the scope needs it), then the plugin from our ZIP.
        $this->assertSame('woocommerce', $steps[0]['pluginData']['slug']);
        $this->assertStringEndsWith("/api/plugin/{$project->id}/plugin.zip", $steps[1]['pluginData']['url']);
        $this->get("/api/plugin/{$project->id}/plugin.zip")->assertOk();
    }

    public function test_a_chrome_extension_is_priced_with_its_own_parts_and_built_on_its_stack(): void
    {
        config(['services.stripe.secret' => null, 'services.worker.token' => 't']);
        Http::fake(['*/run' => Http::response(['accepted' => true], 202)]);
        Mail::fake();
        $session = DeskSession::create(['door' => 'idea', 'locale' => 'en', 'platform' => 'chrome', 'ready' => true, 'scope' => [
            'platform' => 'chrome', 'name' => 'Tab Notes', 'purpose' => 'Notes per website.', 'features' => ['notes'], 'not_included' => [],
            'requires' => ['woocommerce' => false, 'wordpress' => '', 'php' => ''],
            'modules' => [['key' => 'page', 'qty' => 1], ['key' => 'sync', 'qty' => 1], ['key' => 'woo', 'qty' => 1]],
        ]]);

        $res = $this->postJson("/api/desk/{$session->id}/quote")->assertCreated();
        // Chrome parts only: a WordPress key in the scope is not priced.
        $this->assertSame(250 + 120 + 50, $res->json('price_eur'));
        $this->assertSame(['salesPage' => 299, 'listing' => 79, 'ads' => 129], $res->json('packages'));

        $this->postJson('/api/checkout', ['quote_id' => $res->json('quote_id'), 'email' => 'ext@example.com', 'fagg_waiver' => true, 'terms' => true]);
        $project = app(OrderFulfillment::class)->markPaid(Order::firstOrFail(), 'pi', 420, []);
        $this->assertSame('chrome-ext', $project->stack);
        $project->builds()->create(['platform' => 'plugin', 'version' => '0.1.0', 'artifact_path' => 'x.zip', 'status' => 'preview']);
        $this->assertNull($project->previewUrl(), 'Playground is for WordPress only');
    }

    public function test_a_shopify_app_is_priced_with_its_own_parts_and_built_on_its_stack(): void
    {
        config(['services.stripe.secret' => null, 'services.worker.token' => 't']);
        Http::fake(['*/run' => Http::response(['accepted' => true], 202)]);
        Mail::fake();
        $session = DeskSession::create(['door' => 'idea', 'locale' => 'en', 'platform' => 'shopify', 'ready' => true, 'scope' => [
            'platform' => 'shopify', 'name' => 'Size Guide', 'purpose' => 'Size charts on product pages.', 'features' => ['chart'], 'not_included' => [],
            'requires' => ['woocommerce' => false, 'wordpress' => '', 'php' => ''],
            'modules' => [['key' => 'storefront', 'qty' => 1], ['key' => 'products', 'qty' => 1], ['key' => 'woo', 'qty' => 1]],
        ]]);

        $res = $this->postJson("/api/desk/{$session->id}/quote")->assertCreated();
        // Shopify parts only: a WordPress key in the scope is not priced.
        $this->assertSame(350 + 120 + 90, $res->json('price_eur'));
        $this->assertSame(['salesPage' => 299, 'listing' => 99, 'ads' => 129], $res->json('packages'));

        $this->postJson('/api/checkout', ['quote_id' => $res->json('quote_id'), 'email' => 'shop@example.com', 'fagg_waiver' => true, 'terms' => true]);
        $project = app(OrderFulfillment::class)->markPaid(Order::firstOrFail(), 'pi', 560, []);
        $this->assertSame('shopify', $project->stack);
        $project->builds()->create(['platform' => 'plugin', 'version' => '0.1.0', 'artifact_path' => 'x.zip', 'status' => 'preview']);
        $this->assertNull($project->previewUrl(), 'Playground is for WordPress only');
    }

    public function test_the_desk_offers_wordpress_and_shopify_with_their_own_catalogues(): void
    {
        $this->postJson('/api/desk', ['door' => 'idea', 'platform' => 'shopify'])->assertCreated()->assertJsonPath('platform', 'shopify');
        $this->postJson('/api/desk', ['door' => 'idea', 'platform' => 'chrome'])->assertStatus(422);

        $items = $this->getJson('/api/desk/catalog?platform=shopify')->assertOk()->json('items');
        $reviews = collect($items)->firstWhere('id', 'reviews');
        $this->assertSame('$180', $reviews['price'], 'a monthly price is shown per year');
        $this->assertSame(47971, $reviews['reviews']);
    }
}
