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
        $res->assertJsonPath('price.build_eur', 35)
            ->assertJsonPath('price.hosting_monthly_eur', 0)
            ->assertJsonPath('price.delivery_days', [1, 2]);
        $this->assertArrayNotHasKey('storePublishing', $res->json('price.launch'));

        $quoteId = $this->postJson("/api/desk/$id/quote")->assertCreated()->json('quote_id');
        $quote = Quote::find($quoteId);
        $this->assertSame(['app', 'appmitki', 'mobile', 35, 'A', ['offline']], [$quote->kind, $quote->brand, $quote->platform, $quote->price_eur, $quote->app_type, $quote->features]);
        $this->assertSame('Tickly', $quote->breakdown['scope']['name']);
    }

    public function test_a_part_that_needs_the_server_adds_the_monthly_fee(): void
    {
        $id = $this->chat([['key' => 'screen', 'qty' => 2], ['key' => 'accounts']]);

        $this->getJson("/api/desk/$id")->assertJsonPath('price.build_eur', 65)->assertJsonPath('price.hosting_monthly_eur', 19);
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
        // A complex screen (40 min), a far too long base (cut to 3 x 15) and a part with no estimate.
        $id = $this->chat([['key' => 'base', 'minutes' => 500], ['key' => 'screen', 'minutes' => 40], ['key' => 'local_save']]);

        $res = $this->getJson("/api/desk/$id")->assertOk();
        $this->assertSame([45, 40, 5], array_column($res->json('price.lines'), 'minutes'));
        $res->assertJsonPath('price.build_eur', 90)->assertJsonPath('price.build_minutes', 90)->assertJsonPath('price.rate_eur_hour', 60);

        // The admin's hourly rate for apps; WordPress keeps its own.
        Settings::write(['rate_app' => 30], 'test');
        $this->getJson("/api/desk/$id")->assertJsonPath('price.build_eur', 23 + 20 + 3);
        $this->assertSame(60, \App\Domain\Sofabuilt\Platforms::rate('wordpress'));
    }

    public function test_parts_ticked_by_hand_keep_the_estimate_of_the_parts_kept(): void
    {
        $id = $this->chat([['key' => 'base', 'minutes' => 20], ['key' => 'screen', 'minutes' => 30]]);

        $this->postJson("/api/desk/$id/modules", ['modules' => [['key' => 'screen'], ['key' => 'photos']]])->assertOk()
            ->assertJsonPath('price.build_eur', 20 + 30 + 10);
    }
}
