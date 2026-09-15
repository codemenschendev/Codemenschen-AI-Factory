<?php

namespace App\Jobs;

use App\Models\ChangeMessage;
use App\Models\PipelineRun;
use App\Services\ChangeShots;
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
                'revision_round' => $project->revision_rounds,
                'change_request' => $project->changeRequests()->where('status', 'in_progress')->latest('id')->value('text'),
                // The confirmed checklist from the change chat; the revise agent reports on each item.
                'change_items' => $project->changeRequests()->where('status', 'in_progress')->latest('id')->first()?->items,
                'criteria' => $project->criteria()->get(['key', 'criterion', 'kind', 'status'])->toArray(),
                'last_test_report' => $project->testReports()->latest()->first()?->report,
            ],
        ];

        if ($run->stage === 'revise') {
            // Screenshots from the chat that led to this round. Outside `context`, which the worker
            // prints into the prompt; the worker saves these as files for the agent to open.
            $cr = $project->changeRequests()->where('status', 'in_progress')->latest('id')->first();
            $payload['change_images'] = $cr === null ? [] : app(ChangeShots::class)->inline(
                // Confirming dispatches before the draft is tied to the round, so an untied draft
                // line from before the round counts too.
                ChangeMessage::where('project_id', $project->id)->where('role', 'customer')
                    ->where(fn ($q) => $q->where('change_request_id', $cr->id)
                        ->orWhere(fn ($q) => $q->whereNull('change_request_id')->where('created_at', '<=', $cr->created_at)))
                    ->orderBy('id')->get(),
                ChangeShots::MAX_FOR_ROUND,
            );
        }

        $res = Http::timeout(30)
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
