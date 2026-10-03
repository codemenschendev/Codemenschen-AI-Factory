<?php

namespace Tests\Feature;

use App\Mail\CustomerNotice;
use App\Models\DeskSession;
use App\Models\Order;
use App\Models\PipelineRun;
use App\Services\OrderFulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame(['salesPage' => 299, 'listing' => 79, 'ads' => 129], $res->json('packages'));
        $this->assertSame($res->json('quote_id'), $session->fresh()->quote_id);

        $this->postJson('/api/desk/'.$this->readySession(false)->id.'/quote')->assertStatus(409);
    }

    public function test_paying_a_plugin_order_creates_a_plugin_project_without_the_app_pipeline(): void
    {
        config(['services.stripe.secret' => null]);
        Mail::fake();
        $quote = $this->postJson('/api/desk/'.$this->readySession()->id.'/quote')->json('quote_id');
        $this->postJson('/api/checkout', [
            'quote_id' => $quote, 'email' => 'shop@example.com', 'fagg_waiver' => true, 'terms' => true, 'locale' => 'en',
            // An app package is not on offer for a plugin and is dropped.
            'packages' => ['listing' => true, 'storePublishing' => true],
        ])->assertStatus(503);
        $order = Order::firstOrFail();
        $this->assertSame('sofabuilt', $order->brand);
        $this->assertSame(['listing' => true], $order->packages);
        $this->assertSame(530 + 79, $order->total_one_time_eur);

        $project = app(OrderFulfillment::class)->markPaid($order, 'pi', 609, []);

        $this->assertSame('plugin', $project->kind);
        $this->assertSame('wp-plugin', $project->stack);
        $this->assertSame('Extra Options', $project->name);
        $this->assertSame(0, PipelineRun::where('project_id', $project->id)->count());
        Mail::assertSent(CustomerNotice::class, fn ($m) => str_contains($m->subjectLine, 'Sofabuilt') && $m->fromName === 'Sofabuilt');
    }
}
