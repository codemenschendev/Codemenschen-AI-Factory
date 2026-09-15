<?php

namespace App\Services;

use App\Models\ChangeRequest;
use App\Models\Project;
use App\Models\Prototype;
use Carbon\CarbonInterface;
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
        // The customer first: a preview to look at, an approval received, a failure we are on.
        app(CustomerMail::class)->projectStatus($project, $to, $from);

        if (in_array($to, self::MAIL_WORTHY, true)) {
            $this->mailAdmin(
                "[AI Factory] {$project->name}: {$from} → {$to}",
                "Project {$project->id}\nCustomer: {$project->customer?->email}\nStatus: {$from} → {$to}"
                .($project->failed_reason ? "\nReason: {$project->failed_reason}" : '')
                .($project->previewUrl() ? "\nPreview: {$project->previewUrl()}" : '')
                ."\n\nPortal: ".rtrim(config('services.frontend_url'), '/')."/de/account/{$project->id}",
            );
        }
        $this->send(sprintf(
            'Project %s (%s) %s → %s%s',
            substr($project->id, 0, 8),
            $project->name,
            $from,
            $to,
            $to === 'REVIEW' ? ' — preview ready, approval needed'.($project->previewUrl() ? " {$project->previewUrl()}" : '') : ($to === 'FAILED' ? " — {$project->failed_reason}" : ''),
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
        $this->note($project, $note);
    }

    /**
     * A free prototype that died. Nobody is paying, but a visitor watched a progress bar for
     * minutes and got "that did not work", and until this line the only record was a row in
     * the admin tab that somebody had to go and look at.
     */
    public function prototypeFailed(Prototype $proto): void
    {
        $line = sprintf('Prototype %s (%s) failed after %s: %s',
            substr($proto->id, 0, 8), $proto->kind,
            $proto->created_at->diffForHumans(now(), ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'short' => true]),
            mb_substr((string) $proto->error, 0, 200));
        $this->mailAdmin('[AI Factory] prototype failed: '.mb_substr($proto->prompt, 0, 60),
            "Prototype {$proto->id} ({$proto->kind})\nPrompt: ".mb_substr($proto->prompt, 0, 300)."\nError: {$proto->error}"
            ."\n\nAdmin: ".rtrim(config('services.frontend_url'), '/').'/de/admin');
        $this->send($line);
    }

    /** One chat line and no mail: for things an operator should see soon, but not in the inbox. */
    public function alert(Project $project, string $line): void
    {
        $this->send(sprintf('Project %s (%s) %s', substr($project->id, 0, 8), $project->name, $line));
    }

    /** One-line operator note (mail + chat) for events that are not status transitions. */
    public function note(Project $project, string $note): void
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

    /**
     * One line to the operators' chat: the gateway hook if one is configured, and the Buzz
     * channel #appwerk-alerts if its drop folder is mounted. Buzz speaks signed Nostr events,
     * not webhooks, so the API only leaves a file; the Appwerk bot on the Buzz side posts it
     * (buzz/appwerk-dev-kit/deploy/listener.mjs in the OpenClaw repo). Both fail soft: a
     * notification must never break the pipeline.
     */
    private function send(string $message): void
    {
        $url = config('services.openclaw.hook_url');
        $token = config('services.openclaw.hook_token');
        if ($url && $token) {
            try {
                Http::timeout(5)->withToken($token)->post($url, [
                    'message' => $message,
                    'deliver' => true,
                ]);
            } catch (\Throwable $e) {
                Log::warning('notify.openclaw_failed', ['error' => $e->getMessage()]);
            }
        }

        $dir = config('services.buzz.alert_dir');
        if ($dir && is_dir($dir)) {
            try {
                // Written under a dot name and renamed, so the bot never reads half a file.
                $name = now()->format('Ymd-His-v').'-'.bin2hex(random_bytes(3)).'.json';
                $tmp = $dir.'/.'.$name;
                $body = json_encode(['message' => 'Appwerk: '.$message, 'at' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE);
                if (file_put_contents($tmp, $body) === false || ! rename($tmp, $dir.'/'.$name)) {
                    Log::warning('notify.buzz_failed', ['dir' => $dir]);
                }
            } catch (\Throwable $e) {
                Log::warning('notify.buzz_failed', ['error' => $e->getMessage()]);
            }
        }
    }
}
