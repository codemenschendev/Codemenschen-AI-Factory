<?php

namespace Tests\Feature;

use App\Domain\Sofabuilt\Settings;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Appmitki's apps on the desk: priced from parts, ordered as an app (stack expo). */
class AppDeskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Prices here are build time only; the token cost has its own test (AppDeskTest).
        \App\Domain\Sofabuilt\Settings::write(['token_pct' => 0, 'time_buffer_pct' => 0, 'rate_app' => 60], 'test');
        config(['services.ai_image.base_url' => 'http://model.test', 'services.ai_image.token' => 't', 'services.turnstile.secret' => null]);
    }

    private function chat(array $modules): string
    {
        Http::fake(['model.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'reply' => 'A todo app, got it.',
            'questions' => [],
            'scope' => ['name' => 'Tickly', 'purpose' => 'Tick off your tasks.', 'features' => ['Tick off tasks'], 'not_included' => ['Sharing'], 'modules' => $modules],
            'search' => null,
            'ready' => true,
        ])]]]])]);
        $id = $this->postJson('/api/desk', ['door' => 'idea', 'locale' => 'en', 'platform' => 'app'])->assertCreated()->json('id');
        $this->postJson("/api/desk/$id/messages", ['text' => 'A todo app where I tick off tasks'])->assertOk();

        return $id;
    }

    public function test_a_todo_app_costs_what_its_parts_cost(): void
    {
        $id = $this->chat([['key' => 'screen'], ['key' => 'local_save'], ['key' => 'woo']]);

        $res = $this->getJson("/api/desk/$id")->assertOk();
        $this->assertSame(['base', 'screen', 'local_save'], array_column($res->json('price.lines'), 'key'));
        $res->assertJsonPath('price.build_eur', 8 + 4 + 1)
            ->assertJsonPath('price.hosting_monthly_eur', 0)
            ->assertJsonPath('price.delivery_days', [1, 2]);
        $this->assertArrayNotHasKey('storePublishing', $res->json('price.launch'));

        $quoteId = $this->postJson("/api/desk/$id/quote")->assertCreated()->json('quote_id');
        $quote = Quote::find($quoteId);
        $this->assertSame(['app', 'appmitki', 'mobile', 13, 'A', ['offline']], [$quote->kind, $quote->brand, $quote->platform, $quote->price_eur, $quote->app_type, $quote->features]);
        $this->assertSame('Tickly', $quote->breakdown['scope']['name']);
    }

    public function test_a_part_that_needs_the_server_adds_the_monthly_fee(): void
    {
        $id = $this->chat([['key' => 'screen', 'qty' => 2], ['key' => 'accounts']]);

        $this->getJson("/api/desk/$id")->assertJsonPath('price.build_eur', 8 + 2 * 4 + 5)->assertJsonPath('price.hosting_monthly_eur', 19);
        $quote = Quote::find($this->postJson("/api/desk/$id/quote")->json('quote_id'));
        $this->assertSame(['B', 19, ['auth']], [$quote->app_type, $quote->hosting_monthly_eur, $quote->features]);
    }

    public function test_the_app_desk_stays_open_when_sofabuilt_closes_its_desk(): void
    {
        Settings::write(['desk_open' => false], 'test');
        $this->postJson('/api/desk', ['door' => 'idea', 'platform' => 'app'])->assertCreated()->assertJsonPath('platform', 'app');
        $this->postJson('/api/desk', ['door' => 'idea', 'platform' => 'wordpress'])->assertStatus(503);
    }

    public function test_the_price_is_the_estimated_build_time_at_the_platforms_rate(): void
    {
        // A far too long base (cut to 3 x 8), a complex screen (10 min) and a part with no estimate.
        $id = $this->chat([['key' => 'base', 'minutes' => 500], ['key' => 'screen', 'minutes' => 10], ['key' => 'local_save']]);

        $res = $this->getJson("/api/desk/$id")->assertOk();
        $this->assertSame([24, 10, 1], array_column($res->json('price.lines'), 'minutes'));
        $res->assertJsonPath('price.build_eur', 35)->assertJsonPath('price.build_minutes', 35)->assertJsonPath('price.rate_eur_hour', 60);

        // The admin's hourly rate for apps; WordPress keeps its own.
        Settings::write(['rate_app' => 30], 'test');
        $this->getJson("/api/desk/$id")->assertJsonPath('price.build_eur', 12 + 5 + 1);
        $this->assertSame(300, \App\Domain\Sofabuilt\Platforms::rate('wordpress'));
    }

    public function test_parts_ticked_by_hand_keep_the_estimate_of_the_parts_kept(): void
    {
        $id = $this->chat([['key' => 'base', 'minutes' => 20], ['key' => 'screen', 'minutes' => 10]]);

        $this->postJson("/api/desk/$id/modules", ['modules' => [['key' => 'screen'], ['key' => 'photos']]])->assertOk()
            ->assertJsonPath('price.build_eur', 20 + 10 + 3);
    }

    public function test_the_ai_token_cost_is_added_to_each_part(): void
    {
        Settings::write(['token_pct' => 100], 'test');
        // Screen: 4 min usual, asked for 5000k tokens, cut to 3 x its usual 640k.
        $id = $this->chat([['key' => 'screen', 'tokens_k' => 5000], ['key' => 'local_save']]);

        $res = $this->getJson("/api/desk/$id")->assertOk();
        $lines = collect($res->json('price.lines'))->keyBy('key');
        $this->assertSame(1920, $lines['screen']['tokens_k']);
        // 1920k at Opus 5.5 in the usual mix (91 % cache read at $0.20, 6 % cache write at $5, 1 % input
        // at $4, 2 % output at $20) = $0.922 per million, $1.77, at 0.92 = 1.63 EUR.
        $this->assertEqualsWithDelta(1.63, $lines['screen']['token_eur'], 0.01);
        $this->assertSame(4 + 2, $lines['screen']['eur']);
        $res->assertJsonPath('price.token_model', 'Claude Opus 5.5')->assertJsonPath('price.time_eur', 13);

        // The admin can leave the token cost out.
        Settings::write(['token_pct' => 0], 'test');
        $this->getJson("/api/desk/$id")->assertJsonPath('price.build_eur', 13);
    }

    public function test_the_estimate_gets_a_time_buffer_and_apps_cost_twice_the_hour_of_plugins(): void
    {
        Settings::write(['token_pct' => 100, 'time_buffer_pct' => 20], 'test');
        $id = $this->chat([['key' => 'screen'], ['key' => 'local_save']]);

        // 13 min plus 20 % at 60 an hour, plus the tokens: base 9.6 + 1.09, screen 4.8 + 0.54, local 1.2 + 0.14.
        $res = $this->getJson("/api/desk/$id")->assertJsonPath('price.time_buffer_pct', 20)->assertJsonPath('price.rate_eur_hour', 60);
        $this->assertSame(11 + 5 + 1, $res->json('price.build_eur'));
        // The defaults: ten times 60 for apps and 30 for the rest, tokens ten times their price.
        Settings::write(['rate_app' => 600], 'test');
        $this->assertSame([600, 300, 300, 300], array_map(fn ($p) => \App\Domain\Sofabuilt\Platforms::rate($p), ['app', 'wordpress', 'shopify', 'chrome']));
        $this->assertSame(1000, \App\Domain\Sofabuilt\Settings::defaults()['token_pct']);
    }
}
