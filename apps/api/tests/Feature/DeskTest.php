<?php

namespace Tests\Feature;

use App\Domain\Sofabuilt\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Prices here are build time only; the token cost has its own test (AppDeskTest).
        // At 1,200 an hour a minute is 20 EUR, so the prices stay readable; no buffer, no tokens.
        \App\Domain\Sofabuilt\Settings::write(['token_pct' => 0, 'time_buffer_pct' => 0, 'rate_wordpress' => 1200, 'rate_shopify' => 1200, 'rate_chrome' => 1200], 'test');
        config(['services.ai_image.base_url' => 'http://model.test', 'services.ai_image.token' => 't', 'services.turnstile.secret' => null]);
    }

    private function fakeModel(array $reply): void
    {
        Http::fake([
            'model.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode($reply)]]]]),
            'api.wordpress.org/*' => Http::response(['plugins' => [[
                'name' => 'Wishlist &amp; Co', 'slug' => 'some-wishlist', 'active_installs' => 900000, 'rating' => 88,
                'num_ratings' => 1200, 'last_updated' => '2026-09-01 10:00pm GMT',
            ]], 'slug' => 'x', 'name' => 'X', 'active_installs' => 1000, 'rating' => 90]),
        ]);
    }

    public function test_a_turn_keeps_the_scope_and_prices_it_from_the_module_list(): void
    {
        $this->fakeModel([
            'reply' => 'Got it — a wishlist for WooCommerce.',
            'questions' => [['q' => 'Share by e-mail?', 'options' => ['Yes', 'No']]],
            'scope' => ['name' => 'Keepsake Lists', 'purpose' => 'Wishlists for shops.', 'features' => ['save products'],
                'not_included' => ['price drop alerts'], 'requires' => ['woocommerce' => true],
                'modules' => [['key' => 'woo'], ['key' => 'email'], ['key' => 'teleport'], ['key' => 'external_api', 'qty' => 9]]],
            'search' => 'woocommerce wishlist',
            'ready' => true,
        ]);
        $id = $this->postJson('/api/desk', ['door' => 'idea', 'locale' => 'en'])->assertCreated()->json('id');

        $res = $this->postJson("/api/desk/$id/messages", ['text' => 'A wishlist for my WooCommerce shop'])->assertOk();

        $res->assertJsonPath('scope.name', 'Keepsake Lists')
            ->assertJsonPath('ready', true)
            ->assertJsonPath('research.0.slug', 'some-wishlist')
            ->assertJsonPath('research.0.name', 'Wishlist & Co')
            ->assertJsonPath('messages.1.meta.questions.0.q', 'Share by e-mail?');
        $this->assertStringNotContainsString('—', $res->json('messages.1.body'));
        // Unknown keys never reach the price; a repeatable module is capped.
        $this->assertSame(['woo', 'email', 'external_api'], array_column($res->json('scope.modules'), 'key'));
        // 20 EUR a minute (setUp), without tokens.
        $this->assertSame(20 * (3 + 3 + 1 + 3 * 4), $res->json('price.build_eur'));
    }

    public function test_a_turn_without_a_new_scope_keeps_the_old_one(): void
    {
        $turn = fn (array $r) => ['choices' => [['message' => ['content' => json_encode($r)]]]];
        Http::fake([
            'model.test/*' => Http::sequence()
                ->push($turn(['reply' => 'First.', 'scope' => ['name' => 'A', 'modules' => [['key' => 'block']]], 'ready' => false]))
                ->push($turn(['reply' => 'Second.', 'scope' => null, 'ready' => true])),
            'api.wordpress.org/*' => Http::response([]),
        ]);
        $id = $this->postJson('/api/desk', ['door' => 'premium'])->json('id');
        $this->postJson("/api/desk/$id/messages", ['text' => 'hello there'])->assertOk();

        $this->postJson("/api/desk/$id/messages", ['text' => 'and more'])->assertOk()
            ->assertJsonPath('scope.name', 'A')->assertJsonPath('ready', true)->assertJsonPath('price.build_eur', 20 * (3 + 1));
    }

    public function test_a_model_failure_keeps_the_question_and_says_so(): void
    {
        Http::fake(['model.test/*' => Http::response('down', 502), 'api.wordpress.org/*' => Http::response([])]);
        $id = $this->postJson('/api/desk', ['door' => 'idea'])->json('id');
        $this->postJson("/api/desk/$id/messages", ['text' => 'hello there'])->assertStatus(503)
            ->assertJsonPath('error', 'unavailable')->assertJsonCount(1, 'messages');
    }

    public function test_a_chat_has_a_turn_limit(): void
    {
        $this->fakeModel(['reply' => 'ok']);
        $id = $this->postJson('/api/desk', ['door' => 'idea'])->json('id');
        $session = \App\Models\DeskSession::find($id);
        foreach (range(1, 30) as $i) {
            $session->messages()->create(['role' => 'customer', 'body' => "m$i"]);
        }
        $this->postJson("/api/desk/$id/messages", ['text' => 'one more'])->assertStatus(429)->assertJsonPath('error', 'limit');
    }

    public function test_the_catalogue_lists_premium_plugins_with_a_price_for_an_own_version(): void
    {
        $this->fakeModel(['reply' => 'x']);
        $items = $this->getJson('/api/desk/catalog')->assertOk()->json('items');
        $this->assertNotEmpty($items);
        $this->assertGreaterThanOrEqual(20 * 3, $items[0]['own_from_eur']);
    }

    public function test_base_is_always_priced_once(): void
    {
        $q = Pricing::quote(['modules' => [['key' => 'base'], ['key' => 'base']]]);
        $this->assertSame(20 * 3, $q['build_eur']);
        $this->assertFalse($q['too_big']);
    }

    public function test_the_customer_picks_parts_and_the_model_keeps_them(): void
    {
        $session = \App\Models\DeskSession::create(['door' => 'idea', 'locale' => 'en', 'scope' => [
            'name' => 'A', 'purpose' => 'p', 'features' => ['x'], 'not_included' => [], 'requires' => ['woocommerce' => false],
            'modules' => [['key' => 'woo', 'qty' => 1, 'why' => 'shop'], ['key' => 'email', 'qty' => 1, 'why' => 'mails']],
        ]]);

        $res = $this->postJson("/api/desk/{$session->id}/modules", ['modules' => [['key' => 'woo'], ['key' => 'block'], ['key' => 'nope'], ['key' => 'base']]])
            ->assertOk();
        $this->assertSame(['woo', 'block'], array_column($res->json('scope.modules'), 'key'));
        $this->assertSame(20 * (3 + 3 + 1), $res->json('price.build_eur'));
        $this->assertNotEmpty($res->json('module_options'));

        // The next turn may reword features but not bring e-mails back.
        $this->fakeModel(['reply' => 'Updated.', 'scope' => ['name' => 'A', 'features' => ['y'], 'modules' => [['key' => 'woo'], ['key' => 'email']]], 'ready' => true]);
        $this->postJson("/api/desk/{$session->id}/messages", ['text' => 'ok then'])->assertOk()
            ->assertJsonPath('scope.features.0', 'y')
            ->assertJsonPath('price.build_eur', 20 * (3 + 3 + 1));
    }
}
