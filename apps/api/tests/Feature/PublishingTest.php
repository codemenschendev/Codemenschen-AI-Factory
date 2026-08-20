<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Project;
use App\Services\OrderFulfillment;
use App\Services\PublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublishingTest extends TestCase
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
            'idea' => 'x', 'audience' => 'b2b', 'platform' => 'mobile', 'features' => ['auth'],
        ])->json('id');
        $this->postJson('/api/checkout', [
            'quote_id' => $quote, 'email' => 'pub@example.com', 'fagg_waiver' => true,
            'packages' => ['storePublishing' => true],
        ]);
        $order = Order::firstOrFail();
        $this->project = app(OrderFulfillment::class)->markPaid($order, 'pi', 100, []);
        $this->project->update(['status' => 'READY']);
        $this->token = $order->customer->createToken('portal')->plainTextToken;
    }

    public function test_customer_starts_publishing_and_attaches_accounts(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/me/projects/{$this->project->id}/publishing/start", ['stores' => ['apple', 'google']])
            ->assertOk()
            ->assertJsonPath('status', 'PUBLISHING');

        $this->assertSame(2, $this->project->submissions()->count());
        $this->assertSame('waiting_account', $this->project->submissions()->first()->status);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/me/projects/{$this->project->id}/publishing/account", [
                'store' => 'apple', 'account_ref' => 'ASC Team 12345',
            ])->assertOk()->assertJsonPath('status', 'preparing');
    }

    public function test_all_submissions_live_marks_project_published(): void
    {
        $publishing = app(PublishingService::class);
        $publishing->start($this->project, ['apple', 'google'], 'test');

        [$apple, $google] = $this->project->submissions()->orderBy('store')->get();
        $publishing->setStatus($apple, 'live', null, 'test');
        $this->assertSame('PUBLISHING', $this->project->fresh()->status);

        $publishing->setStatus($google, 'live', null, 'test');
        $this->assertSame('PUBLISHED', $this->project->fresh()->status);
    }

    public function test_publishing_requires_ready_project(): void
    {
        $this->project->update(['status' => 'TESTING']);
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/me/projects/{$this->project->id}/publishing/start", ['stores' => ['apple']])
            ->assertStatus(409);
    }
}
