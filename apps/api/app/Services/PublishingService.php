<?php

namespace App\Services;

use App\Models\Project;
use App\Models\StoreSubmission;

/**
 * Publishing workflow (MVP 2 phase B). Store-policy constraint: template-
 * built apps must ship under the CUSTOMER's own developer accounts, so the
 * flow starts by collecting those. Status changes are operator-driven
 * (factory:submission) until the ASC / Play Developer API integrations land.
 */
class PublishingService
{
    public function __construct(private Notify $notify) {}

    /** Customer starts publishing for a READY project. */
    public function start(Project $project, array $stores, string $actor): void
    {
        abort_unless(in_array($project->status, ['READY', 'PUBLISHING']), 409, 'Project is not READY');
        abort_if(empty($stores), 422, 'Pick at least one store');

        foreach ($stores as $store) {
            $project->submissions()->firstOrCreate(['store' => $store]);
        }
        if ($project->status === 'READY') {
            $project->update(['status' => 'PUBLISHING']);
            $project->recordEvent('status.changed', ['from' => 'READY', 'to' => 'PUBLISHING'], $actor);
            $this->notify->projectStatus($project, 'READY', 'PUBLISHING');
        }
        $project->recordEvent('publishing.started', ['stores' => $stores], $actor);
    }

    /** Customer saves their developer-account reference for a store. */
    public function attachAccount(Project $project, string $store, string $accountRef, string $actor): StoreSubmission
    {
        $submission = $project->submissions()->where('store', $store)->firstOrFail();
        $submission->update([
            'account_ref' => $accountRef,
            'status' => $submission->status === 'waiting_account' ? 'preparing' : $submission->status,
        ]);
        $project->recordEvent('publishing.account_attached', ['store' => $store], $actor);

        return $submission;
    }

    /** Operator advances a submission (artisan / future store-API callbacks). */
    public function setStatus(StoreSubmission $submission, string $status, ?string $notes, string $actor): void
    {
        abort_unless(in_array($status, StoreSubmission::STATUSES), 422, 'Unknown status');
        $submission->update([
            'status' => $status,
            'notes' => $notes ?? $submission->notes,
            'submitted_at' => $status === 'submitted' ? now() : $submission->submitted_at,
        ]);
        $project = $submission->project;
        $project->recordEvent('publishing.status', ['store' => $submission->store, 'status' => $status], $actor);

        // All requested stores live → the project is PUBLISHED.
        if ($status === 'live' && $project->submissions()->where('status', '!=', 'live')->doesntExist()) {
            $project->update(['status' => 'PUBLISHED']);
            $project->recordEvent('status.changed', ['from' => 'PUBLISHING', 'to' => 'PUBLISHED'], $actor);
            $this->notify->projectStatus($project, 'PUBLISHING', 'PUBLISHED');
        }
    }
}
