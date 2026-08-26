<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\PipelineOrchestrator;
use Illuminate\Console\Command;

/**
 * Operator lane: re-dispatch a single pipeline stage for a project, e.g.
 * `factory:stage <project> release` after enabling EAS builds, or `fix`
 * after a human rescued a FAILED repo. The normal transitions run when the
 * stage completes, exactly as in the automatic flow.
 */
class RunStage extends Command
{
    protected $signature = 'factory:stage {project} {stage : product|uiux|coding|test|fix|release|assets|marketing}';

    protected $description = 'OPERATOR: dispatch one pipeline stage for a project';

    public function handle(PipelineOrchestrator $orchestrator): int
    {
        $project = Project::findOrFail($this->argument('project'));
        $stage = $this->argument('stage');
        if ($project->runs()->where('status', 'running')->exists()) {
            $this->error('A stage is already running for this project.');

            return self::FAILURE;
        }
        $run = $orchestrator->dispatch($project, $stage, 'operator');
        $this->info("run {$run->id}: {$stage} dispatched for {$project->name} ({$project->status})");

        return self::SUCCESS;
    }
}
