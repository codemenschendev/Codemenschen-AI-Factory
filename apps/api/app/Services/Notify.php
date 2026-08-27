<?php

namespace App\Services;

use App\Models\ChangeRequest;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Ops cockpit notifications via the OpenClaw gateway hooks API
 * (loopback-only on this host). Silently skips when unconfigured —
 * notifications must never break the pipeline.
 */
class Notify
{
    /** Transitions that deserve a human's attention by e-mail. */
    private const MAIL_WORTHY = ['REVIEW', 'READY', 'FAILED', 'PUBLISHING', 'PUBLISHED'];

    public function projectStatus(Project $project, string $from, string $to): void
    {
        if (in_array($to, self::MAIL_WORTHY, true)) {
            $this->mailAdmin(
                "[AI Factory] {$project->name}: {$from} → {$to}",
                "Project {$project->id}\nCustomer: {$project->customer?->email}\nStatus: {$from} → {$to}"
                .($project->failed_reason ? "\nReason: {$project->failed_reason}" : '')
                ."\n\nPortal: ".rtrim(config('services.frontend_url'), '/')."/de/account/{$project->id}",
            );
        }
        $this->send(sprintf(
            'Project %s (%s) %s → %s%s',
            substr($project->id, 0, 8),
            $project->name,
            $from,
            $to,
            $to === 'REVIEW' ? ' — preview ready, approval needed' : ($to === 'FAILED' ? " — {$project->failed_reason}" : ''),
        ));
    }

    public function changeRequested(Project $project, ChangeRequest $cr): void
    {
        $this->send(sprintf(
            'Project %s (%s) change request round %d/%d: %s',
            substr($project->id, 0, 8),
            $project->name,
            $cr->round,
            PipelineOrchestrator::MAX_REVISION_ROUNDS,
            mb_substr($cr->text, 0, 300),
        ));
    }

    public function changeRequestNote(Project $project, string $note): void
    {
        $this->mailAdmin("[AI Factory] {$project->name}: {$note}", "Project {$project->id}\n{$note}");
        $this->send(sprintf('Project %s (%s) %s', substr($project->id, 0, 8), $project->name, $note));
    }

    private function mailAdmin(string $subject, string $body): void
    {
        $to = config('services.admin_email');
        if (! $to) {
            return;
        }
        try {
            Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
        } catch (\Throwable $e) {
            Log::warning('notify.admin_mail_failed', ['error' => $e->getMessage()]);
        }
    }

    private function send(string $message): void
    {
        $url = config('services.openclaw.hook_url');
        $token = config('services.openclaw.hook_token');
        if (! $url || ! $token) {
            return;
        }
        try {
            Http::timeout(5)->withToken($token)->post($url, [
                'message' => $message,
                'deliver' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('notify.openclaw_failed', ['error' => $e->getMessage()]);
        }
    }
}
