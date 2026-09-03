<?php

namespace Tests\Feature;

use App\Domain\Pricing\Estimator;
use App\Models\Order;
use App\Models\Project;
use App\Services\CareService;
use App\Services\OrderFulfillment;
use App\Services\PipelineOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CareTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(["*" => Http::response(["accepted" => "run"], 202)]); // worker admission at payment / revise dispatch
        config(['services.stripe.secret' => null]);
    }

    /** A released app whose free rounds are used up — the customer would pay per round. */
    private function releasedProject(): Project
    {
        $quote = $this->postJson('/api/quotes', ['idea' => 'club app', 'audience' => 'b2b', 'platform' => 'mobile', 'features' => ['auth']])->json('id');
        $this->postJson('/api/checkout', ['quote_id' => $quote, 'email' => 'c@example.com', 'fagg_waiver' => true, 'terms' => true, 'terms' => true]);
        $project = app(OrderFulfillment::class)->markPaid(Order::latest('created_at')->firstOrFail(), 'pi', 100, [])->fresh();
        $project->update(['status' => 'READY']);
        $project->criteria()->create(['key' => 'boots', 'criterion' => 'app boots', 'kind' => 'automated', 'status' => 'passed']);
        $project->builds()->create(['platform' => 'bundle', 'version' => '0.1.0']);
        $this->token = $project->customer->createToken('portal')->plainTextToken;

        return $project->fresh();
    }

    public function test_care_checkout_needs_the_express_start_consent_and_stripe(): void
    {
        $project = $this->releasedProject();
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/me/projects/{$project->id}/care/checkout", [])
            ->assertUnprocessable();
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/me/projects/{$project->id}/care/checkout", ['fagg_waiver' => true, 'terms' => true, 'terms' => true])
            ->assertStatus(503)
            ->assertJsonPath('payment', 'unconfigured');
        $this->assertDatabaseHas('project_events', ['project_id' => $project->id, 'type' => 'care.checkout_requested']);
    }

    public function test_active_care_makes_change_requests_free_without_touching_the_free_rounds(): void
    {
        $project = $this->releasedProject();
        $orchestrator = app(PipelineOrchestrator::class);
        $this->assertSame('paid', $orchestrator->changeRequestMode($project));

        app(CareService::class)->activate($project, 'sub_123', ['id' => 'evt_1']);
        $project = $project->fresh();
        $this->assertSame('care', $orchestrator->changeRequestMode($project));

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/me/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('care_status', 'active')
            ->assertJsonPath('care_monthly_eur', Estimator::CARE_MONTHLY_EUR)
            ->assertJsonPath('change_request_mode', 'care');

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/me/projects/{$project->id}/change-requests", ['text' => 'Please make the primary button green.'])
            ->assertCreated()
            ->assertJsonPath('status', 'FIXING');
        $cr = $project->changeRequests()->first();
        $this->assertSame(0, $cr->price_eur);
        $this->assertSame('care', $cr->covered_by);
        $this->assertSame('PUBLISHED' === $project->status ? 'PUBLISHED' : 'READY', $project->fresh()->resume_status);
        $this->assertSame(PipelineOrchestrator::MAX_REVISION_ROUNDS, $orchestrator->freeRoundsLeft($project->fresh()));
    }

    public function test_cancel_keeps_care_until_the_month_ends_and_stripe_deletion_closes_it(): void
    {
        $project = $this->releasedProject();
        $care = app(CareService::class);
        $care->activate($project, 'sub_123');

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/me/projects/{$project->id}/care/cancel")
            ->assertOk()
            ->assertJsonPath('care_status', 'active');
        $this->assertNotNull($project->fresh()->care_ends_at);
        $this->assertSame('care', app(PipelineOrchestrator::class)->changeRequestMode($project->fresh()));
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/me/projects/{$project->id}/care/cancel")
            ->assertStatus(409);

        $care->onSubscriptionEvent('sub_123', 'canceled', true);
        $project = $project->fresh();
        $this->assertSame('canceled', $project->care_status);
        $this->assertSame('paid', app(PipelineOrchestrator::class)->changeRequestMode($project));
        $this->assertDatabaseHas('project_events', ['project_id' => $project->id, 'type' => 'care.canceled']);
    }

    public function test_failed_payment_suspends_care_and_recovery_restores_it(): void
    {
        $project = $this->releasedProject();
        $care = app(CareService::class);
        $care->activate($project, 'sub_9');
        $care->onSubscriptionEvent('sub_9', 'past_due', false);
        $this->assertSame('past_due', $project->fresh()->care_status);
        $this->assertSame('paid', app(PipelineOrchestrator::class)->changeRequestMode($project->fresh()));
        $care->onSubscriptionEvent('sub_9', 'active', false);
        $this->assertSame('active', $project->fresh()->care_status);
        $care->onSubscriptionEvent('sub_unknown', 'active', false); // no such project: ignored
    }
}
