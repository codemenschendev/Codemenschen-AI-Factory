<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Services\OrderFulfillment;
use App\Services\PipelineOrchestrator;
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

        // READY kicks off store-asset generation (MVP 2)
        $this->completeStage($project, 'assets', ['assets' => [
            ['kind' => 'name', 'locale' => 'de', 'content' => 'ClubApp'],
            ['kind' => 'description', 'locale' => 'en', 'content' => 'An honest description.'],
        ]]);
        $this->assertSame('READY', $project->fresh()->status);
        $this->assertSame(2, $project->storeAssets()->count());
        $this->assertDatabaseHas('project_events', ['project_id' => $project->id, 'type' => 'assets.generated']);
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

    public function test_ready_is_blocked_while_criteria_fail(): void
    {
        $project = $this->paidProject();
        $project->update(['status' => 'REVIEW']);
        $project->criteria()->create(['key' => 'k', 'criterion' => 'c', 'kind' => 'automated', 'status' => 'failed']);
        $project->builds()->create(['platform' => 'bundle', 'version' => '0.1.0']);

        $this->expectExceptionMessage('Automated acceptance criteria not green');
        app(PipelineOrchestrator::class)->approveReview($project, 'test');
    }

    public function test_stage_failure_retries_then_fails_project(): void
    {
        $project = $this->paidProject();
        $this->completeStage($project, 'product', [], 'failed');
        $this->assertSame('SPECIFICATION', $project->fresh()->status);
        $this->assertSame(2, $project->runs()->where('stage', 'product')->max('attempt'));

        $this->completeStage($project, 'product', [], 'failed');
        $this->assertSame('FAILED', $project->fresh()->status);
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
