<?php

namespace App\Console\Commands;

use App\Models\StoreSubmission;
use App\Services\PublishingService;
use Illuminate\Console\Command;

/** Operator lever until the ASC / Play Developer API integrations land. */
class SubmissionStatus extends Command
{
    protected $signature = 'factory:submission {id} {status} {--notes=}';

    protected $description = 'Advance a store submission (waiting_account|preparing|submitted|in_review|live|rejected)';

    public function handle(PublishingService $publishing): int
    {
        $submission = StoreSubmission::findOrFail($this->argument('id'));
        $publishing->setStatus($submission, $this->argument('status'), $this->option('notes'), 'operator:cli');
        $this->info("submission {$submission->id} ({$submission->store}) → {$submission->status}; project {$submission->project->status}");

        return self::SUCCESS;
    }
}
