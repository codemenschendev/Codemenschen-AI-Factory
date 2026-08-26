<?php

namespace App\Services;

use App\Jobs\DispatchStageJob;
use App\Models\PipelineRun;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic pipeline state machine (PLAN.md §6). Agents produce artifacts;
 * every transition decision lives HERE, in code — never in a prompt.
 *
 * PAID → SPECIFICATION (product → uiux) → BUILDING (coding) → TESTING (test)
 *   ⇄ FIXING (fix, max 3) → release (preview build) → REVIEW —approve→ READY.
 * Any exhausted stage → FAILED + human escalation.
 */
class PipelineOrchestrator
{
    public const MAX_FIX_ATTEMPTS = 3;

    /**
     * A stage that errors (not a test failure) is retried with growing delays.
     * Most stage errors are transient upstream hiccups — the OpenClaw gateway
     * answering "No response", a busy relay, an EAS queue timeout — and an
     * immediate second try lands in exactly the same condition (Formpilot,
     * 26.08.2026: both attempts 6 s apart). Waiting a minute and then three
     * turns those into successes without a human.
     */
    public const MAX_STAGE_ATTEMPTS = 3;

    /** Seconds to wait before attempt 2, 3, … */
    public const RETRY_DELAYS = [60, 180];

    public function __construct(private Notify $notify) {}

    /** Entry point: called at payment (or when a deferred build comes due). */
    public function start(Project $project): void
    {
        if ($project->status !== 'PAID') {
            return;
        }
        if ($project->build_starts_at?->isFuture()) {
            return; // FAGG withdrawal period still running
        }
        $this->transition($project, 'SPECIFICATION');
        $this->dispatchStage($project, 'product');
    }

    public function onStageCompleted(PipelineRun $run): void
    {
        $project = $run->project;

        match ($run->stage) {
            'product' => $this->afterProduct($project, $run),
            'uiux' => $this->afterUiux($project),
            'coding' => $this->afterCoding($project),
            'test' => $this->afterTest($project, $run),
            'fix' => $this->afterFix($project),
            'release' => $this->afterRelease($project, $run),
            'assets' => $this->afterAssets($project, $run),
            'marketing' => $this->afterMarketing($project, $run),
            default => null,
        };
    }

    /** Customer-triggered creative generation (marketingLaunch package). */
    public function generateMarketing(Project $project, string $actor): void
    {
        abort_unless(
            in_array($project->status, ['READY', 'PUBLISHING', 'PUBLISHED', 'MARKETING']),
            409,
            'App is not ready for marketing',
        );
        abort_unless(
            (bool) ($project->order->packages['marketingLaunch'] ?? false),
            403,
            'Marketing package not purchased',
        );
        $project->recordEvent('marketing.generation_requested', [], $actor);
        $this->dispatchStage($project, 'marketing');
    }

    public function onStageFailed(PipelineRun $run): void
    {
        $project = $run->project;
        $project->recordEvent('stage.failed', [
            'stage' => $run->stage, 'attempt' => $run->attempt, 'error' => mb_substr((string) $run->error, 0, 500),
        ]);

        if ($run->attempt < self::MAX_STAGE_ATTEMPTS) {
            $delay = self::RETRY_DELAYS[$run->attempt - 1] ?? end(self::RETRY_DELAYS);
            $this->dispatchStage($project, $run->stage, $run->attempt + 1, 'system', $delay);

            return;
        }
        $this->fail($project, "stage {$run->stage} failed after {$run->attempt} attempts");
    }

    /** REVIEW → READY. Guarded: never READY with failing required tests. */
    public function approveReview(Project $project, string $actor): void
    {
        abort_unless($project->status === 'REVIEW', 409, 'Project is not awaiting review');
        $openCriteria = $project->criteria()
            ->where('kind', 'automated')->where('status', '!=', 'passed')->count();
        abort_if($openCriteria > 0, 409, 'Automated acceptance criteria not green');
        abort_if(! $project->builds()->exists(), 409, 'No build artifact');

        $this->transition($project, 'READY', $actor);
        // MVP 2: store assets are generated as soon as the app is READY.
        $this->dispatchStage($project, 'assets');
    }

    private function afterProduct(Project $project, PipelineRun $run): void
    {
        foreach (($run->output['criteria'] ?? []) as $c) {
            $project->criteria()->updateOrCreate(
                ['key' => $c['key']],
                ['criterion' => $c['criterion'], 'kind' => $c['kind'] ?? 'automated'],
            );
        }
        $this->dispatchStage($project, 'uiux');
    }

    private function afterUiux(Project $project): void
    {
        $this->transition($project, 'BUILDING');
        $this->dispatchStage($project, 'coding');
    }

    private function afterCoding(Project $project): void
    {
        $this->transition($project, 'TESTING');
        $this->dispatchStage($project, 'test');
    }

    private function afterTest(Project $project, PipelineRun $run): void
    {
        $report = $run->output['report'] ?? [];
        $project->testReports()->create([
            'pipeline_run_id' => $run->id,
            'passed' => $report['passed'] ?? 0,
            'failed' => $report['failed'] ?? 0,
            'report' => $report,
        ]);
        foreach (($run->output['criteria_results'] ?? []) as $key => $status) {
            $project->criteria()->where('key', $key)->update(['status' => $status]);
        }

        $allGreen = ($report['failed'] ?? 1) === 0
            && $project->criteria()->where('kind', 'automated')->where('status', '!=', 'passed')->doesntExist();

        if ($allGreen) {
            $this->dispatchStage($project, 'release');

            return;
        }

        if ($project->fix_attempts >= self::MAX_FIX_ATTEMPTS) {
            $this->fail($project, 'tests still failing after '.self::MAX_FIX_ATTEMPTS.' fix attempts');

            return;
        }
        $project->increment('fix_attempts');
        $this->transition($project->fresh(), 'FIXING');
        $this->dispatchStage($project, 'fix');
    }

    private function afterFix(Project $project): void
    {
        $this->transition($project, 'TESTING');
        $this->dispatchStage($project, 'test');
    }

    private function afterAssets(Project $project, PipelineRun $run): void
    {
        $version = ((int) $project->storeAssets()->max('version')) + 1;
        foreach (($run->output['assets'] ?? []) as $a) {
            $project->storeAssets()->create([
                'kind' => $a['kind'],
                'locale' => $a['locale'] ?? null,
                'content' => $a['content'] ?? null,
                'version' => $version,
            ]);
        }
        $project->recordEvent('assets.generated', ['count' => count($run->output['assets'] ?? []), 'version' => $version]);
    }

    private function afterMarketing(Project $project, PipelineRun $run): void
    {
        $version = ((int) $project->campaigns()->max('version')) + 1;
        $adBudget = $project->order->ad_budget_monthly_eur;
        foreach (($run->output['campaigns'] ?? []) as $c) {
            $campaign = $project->campaigns()->create([
                'platform' => $c['platform'],
                'strategy' => $c['strategy'] ?? [],
                'ad_budget_monthly_eur' => $adBudget,
                'version' => $version,
            ]);
            foreach (($c['creatives'] ?? []) as $cr) {
                $campaign->creatives()->create([
                    'kind' => $cr['kind'],
                    'locale' => $cr['locale'] ?? null,
                    'content' => $cr['content'],
                ]);
            }
        }
        $project->recordEvent('marketing.generated', [
            'campaigns' => count($run->output['campaigns'] ?? []),
            'version' => $version,
        ]);
    }

    private function afterRelease(Project $project, PipelineRun $run): void
    {
        foreach (($run->output['builds'] ?? []) as $b) {
            $project->builds()->create([
                'platform' => $b['platform'],
                'version' => $b['version'] ?? '0.1.0',
                'artifact_path' => $b['artifact_path'] ?? null,
                'status' => 'preview',
            ]);
        }
        $this->transition($project, 'REVIEW');
    }

    /** Operator lane (artisan factory:stage): one stage, outside the automatic flow. */
    public function dispatch(Project $project, string $stage, string $actor): PipelineRun
    {
        $allowed = ['product', 'uiux', 'coding', 'test', 'fix', 'release', 'assets', 'marketing'];
        abort_unless(in_array($stage, $allowed, true), 422, 'unknown stage');

        return $this->dispatchStage($project, $stage, 1, $actor);
    }

    private function dispatchStage(Project $project, string $stage, int $attempt = 1, string $actor = 'system', int $delaySeconds = 0): PipelineRun
    {
        $run = DB::transaction(fn () => PipelineRun::create([
            'project_id' => $project->id,
            'stage' => $stage,
            'attempt' => $attempt,
            'callback_token' => bin2hex(random_bytes(24)),
        ]));
        $project->recordEvent('stage.dispatched', ['stage' => $stage, 'attempt' => $attempt, 'delay_s' => $delaySeconds], $actor);
        $job = DispatchStageJob::dispatch($run->id);
        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }

        return $run;
    }

    private function transition(Project $project, string $to, string $actor = 'system'): void
    {
        $from = $project->status;
        $project->update(['status' => $to]);
        $project->recordEvent('status.changed', ['from' => $from, 'to' => $to], $actor);
        $this->notify->projectStatus($project, $from, $to);
    }

    private function fail(Project $project, string $reason): void
    {
        $project->update(['failed_reason' => $reason]);
        $this->transition($project, 'FAILED');
    }
}
