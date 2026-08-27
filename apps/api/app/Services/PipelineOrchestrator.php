<?php

namespace App\Services;

use App\Domain\Pricing\Estimator;
use App\Jobs\DispatchStageJob;
use App\Models\ChangeRequest;
use App\Models\PipelineRun;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic pipeline state machine (PLAN.md §6). Agents produce artifacts;
 * every transition decision lives HERE, in code — never in a prompt.
 *
 * PAID → SPECIFICATION (product → uiux) → BUILDING (coding) → TESTING (test)
 *   ⇄ FIXING (fix, max 3) → release (web preview) → REVIEW —approve→ READY
 *   → assets → build (EAS .apk). The installable build runs only after the
 *   customer approved the browser preview: cloud builds cost quota and up to
 *   30 min of queue, and every change round would otherwise burn one.
 * REVIEW —change request→ FIXING (revise) → TESTING → … → REVIEW; three free
 * rounds, afterwards (and once the app is READY/PUBLISHED) a paid round that
 * returns the app to the status it had before the revision.
 * Any exhausted stage → FAILED + human escalation.
 */
class PipelineOrchestrator
{
    public const MAX_FIX_ATTEMPTS = 3;

    /** Free change-request rounds a customer gets while the app is in REVIEW. */
    public const MAX_REVISION_ROUNDS = 3;

    /** Statuses in which a customer may file a change request. */
    public const REVISABLE_STATUSES = ['REVIEW', 'READY', 'PUBLISHING', 'PUBLISHED', 'MARKETING', 'COMPLETED'];

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

    public function __construct(private Notify $notify, private RevisionService $revisions) {}

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
            'revise' => $this->afterRevise($project, $run),
            'release' => $this->afterRelease($project, $run),
            'build' => $this->afterBuild($project, $run),
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
        if ($run->stage === 'build') {
            // The customer already approved this app; a failed installable build
            // must not sink it. Operator retries with `factory:stage <id> build`.
            $project->recordEvent('build.failed', ['error' => mb_substr((string) $run->error, 0, 500)]);
            $this->notify->note($project, "Android build failed after {$run->attempt} attempts — ".mb_substr((string) $run->error, 0, 200));

            return;
        }
        if ($run->stage === 'revise') {
            // A change request that cannot be implemented must not sink an app
            // that already passed review: hand it back to the customer + operator.
            $this->closeChangeRequest($project, 'failed', mb_substr((string) $run->error, 0, 500));
            $this->backFromRevision($project);

            return;
        }
        $this->fail($project, "stage {$run->stage} failed after {$run->attempt} attempts");
    }

    /**
     * REVIEW → FIXING (revise). The customer describes a change; the revise
     * agent implements it inside the paid scope, then the normal test → release
     * chain produces a fresh preview and the project returns to REVIEW.
     */
    public function requestChanges(Project $project, string $text, string $actor, bool $faggWaiver = false, ?string $ip = null): ChangeRequest
    {
        $mode = $this->changeRequestMode($project);
        abort_if($mode === 'none', 409, 'Project cannot take change requests right now');

        if ($mode === 'free' || $mode === 'care') {
            $cr = $project->changeRequests()->create([
                'round' => $project->revision_rounds + 1,
                'text' => $text,
                'covered_by' => $mode === 'care' ? 'care' : null,
            ]);
            $this->startRevision($project, $cr, $actor);

            return $cr;
        }

        // Paid round: work starts right after payment, so the FAGG § 18 express
        // start consent must be an explicit choice again (same as at checkout).
        abort_unless($faggWaiver, 422, 'Express start consent (FAGG § 18) is required for a paid revision');
        $cr = $project->changeRequests()->create([
            'round' => $project->revision_rounds + 1,
            'text' => $text,
            'status' => 'awaiting_payment',
            'price_eur' => Estimator::REVISION_PRICE_EUR,
            'fagg_waiver_at' => now(),
            'fagg_waiver_ip' => $ip,
        ]);
        $project->recordEvent('changes.quoted', ['change_request_id' => $cr->id, 'price_eur' => $cr->price_eur], $actor);
        $this->revisions->createCheckout($cr);

        return $cr->fresh();
    }

    /** Stripe confirmed a paid change request. */
    public function onRevisionPaid(ChangeRequest $cr): void
    {
        if ($cr->status !== 'awaiting_payment') {
            return;
        }
        $project = $cr->project;
        if (! in_array($project->status, self::REVISABLE_STATUSES, true)) {
            // Another revision is running: park it, the operator starts it by hand.
            $cr->update(['status' => 'paid']);
            $this->notify->changeRequestNote($project, "paid change request #{$cr->id} is waiting — project is {$project->status}");

            return;
        }
        $this->startRevision($project, $cr, 'system:stripe');
    }

    private function startRevision(Project $project, ChangeRequest $cr, string $actor): void
    {
        $project->increment('revision_rounds');
        $project->update([
            // Each round gets the full fix budget again for its own test failures.
            'fix_attempts' => 0,
            'resume_status' => $project->status === 'REVIEW' ? null : $project->status,
        ]);
        $cr->update(['status' => 'in_progress', 'round' => $project->revision_rounds]);
        $project->recordEvent('changes.requested', [
            'change_request_id' => $cr->id, 'round' => $cr->round, 'price_eur' => $cr->price_eur,
        ], $actor);
        $this->transition($project->fresh(), 'FIXING', $actor);
        $this->dispatchStage($project, 'revise', 1, $actor);
        $this->notify->changeRequested($project, $cr);
    }

    /** After a revision that produced nothing: back to REVIEW, or to the pre-revision status. */
    private function backFromRevision(Project $project): void
    {
        $to = $project->resume_status ?: 'REVIEW';
        $project->update(['resume_status' => null]);
        $this->transition($project->fresh(), $to);
    }

    /** REVIEW → READY. Guarded: never READY with failing required tests. */
    public function approveReview(Project $project, string $actor): void
    {
        abort_unless($project->status === 'REVIEW', 409, 'Project is not awaiting review');
        $openCriteria = $project->criteria()
            ->where('kind', 'automated')->where('status', '!=', 'passed')->count();
        abort_if($openCriteria > 0, 409, 'Automated acceptance criteria not green');
        abort_if(! $project->builds()->exists(), 409, 'No build artifact');

        // A revision of an already released app goes back where it came from.
        $to = $project->resume_status ?: 'READY';
        $project->update(['resume_status' => null]);
        $this->transition($project->fresh(), $to, $actor);
        // MVP 2: store assets are (re)generated as soon as the app is READY;
        // the installable build follows them (afterAssets → build).
        $this->dispatchStage($project, 'assets');
    }

    /** What a new change request costs right now: care (subscription) | free | paid | none. */
    public function changeRequestMode(Project $project): string
    {
        if (! in_array($project->status, self::REVISABLE_STATUSES, true)) {
            return 'none';
        }
        if ($project->care_status === 'active') {
            return 'care';
        }

        return $project->status === 'REVIEW' && $this->freeRoundsLeft($project) > 0 ? 'free' : 'paid';
    }

    public function freeRoundsLeft(Project $project): int
    {
        // Care-covered rounds never eat into the free ones.
        $used = $project->changeRequests()->where('price_eur', 0)->whereNull('covered_by')->count();

        return max(0, self::MAX_REVISION_ROUNDS - $used);
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

    private function afterRevise(Project $project, PipelineRun $run): void
    {
        $declined = trim((string) ($run->output['declined'] ?? ''));
        if ($declined !== '') {
            // Outside the paid feature list — nothing changed, no rebuild needed.
            $this->closeChangeRequest($project, 'out_of_scope', $declined);
            $this->backFromRevision($project);

            return;
        }
        $this->closeChangeRequest($project, 'done', (string) ($run->output['summary'] ?? ''));
        $this->transition($project, 'TESTING');
        $this->dispatchStage($project, 'test');
    }

    private function closeChangeRequest(Project $project, string $status, string $summary): void
    {
        $cr = $project->changeRequests()->where('status', 'in_progress')->latest('id')->first();
        if (! $cr) {
            return;
        }
        $cr->update(['status' => $status, 'agent_summary' => $summary ?: null]);
        $project->recordEvent('changes.'.$status, ['change_request_id' => $cr->id, 'round' => $cr->round]);
        if ($status !== 'done' && $cr->price_eur > 0) {
            // The customer paid for a round that produced nothing: refund by hand.
            $project->recordEvent('changes.refund_needed', ['change_request_id' => $cr->id, 'price_eur' => $cr->price_eur]);
            $this->notify->changeRequestNote($project, "paid change request #{$cr->id} ended {$status} — refund €{$cr->price_eur}");
        }
    }

    private function afterAssets(Project $project, PipelineRun $run): void
    {
        $version = ((int) $project->storeAssets()->max('version')) + 1;
        $locales = $project->order->storeLocales();
        foreach (($run->output['assets'] ?? []) as $a) {
            // Keep only the languages the customer ordered (the model may emit more).
            if (isset($a['locale']) && ! in_array($a['locale'], $locales, true)) {
                continue;
            }
            $project->storeAssets()->create([
                'kind' => $a['kind'],
                'locale' => $a['locale'] ?? null,
                'content' => $a['content'] ?? null,
                'version' => $version,
            ]);
        }
        $project->recordEvent('assets.generated', ['count' => $project->storeAssets()->where('version', $version)->count(), 'version' => $version]);
        // Installable build only now — the customer approved the web preview.
        if ($project->stack === 'expo') {
            $this->dispatchStage($project, 'build');
        }
    }

    /** The EAS .apk landed (or nothing to build): record it, tell the operator. */
    private function afterBuild(Project $project, PipelineRun $run): void
    {
        $builds = $run->output['builds'] ?? [];
        $this->recordBuilds($project, $builds, 'release');
        if ($builds !== []) {
            $project->recordEvent('build.ready', ['platforms' => array_column($builds, 'platform')]);
            $this->notify->note($project, 'Android build ready (.apk in the portal)');
        }
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

    /** Statuses from which a finished release means "the customer looks at it next". */
    private const PRE_REVIEW = ['BUILDING', 'TESTING', 'FIXING'];

    private function afterRelease(Project $project, PipelineRun $run): void
    {
        $this->recordBuilds($project, $run->output['builds'] ?? [], 'preview');
        if (in_array($project->status, self::PRE_REVIEW, true)) {
            $this->transition($project, 'REVIEW');

            return;
        }
        // Operator re-run on an already reviewed/released app (e.g. to add the
        // browser preview to builds from before it existed): artifacts refresh,
        // the status stays.
        $project->recordEvent('release.refreshed', ['platforms' => array_column($run->output['builds'] ?? [], 'platform')]);
    }

    /** One artifact per platform and version — a re-run replaces, never duplicates. */
    private function recordBuilds(Project $project, array $builds, string $status): void
    {
        foreach ($builds as $b) {
            $project->builds()->updateOrCreate(
                ['platform' => $b['platform'], 'version' => $b['version'] ?? '0.1.0'],
                ['artifact_path' => $b['artifact_path'] ?? null, 'status' => $status],
            );
        }
    }

    /** Operator lane (artisan factory:stage): one stage, outside the automatic flow. */
    public function dispatch(Project $project, string $stage, string $actor): PipelineRun
    {
        $allowed = ['product', 'uiux', 'coding', 'test', 'fix', 'revise', 'release', 'build', 'assets', 'marketing'];
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
