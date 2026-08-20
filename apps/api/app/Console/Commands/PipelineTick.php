<?php

namespace App\Console\Commands;

use App\Models\PipelineRun;
use App\Models\Project;
use App\Services\PipelineOrchestrator;
use Illuminate\Console\Command;

/**
 * Scheduled every minute: starts deferred builds whose FAGG withdrawal
 * period has ended, and fails over stage runs whose worker went silent.
 */
class PipelineTick extends Command
{
    protected $signature = 'pipeline:tick';

    protected $description = 'Start due projects and reap stalled pipeline runs';

    public function handle(PipelineOrchestrator $orchestrator): int
    {
        Project::where('status', 'PAID')
            ->where('build_starts_at', '<=', now())
            ->whereDoesntHave('runs')
            ->each(function (Project $p) use ($orchestrator) {
                $this->info("starting deferred project {$p->id}");
                $orchestrator->start($p);
            });

        PipelineRun::where('status', 'running')
            ->where('heartbeat_at', '<', now()->subMinutes(15))
            ->each(function (PipelineRun $run) use ($orchestrator) {
                $this->warn("reaping stalled run {$run->id} ({$run->stage})");
                $run->update(['status' => 'failed', 'error' => 'stalled: no heartbeat for 15m', 'finished_at' => now()]);
                $orchestrator->onStageFailed($run);
            });

        return self::SUCCESS;
    }
}
