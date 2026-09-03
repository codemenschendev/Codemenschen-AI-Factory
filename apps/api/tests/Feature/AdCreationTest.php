<?php

namespace Tests\Feature;

use App\Domain\Ai\AdScriptWriter;
use App\Models\Order;
use App\Models\Project;
use App\Models\ProjectAd;
use App\Services\OrderFulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The choices the customer makes in the portal have to survive the trip to the render job: the
 * canvas, the action the ad closes on, and the shape of the story. Each one is only worth
 * anything if it is still in the row when the queue picks it up.
 */
class AdCreationTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret' => null, 'services.worker.token' => 't']);
        Http::fake(['*/run' => Http::response(['accepted' => true], 202)]);
        Queue::fake();

        $quote = $this->postJson('/api/quotes', [
            'idea' => 'salon app', 'audience' => 'consumer', 'platform' => 'mobile', 'features' => [],
        ])->json('id');
        $this->postJson('/api/checkout', [
            'quote_id' => $quote, 'email' => 'ads@example.com', 'fagg_waiver' => true, 'terms' => true,
        ]);
        $order = Order::firstOrFail();
        $this->project = app(OrderFulfillment::class)->markPaid($order, 'pi', 100, []);
        $this->auth = ['Authorization' => 'Bearer '.$order->customer->createToken('portal')->plainTextToken];
    }

    private function create(array $extra = []): TestResponse
    {
        return $this->postJson("/api/me/projects/{$this->project->id}/ads", array_merge([
            'prompt' => 'Ad for a hair salon in Vienna',
        ], $extra), $this->auth);
    }

    public function test_the_4_5_feed_canvas_is_rendered_at_1080x1350(): void
    {
        $this->create(['format' => 'feed_portrait'])->assertStatus(202);

        $this->assertSame('1080x1350', ProjectAd::firstOrFail()->spec['size']);
    }

    public function test_the_angle_and_the_goal_reach_the_render_spec(): void
    {
        $this->create(['goal' => 'booking', 'angle' => 'before_after'])->assertStatus(202);

        $spec = ProjectAd::firstOrFail()->spec;
        $this->assertSame('booking', $spec['goal']);
        $this->assertSame('before_after', $spec['angle']);
    }

    public function test_an_angle_that_is_not_in_the_table_is_refused(): void
    {
        $this->create(['angle' => 'jazzhands'])->assertStatus(422)->assertJsonValidationErrors('angle');
        $this->assertNull(ProjectAd::first());
    }

    public function test_no_choice_leaves_both_open_for_the_copywriter(): void
    {
        $this->create()->assertStatus(202);

        $spec = ProjectAd::firstOrFail()->spec;
        $this->assertNull($spec['goal']);
        $this->assertNull($spec['angle']);
    }

    public function test_the_portal_is_handed_the_lists_it_has_to_offer(): void
    {
        $res = $this->getJson('/api/me/ads', $this->auth)->assertOk();

        $this->assertSame(array_keys(AdScriptWriter::GOALS), $res->json('goals'));
        $this->assertSame(array_keys(AdScriptWriter::ANGLES), $res->json('angles'));
    }
}
