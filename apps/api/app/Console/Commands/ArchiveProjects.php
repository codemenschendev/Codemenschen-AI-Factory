<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

/**
 * Takes test and abandoned projects out of the admin lists. Nothing is deleted: the project, its
 * runs and its events stay, and --undo brings it back. A project paid with real money is refused,
 * because hiding a customer's work from the operator is how it gets forgotten.
 */
class ArchiveProjects extends Command
{
    protected $signature = 'factory:archive {ids* : project ids} {--undo : bring archived projects back}';

    protected $description = 'Hide test or abandoned projects from the admin lists (reversible)';

    public function handle(): int
    {
        $failed = false;

        foreach ($this->argument('ids') as $id) {
            $project = Project::with('order')->find($id);
            if (! $project) {
                $this->error("{$id}: not found");
                $failed = true;

                continue;
            }
            if (! $this->option('undo') && $project->order?->livemode) {
                $this->error("{$id}: paid with real money, not archived");
                $failed = true;

                continue;
            }
            $project->forceFill(['archived_at' => $this->option('undo') ? null : now()])->save();
            $this->line(($this->option('undo') ? 'restored  ' : 'archived  ')."{$id}  {$project->status}  ".mb_substr((string) $project->name, 0, 50));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
