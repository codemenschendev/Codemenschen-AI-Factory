<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\PipelineOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MeController extends Controller
{
    public function project(Request $request, Project $project): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id, 404);

        return response()->json([
            'id' => $project->id,
            'name' => $project->name,
            'status' => $project->status,
            'fix_attempts' => $project->fix_attempts,
            'failed_reason' => $project->failed_reason,
            'build_starts_at' => $project->build_starts_at?->toIso8601String(),
            'criteria' => $project->criteria()->get(['key', 'criterion', 'kind', 'status']),
            'builds' => $project->builds()->latest()->get(['id', 'platform', 'version', 'status', 'created_at']),
            'store_assets' => $project->storeAssets()
                ->where('version', $project->storeAssets()->max('version') ?? 0)
                ->get(['id', 'kind', 'locale', 'content', 'status']),
            'runs' => $project->runs()->latest()->limit(30)
                ->get(['stage', 'attempt', 'status', 'started_at', 'finished_at']),
            'events' => $project->events()->latest('created_at')->limit(50)
                ->get(['type', 'payload', 'actor', 'created_at']),
        ]);
    }

    /** Customer approves the preview build: REVIEW → READY (guarded). */
    public function approveReview(Request $request, Project $project, PipelineOrchestrator $orchestrator): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id, 404);
        $orchestrator->approveReview($project, 'customer:'.$request->user()->email);

        return response()->json(['status' => $project->fresh()->status]);
    }

    public function downloadBuild(Request $request, Project $project, int $buildId): BinaryFileResponse
    {
        abort_unless($project->customer_id === $request->user()->id, 404);
        $build = $project->builds()->findOrFail($buildId);
        $base = rtrim(config('services.worker.artifacts_path'), '/');
        $path = $base.'/'.ltrim((string) $build->artifact_path, '/');
        abort_unless($build->artifact_path && is_file($path), 404, 'Artifact not found');

        return response()->download($path);
    }

    public function projects(Request $request): JsonResponse
    {
        $projects = $request->user()->projects()
            ->with(['order', 'events' => fn ($q) => $q->latest('created_at')->limit(20)])
            ->latest()
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'status' => $p->status,
                'stack' => $p->stack,
                'build_starts_at' => $p->build_starts_at?->toIso8601String(),
                'created_at' => $p->created_at->toIso8601String(),
                'order' => [
                    'total_one_time_eur' => $p->order->total_one_time_eur,
                    'hosting_monthly_eur' => $p->order->hosting_monthly_eur,
                    'status' => $p->order->status,
                ],
                'events' => $p->events->map(fn ($e) => [
                    'type' => $e->type,
                    'at' => $e->created_at->toIso8601String(),
                ]),
            ]);

        return response()->json([
            'email' => $request->user()->email,
            'projects' => $projects,
        ]);
    }
}
