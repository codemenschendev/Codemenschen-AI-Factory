<?php

namespace App\Jobs;

use App\Models\PipelineRun;
use App\Services\PipelineOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Hands one stage run to the worker service. The worker answers 202 and
 * reports the result asynchronously to /internal/runs/{id}/complete —
 * the HTTP admission is NOT the result (same contract as OpenClaw hooks).
 */
class DispatchStageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(public string $runId) {}

    public function handle(PipelineOrchestrator $orchestrator): void
    {
        $run = PipelineRun::with('project.order.quote')->findOrFail($this->runId);
        if ($run->status !== 'queued') {
            return; // already picked up (job retry after partial success)
        }

        $project = $run->project;
        $quote = $project->order->quote;

        $payload = [
            'run_id' => $run->id,
            'project_id' => $project->id,
            'stage' => $run->stage,
            'attempt' => $run->attempt,
            'callback_url' => rtrim(config('app.url'), '/')."/api/internal/runs/{$run->id}/complete",
            'callback_token' => $run->callback_token,
            'context' => [
                'name' => $project->name,
                'stack' => $project->stack,
                'idea' => $quote->idea,
                'listing_slug' => $quote->listing_slug,
                'audience' => $quote->audience,
                'platform' => $quote->platform,
                'features' => $quote->features,
                'app_type' => $quote->app_type,
                'store_locales' => $project->order->storeLocales(),
                'fix_attempt' => $project->fix_attempts,
                'criteria' => $project->criteria()->get(['key', 'criterion', 'kind', 'status'])->toArray(),
                'last_test_report' => $project->testReports()->latest()->first()?->report,
            ],
        ];

        $res = Http::timeout(20)
            ->withToken(config('services.worker.token'))
            ->post(rtrim(config('services.worker.url'), '/').'/run', $payload);

        if ($res->status() !== 202) {
            throw new \RuntimeException("worker admission failed: HTTP {$res->status()}");
        }
        $run->update(['status' => 'running', 'started_at' => now(), 'heartbeat_at' => now()]);
    }

    public function failed(?\Throwable $e): void
    {
        $run = PipelineRun::find($this->runId);
        if ($run && $run->status === 'queued') {
            $run->update(['status' => 'failed', 'error' => 'dispatch: '.$e?->getMessage(), 'finished_at' => now()]);
            app(PipelineOrchestrator::class)->onStageFailed($run);
        }
    }
}
