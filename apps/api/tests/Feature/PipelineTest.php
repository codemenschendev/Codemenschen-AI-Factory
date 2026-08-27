<?php

namespace Tests\Feature;

use App\Domain\Pricing\Estimator;
use App\Models\Order;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Services\OrderFulfillment;
use App\Services\PipelineOrchestrator;
use App\Services\RevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret' => null, 'services.worker.token' => 't', 'queue.default' => 'sync']);
        Http::fake(['*/run' => Http::response(['accepted' => true], 202)]);
    }

    private function paidProject(bool $waiver = true): Project
    {
        $quote = $this->postJson('/api/quotes', [
            'idea' => 'club app', 'audience' => 'b2b', 'platform' => 'mobile', 'features' => ['auth'],
        ])->json('id');
        $this->postJson('/api/checkout', [
            'quote_id' => $quote, 'email' => 'c@example.com', 'fagg_waiver' => $waiver,
        ]);

        return app(OrderFulfillment::class)
            ->markPaid(Order::latest('created_at')->firstOrFail(), 'pi', 100, [])->fresh();
    }

    /** Worker reports a stage result via the public callback endpoint. */
    private function completeStage(Project $project, string $stage, array $output = [], string $status = 'succeeded'): void
    {
        $run = PipelineRun::where('project_id', $project->id)->where('stage', $stage)
            ->where('status', 'running')->latest('created_at')->firstOrFail();
        $run->update(['status' => 'running']);
        $token = $run->getAttributes()['callback_token'];
        $this->postJson("/api/internal/runs/{$run->id}/complete", [
            'status' => $status, 'output' => $output, 'error' => $status === 'failed' ? 'boom' : null,
        ], ['Authorization' => "Bearer $token"])->assertOk();
    }

    public function test_payment_with_waiver_starts_specification(): void
    {
        $project = $this->paidProject();
        $this->assertSame('SPECIFICATION', $project->status);
        $this->assertSame('running', $project->runs()->where('stage', 'product')->first()->status);
    }

    public function test_no_waiver_defers_pipeline_until_tick(): void
    {
        $project = $this->paidProject(false);
        $this->assertSame('PAID', $project->status);
        $this->assertCount(0, $project->runs);

        $project->update(['build_starts_at' => now()->subMinute()]);
        $this->artisan('pipeline:tick')->assertSuccessful();
        $this->assertSame('SPECIFICATION', $project->fresh()->status);
    }

    public function test_happy_path_reaches_review_then_ready(): void
    {
        $project = $this->paidProject();
        $this->completeStage($project, 'product', ['criteria' => [
            ['key' => 'boots', 'criterion' => 'app boots', 'kind' => 'automated'],
        ]]);
        $this->completeStage($project, 'uiux');
        $this->assertSame('BUILDING', $project->fresh()->status);
        $this->completeStage($project, 'coding');
        $this->assertSame('TESTING', $project->fresh()->status);
        $this->completeStage($project, 'test', [
            'report' => ['passed' => 1, 'failed' => 0],
            'criteria_results' => ['boots' => 'passed'],
        ]);
        $this->completeStage($project, 'release', ['builds' => [
            ['platform' => 'bundle', 'version' => '0.1.0', 'artifact_path' => 'x/build.tar.gz'],
        ]]);

        $project = $project->fresh();
        $this->assertSame('REVIEW', $project->status);
        $this->assertCount(1, $project->builds);

        app(PipelineOrchestrator::class)->approveReview($project, 'test');
        $project = $project->fresh();
        $this->assertSame('READY', $project->status);

        // READY kicks off store-asset generation (MVP 2); only the ordered
        // store-listing languages are kept even if the model emits more.
        $project->order->update(['store_locales' => ['de']]);
        $this->completeStage($project, 'assets', ['assets' => [
            ['kind' => 'name', 'locale' => 'de', 'content' => 'ClubApp'],
            ['kind' => 'subtitle', 'locale' => 'de', 'content' => 'Für Vereine'],
            ['kind' => 'description', 'locale' => 'en', 'content' => 'An honest description.'],
        ]]);
        $this->assertSame('READY', $project->fresh()->status);
        $this->assertSame(['de', 'de'], $project->storeAssets()->pluck('locale')->all());
        $this->assertDatabaseHas('project_events', ['project_id' => $project->id, 'type' => 'assets.generated']);
        $this->assertSame(2, $project->events()->where('type', 'assets.generated')->first()->payload['count']);

        // Only now the installable build: EAS costs quota and queue time, so it
        // runs after the customer approved the web preview, never per change round.
        $this->assertTrue($project->runs()->where('stage', 'build')->exists());
        $this->completeStage($project, 'build', ['builds' => [
            ['platform' => 'android', 'version' => '0.1.0', 'artifact_path' => 'x/app-0.1.0.apk'],
        ]]);
        $this->assertSame('READY', $project->fresh()->status);
        $this->assertSame(['bundle', 'android'], $project->builds()->orderBy('id')->pluck('platform')->all());
        $this->assertDatabaseHas('project_events', ['project_id' => $project->id, 'type' => 'build.ready']);
    }

    public function test_failed_installable_build_keeps_the_approved_app_ready(): void
    {
        $project = $this->paidProject();
        $project->update(['status' => 'READY']);
        app(PipelineOrchestrator::class)->dispatch($project, 'build', 'test');
        for ($i = 1; $i <= PipelineOrchestrator::MAX_STAGE_ATTEMPTS; $i++) {
            $this->completeStage($project, 'build', [], 'failed');
        }
        $this->assertSame(PipelineOrchestrator::MAX_STAGE_ATTEMPTS, $project->runs()->where('stage', 'build')->count());
        $this->assertSame('READY', $project->fresh()->status);
        $this->assertDatabaseHas('project_events', ['project_id' => $project->id, 'type' => 'build.failed']);
    }

    public function test_failing_tests_enter_fix_loop_and_fail_after_three_attempts(): void
    {
        $project = $this->paidProject();
        $this->completeStage($project, 'product', ['criteria' => [
            ['key' => 'boots', 'criterion' => 'app boots', 'kind' => 'automated'],
        ]]);
        $this->completeStage($project, 'uiux');
        $this->completeStage($project, 'coding');

        for ($i = 1; $i <= 3; $i++) {
            $this->completeStage($project, 'test', [
                'report' => ['passed' => 0, 'failed' => 1],
                'criteria_results' => ['boots' => 'failed'],
            ]);
            $this->assertSame('FIXING', $project->fresh()->status, "fix attempt $i");
            $this->completeStage($project, 'fix');
            $this->assertSame('TESTING', $project->fresh()->status);
        }

        $this->completeStage($project, 'test', [
            'report' => ['passed' => 0, 'failed' => 1],
            'criteria_results' => ['boots' => 'failed'],
        ]);
        $project = $project->fresh();
        $this->assertSame('FAILED', $project->status);
        $this->assertStringContainsString('fix attempts', $project->failed_reason);
    }

    /** Put a paid project straight into REVIEW with a green build. */
    private function reviewedProject(): Project
    {
        $project = $this->paidProject();
        $project->update(['status' => 'REVIEW']);
        $project->criteria()->create(['key' => 'boots', 'criterion' => 'app boots', 'kind' => 'automated', 'status' => 'passed']);
        $project->builds()->create(['platform' => 'bundle', 'version' => '0.1.0']);

        return $project->fresh();
    }

    public function test_change_request_revises_retests_and_returns_to_review(): void
    {
        $project = $this->reviewedProject();
        $token = $project->customer->createToken('portal')->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/me/projects/{$project->id}/change-requests", ['text' => 'short'])
            ->assertUnprocessable();
        $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/me/projects/{$project->id}/change-requests", ['text' => 'Please make the primary button green.'])
            ->assertCreated()
            ->assertJsonPath('status', 'FIXING')
            ->assertJsonPath('round', 1);

        $project = $project->fresh();
        $this->assertSame(1, $project->revision_rounds);
        $this->assertSame('in_progress', $project->changeRequests()->first()->status);
        $this->assertSame('running', $project->runs()->where('stage', 'revise')->first()->status);

        $this->completeStage($project, 'revise', ['done' => true, 'summary' => 'Primary button is green now.']);
        $this->assertSame('TESTING', $project->fresh()->status);
        $this->assertSame('done', $project->changeRequests()->first()->status);
        $this->assertSame('Primary button is green now.', $project->changeRequests()->first()->agent_summary);

        $this->completeStage($project, 'test', ['report' => ['passed' => 1, 'failed' => 0], 'criteria_results' => ['boots' => 'passed']]);
        $this->completeStage($project, 'release', ['builds' => [['platform' => 'bundle', 'version' => '0.1.1', 'artifact_path' => 'x/b.tar.gz']]]);
        $project = $project->fresh();
        $this->assertSame('REVIEW', $project->status);
        $this->assertCount(2, $project->builds);

        // Portal payload carries the rounds + history.
        $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/me/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('revision_rounds', 1)
            ->assertJsonPath('max_revision_rounds', PipelineOrchestrator::MAX_REVISION_ROUNDS)
            ->assertJsonPath('change_requests.0.status', 'done');
    }

    public function test_out_of_scope_change_request_returns_to_review_without_rebuild(): void
    {
        $project = $this->reviewedProject();
        app(PipelineOrchestrator::class)->requestChanges($project, 'Add a full chat system with video calls.', 'test');
        $this->completeStage($project, 'revise', ['done' => true, 'declined' => 'A chat system is a new feature outside the ordered scope.']);

        $project = $project->fresh();
        $this->assertSame('REVIEW', $project->status);
        $this->assertSame('out_of_scope', $project->changeRequests()->first()->status);
        $this->assertFalse($project->runs()->where('stage', 'test')->exists());
    }

    public function test_free_change_requests_are_capped_then_become_paid(): void
    {
        $project = $this->reviewedProject();
        $o = app(PipelineOrchestrator::class);
        for ($i = 1; $i <= PipelineOrchestrator::MAX_REVISION_ROUNDS; $i++) {
            $this->assertSame('free', $o->changeRequestMode($project->fresh()));
            $o->requestChanges($project->fresh(), "Change number $i please.", 'test');
            $this->completeStage($project, 'revise', ['done' => true, 'declined' => 'no']);
            $this->assertSame('REVIEW', $project->fresh()->status);
        }
        $this->assertSame(0, $o->freeRoundsLeft($project->fresh()));
        $this->assertSame('paid', $o->changeRequestMode($project->fresh()));
        $this->expectExceptionMessage('Express start consent');
        $o->requestChanges($project->fresh(), 'One more change please.', 'test'); // no waiver
    }

    public function test_paid_change_request_on_a_published_app_returns_it_to_published(): void
    {
        $project = $this->reviewedProject();
        $project->update(['status' => 'PUBLISHED']);
        $token = $project->customer->createToken('portal')->plainTextToken;

        // Stripe is not configured in tests → quoted, but payment "unconfigured".
        $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/me/projects/{$project->id}/change-requests", ['text' => 'Make the header blue please.', 'fagg_waiver' => true])
            ->assertStatus(503)
            ->assertJsonPath('payment', 'unconfigured');
        $cr = $project->changeRequests()->first();
        $this->assertSame('awaiting_payment', $cr->status);
        $this->assertSame(Estimator::REVISION_PRICE_EUR, $cr->price_eur);
        $this->assertNotNull($cr->fagg_waiver_at);
        $this->assertSame('PUBLISHED', $project->fresh()->status);

        app(RevisionService::class)->markPaid($cr, 'pi_rev', $cr->price_eur, ['id' => 'evt_1']);
        app(RevisionService::class)->markPaid($cr->fresh(), 'pi_rev', $cr->price_eur, ['id' => 'evt_1']); // idempotent
        $project = $project->fresh();
        $this->assertSame('FIXING', $project->status);
        $this->assertSame('PUBLISHED', $project->resume_status);
        $this->assertSame(1, $project->revision_rounds);
        $this->assertSame(PipelineOrchestrator::MAX_REVISION_ROUNDS, app(PipelineOrchestrator::class)->freeRoundsLeft($project));
        $this->assertSame(1, $project->runs()->where('stage', 'revise')->count());

        $this->completeStage($project, 'revise', ['done' => true, 'summary' => 'Header is blue.']);
        $this->completeStage($project, 'test', ['report' => ['passed' => 1, 'failed' => 0], 'criteria_results' => ['boots' => 'passed']]);
        $this->completeStage($project, 'release', ['builds' => [['platform' => 'bundle', 'version' => '0.1.1', 'artifact_path' => 'x/b.tar.gz']]]);
        $this->assertSame('REVIEW', $project->fresh()->status);

        app(PipelineOrchestrator::class)->approveReview($project->fresh(), 'test');
        $project = $project->fresh();
        $this->assertSame('PUBLISHED', $project->status);
        $this->assertNull($project->resume_status);
        $this->assertTrue($project->runs()->where('stage', 'assets')->exists());
    }

    public function test_declined_paid_revision_restores_status_and_flags_refund(): void
    {
        $project = $this->reviewedProject();
        $project->update(['status' => 'READY']);
        $cr = app(PipelineOrchestrator::class)->requestChanges($project, 'Add a whole CRM module.', 'test', true, '127.0.0.1');
        app(RevisionService::class)->markPaid($cr, 'pi', $cr->price_eur, []);
        $this->completeStage($project, 'revise', ['done' => true, 'declined' => 'A CRM module is a new feature.']);

        $project = $project->fresh();
        $this->assertSame('READY', $project->status);
        $this->assertSame('out_of_scope', $cr->fresh()->status);
        $this->assertTrue($project->events()->where('type', 'changes.refund_needed')->exists());
    }

    public function test_failed_revise_stage_hands_the_project_back_to_review(): void
    {
        $project = $this->reviewedProject();
        app(PipelineOrchestrator::class)->requestChanges($project, 'Rename the app title everywhere.', 'test');
        for ($i = 1; $i <= PipelineOrchestrator::MAX_STAGE_ATTEMPTS; $i++) {
            $this->completeStage($project, 'revise', [], 'failed');
        }
        $project = $project->fresh();
        $this->assertSame('REVIEW', $project->status);
        $this->assertNull($project->failed_reason);
        $this->assertSame('failed', $project->changeRequests()->first()->status);
    }

    public function test_ready_is_blocked_while_criteria_fail(): void
    {
        $project = $this->paidProject();
        $project->update(['status' => 'REVIEW']);
        $project->criteria()->create(['key' => 'k', 'criterion' => 'c', 'kind' => 'automated', 'status' => 'failed']);
        $project->builds()->create(['platform' => 'bundle', 'version' => '0.1.0']);

        $this->expectExceptionMessage('Automated acceptance criteria not green');
        app(PipelineOrchestrator::class)->approveReview($project, 'test');
    }

    public function test_stage_failure_retries_with_backoff_then_fails_project(): void
    {
        $project = $this->paidProject();
        $this->completeStage($project, 'product', [], 'failed');
        $this->assertSame('SPECIFICATION', $project->fresh()->status);
        $this->assertSame(2, $project->runs()->where('stage', 'product')->max('attempt'));
        // The retry is deferred, not fired 6 s later into the same outage.
        $this->assertSame(60, $project->events()->where('type', 'stage.dispatched')->latest('id')->first()->payload['delay_s']);

        $this->completeStage($project, 'product', [], 'failed');
        $this->assertSame('SPECIFICATION', $project->fresh()->status);
        $this->assertSame(3, $project->runs()->where('stage', 'product')->max('attempt'));
        $this->assertSame(180, $project->events()->where('type', 'stage.dispatched')->latest('id')->first()->payload['delay_s']);

        $this->completeStage($project, 'product', [], 'failed');
        $this->assertSame('FAILED', $project->fresh()->status);
        $this->assertStringContainsString('after 3 attempts', $project->fresh()->failed_reason);
    }

    public function test_callback_requires_valid_token(): void
    {
        $project = $this->paidProject();
        $run = $project->runs()->first();
        $this->postJson("/api/internal/runs/{$run->id}/complete", ['status' => 'succeeded'], [
            'Authorization' => 'Bearer wrong',
        ])->assertForbidden();
    }
}
