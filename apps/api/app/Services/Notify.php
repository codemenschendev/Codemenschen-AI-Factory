<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ops cockpit notifications via the OpenClaw gateway hooks API
 * (loopback-only on this host). Silently skips when unconfigured —
 * notifications must never break the pipeline.
 */
class Notify
{
    public function projectStatus(Project $project, string $from, string $to): void
    {
        $this->send(sprintf(
            'Project %s (%s) %s → %s%s',
            substr($project->id, 0, 8),
            $project->name,
            $from,
            $to,
            $to === 'REVIEW' ? ' — preview ready, approval needed' : ($to === 'FAILED' ? " — {$project->failed_reason}" : ''),
        ));
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
