<?php

namespace Tests\Feature;

use App\Models\DeskSession;
use App\Models\Order;
use App\Models\Project;
use App\Services\CareService;
use App\Services\OrderFulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Improving a Sofabuilt plugin in the console: a new feature is priced from the parts list and paid before it is built. */
class PluginFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected array $replies = [];

    protected array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Prices here are build time only; the token cost has its own test (AppDeskTest).
        \App\Domain\Sofabuilt\Settings::write(['token_pct' => 0], 'test');
        config(['services.stripe.secret' => null, 'services.worker.token' => 't', 'queue.default' => 'sync', 'services.change_chat.enabled' => false,
            'services.buzz.alert_dir' => null, 'services.openclaw.hook_url' => null]);
        Mail::fake();
        Http::fake([
            '*/run' => Http::response(['accepted' => true], 202),
            '*/change-chat' => function ($request) {
                $this->asked[] = $request->data();

                return Http::response(array_shift($this->replies) ?? ['reply' => 'ok', 'scope' => 'in']);
            },
        ]);
    }

    protected function plugin(): Project
    {
        $session = DeskSession::create(['door' => 'idea', 'locale' => 'en', 'platform' => 'wordpress', 'ready' => true, 'scope' => [
            'platform' => 'wordpress', 'name' => 'Quick FAQ', 'purpose' => 'FAQ blocks.', 'features' => ['faq'], 'not_included' => [],
            'requires' => ['woocommerce' => false, 'wordpress' => '6.4', 'php' => '8.1'], 'modules' => [['key' => 'block', 'qty' => 1]],
        ]]);
        $quote = $this->postJson("/api/desk/{$session->id}/quote")->json('quote_id');
        $this->postJson('/api/checkout', ['quote_id' => $quote, 'email' => 'p@example.com', 'fagg_waiver' => true, 'terms' => true]);
        $project = app(OrderFulfillment::class)->markPaid(Order::firstOrFail(), 'pi', 164, [])->fresh();
        $project->update(['status' => 'READY']);
        $project->builds()->create(['platform' => 'plugin', 'version' => '1.0.0', 'artifact_path' => 'x.zip']);

        return $project->fresh();
    }

    protected function as(Project $project): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$project->customer->createToken('portal')->plainTextToken];
    }

    public function test_a_new_feature_gets_a_price_from_its_parts_and_waits_for_payment(): void
    {
        $project = $this->plugin();
        $this->withHeaders($this->as($project))->getJson("/api/me/projects/{$project->id}")
            ->assertJsonPath('change_chat', true)->assertJsonPath('platform', 'wordpress')->assertJsonPath('brand', 'sofabuilt');

        $this->replies = [['reply' => 'The summary shows the price.', 'scope' => 'feature',
            'items' => [['text' => 'A contact form under every FAQ block']], 'modules' => [['key' => 'form'], ['key' => 'email'], ['key' => 'base']]]];
        $res = $this->withHeaders($this->as($project))->postJson("/api/me/projects/{$project->id}/messages", ['body' => 'Add a contact form'])->assertCreated();
        $this->assertStringContainsString('Sofabuilt', $this->asked[0]['system']);
        $this->assertStringContainsString('"feature"', $this->asked[0]['system']);
        $card = $res->json('messages.1.meta.card');
        $this->assertSame('feature', $card['mode']);
        $this->assertSame(41 * (2 + 1), $card['price_eur'], 'form + email, never the base');

        $this->withHeaders($this->as($project))->postJson("/api/me/projects/{$project->id}/messages/confirm", ['fagg_waiver' => true]);
        $cr = $project->changeRequests()->firstOrFail();
        $this->assertSame('feature', $cr->kind);
        $this->assertSame('awaiting_payment', $cr->status);
        $this->assertSame(123, $cr->price_eur);
    }

    public function test_care_takes_its_discount_off_a_new_feature(): void
    {
        $project = $this->plugin();
        app(CareService::class)->activate($project, 'sub', ['id' => 'e']);
        $this->replies = [['reply' => 'ok', 'scope' => 'feature', 'items' => [['text' => 'Import FAQs from a spreadsheet']], 'modules' => [['key' => 'import_export'], ['key' => 'data']]]];
        $res = $this->withHeaders($this->as($project))->postJson("/api/me/projects/{$project->id}/messages", ['body' => 'Import from Excel'])->assertCreated();
        $this->assertSame((int) round(41 * (2 + 2) * 0.8), $res->json('messages.1.meta.card.price_eur'));
        $this->assertSame(20, $res->json('messages.1.meta.card.discount_pct'));
    }
}
