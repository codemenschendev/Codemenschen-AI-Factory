<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Services\OrderFulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketingTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret' => null, 'services.worker.token' => 't']);
        Http::fake(['*/run' => Http::response(['accepted' => true], 202)]);

        $quote = $this->postJson('/api/quotes', [
            'idea' => 'x', 'audience' => 'consumer', 'platform' => 'mobile', 'features' => [],
        ])->json('id');
        $this->postJson('/api/checkout', [
            'quote_id' => $quote, 'email' => 'mkt@example.com', 'fagg_waiver' => true, 'terms' => true,
            'packages' => ['marketingLaunch' => true], 'ad_budget_monthly_eur' => 500,
        ]);
        $order = Order::firstOrFail();
        $this->project = app(OrderFulfillment::class)->markPaid($order, 'pi', 100, []);
        $this->project->update(['status' => 'READY']);
        $this->token = $order->customer->createToken('portal')->plainTextToken;
    }

    private function completeMarketingRun(): void
    {
        $run = PipelineRun::where('project_id', $this->project->id)
            ->where('stage', 'marketing')->latest('created_at')->firstOrFail();
        $token = $run->getAttributes()['callback_token'];
        $this->postJson("/api/internal/runs/{$run->id}/complete", [
            'status' => 'succeeded',
            'output' => ['campaigns' => [[
                'platform' => 'google',
                'strategy' => ['audience' => 'DACH consumers', 'angle' => 'one job'],
                'creatives' => [
                    ['kind' => 'headline', 'locale' => 'de', 'content' => 'Einfach erledigt'],
                    ['kind' => 'ad_copy', 'locale' => 'de', 'content' => 'Ehrliche Copy.'],
                ],
            ]]],
        ], ['Authorization' => "Bearer $token"])->assertOk();
    }

    public function test_generation_requires_marketing_package(): void
    {
        $this->project->order->update(['packages' => []]);
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/me/projects/{$this->project->id}/marketing/generate")
            ->assertForbidden();
    }

    public function test_generate_stores_campaigns_with_ad_budget_and_customer_decides(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/me/projects/{$this->project->id}/marketing/generate")
            ->assertOk();
        $this->completeMarketingRun();

        $campaign = $this->project->campaigns()->firstOrFail();
        $this->assertSame('pending_approval', $campaign->status);
        $this->assertSame(500, $campaign->ad_budget_monthly_eur);
        $this->assertSame(2, $campaign->creatives()->count());

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/me/projects/{$this->project->id}/campaigns/{$campaign->id}/decide", ['decision' => 'approved'])
            ->assertOk()->assertJsonPath('status', 'approved');

        // No double-deciding
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/me/projects/{$this->project->id}/campaigns/{$campaign->id}/decide", ['decision' => 'rejected'])
            ->assertStatus(409);
    }

    public function test_generation_requires_ready_or_later(): void
    {
        $this->project->update(['status' => 'TESTING']);
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/me/projects/{$this->project->id}/marketing/generate")
            ->assertStatus(409);
    }
}
