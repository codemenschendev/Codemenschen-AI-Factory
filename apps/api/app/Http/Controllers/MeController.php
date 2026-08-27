<?php

namespace App\Http\Controllers;

use App\Domain\Pricing\Estimator;
use App\Models\Project;
use App\Services\PipelineOrchestrator;
use App\Services\PublishingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MeController extends Controller
{
    private const ASSET_KIND_ORDER = ['name', 'subtitle', 'description', 'keywords', 'release_notes', 'icon', 'screenshot', 'promo'];

    private static function assetRank(string $kind): int
    {
        $i = array_search($kind, self::ASSET_KIND_ORDER, true);

        return $i === false ? 99 : $i;
    }

    public function project(Request $request, Project $project, PipelineOrchestrator $orchestrator): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id, 404);

        return response()->json([
            'id' => $project->id,
            'name' => $project->name,
            'status' => $project->status,
            'fix_attempts' => $project->fix_attempts,
            'revision_rounds' => $project->revision_rounds,
            'max_revision_rounds' => PipelineOrchestrator::MAX_REVISION_ROUNDS,
            'free_rounds_left' => $orchestrator->freeRoundsLeft($project),
            'change_request_mode' => $orchestrator->changeRequestMode($project),
            'revision_price_eur' => Estimator::REVISION_PRICE_EUR,
            'change_requests' => $project->changeRequests()->latest('id')
                ->get(['id', 'round', 'text', 'status', 'agent_summary', 'price_eur', 'checkout_url', 'created_at']),
            'failed_reason' => $project->failed_reason,
            'build_starts_at' => $project->build_starts_at?->toIso8601String(),
            'criteria' => $project->criteria()->get(['key', 'criterion', 'kind', 'status']),
            'builds' => $project->builds()->latest()->get(['id', 'platform', 'version', 'status', 'created_at']),
            'preview_url' => $project->previewUrl(),
            'store_assets' => $project->storeAssets()
                ->where('version', $project->storeAssets()->max('version') ?? 0)
                ->get(['id', 'kind', 'locale', 'content', 'status'])
                // The assets stage stores rows in whatever order the model emitted them;
                // present them in store-listing order (name, subtitle, ...) per locale.
                ->sortBy(fn ($a) => sprintf('%02d-%s', self::assetRank($a->kind), $a->locale ?? ''))
                ->values(),
            'submissions' => $project->submissions()
                ->get(['id', 'store', 'status', 'account_ref', 'notes', 'submitted_at']),
            'packages' => $project->order->packages,
            'campaigns' => $project->campaigns()
                ->where('version', $project->campaigns()->max('version') ?? 0)
                ->with('creatives:id,marketing_campaign_id,kind,locale,content')
                ->get(['id', 'platform', 'strategy', 'status', 'ad_budget_monthly_eur']),
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

    /** Customer asks for changes to the preview build: REVIEW → FIXING (revise). */
    public function requestChanges(Request $request, Project $project, PipelineOrchestrator $orchestrator): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id, 404);
        $data = $request->validate([
            'text' => 'required|string|min:10|max:4000',
            'fagg_waiver' => 'nullable|boolean', // paid rounds only; never defaulted
        ]);
        $cr = $orchestrator->requestChanges(
            $project, trim($data['text']), 'customer:'.$request->user()->email,
            (bool) ($data['fagg_waiver'] ?? false), $request->ip(),
        );

        if ($cr->status === 'awaiting_payment') {
            if (! $cr->checkout_url) {
                return response()->json([
                    'change_request_id' => $cr->id, 'payment' => 'unconfigured',
                    'message' => 'Stripe is not configured yet (staging).',
                ], 503);
            }

            return response()->json([
                'change_request_id' => $cr->id, 'price_eur' => $cr->price_eur, 'checkout_url' => $cr->checkout_url,
            ], 201);
        }

        return response()->json(['status' => $project->fresh()->status, 'round' => $cr->round], 201);
    }

    public function startPublishing(Request $request, Project $project, PublishingService $publishing): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id, 404);
        $data = $request->validate([
            'stores' => 'required|array|min:1',
            'stores.*' => 'in:apple,google',
        ]);
        $publishing->start($project, array_values(array_unique($data['stores'])), 'customer:'.$request->user()->email);

        return response()->json(['status' => $project->fresh()->status]);
    }

    public function attachStoreAccount(Request $request, Project $project, PublishingService $publishing): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id, 404);
        $data = $request->validate([
            'store' => 'required|in:apple,google',
            'account_ref' => 'required|string|max:120',
        ]);
        $s = $publishing->attachAccount($project, $data['store'], $data['account_ref'], 'customer:'.$request->user()->email);

        return response()->json(['store' => $s->store, 'status' => $s->status]);
    }

    public function generateMarketing(Request $request, Project $project, PipelineOrchestrator $orchestrator): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id, 404);
        $orchestrator->generateMarketing($project, 'customer:'.$request->user()->email);

        return response()->json(['generating' => true]);
    }

    /** Content sign-off by the customer. Spend approval is a separate operator gate. */
    public function decideCampaign(Request $request, Project $project, int $campaignId): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id, 404);
        $data = $request->validate(['decision' => 'required|in:approved,rejected']);
        $campaign = $project->campaigns()->findOrFail($campaignId);
        abort_unless($campaign->status === 'pending_approval', 409, 'Campaign already decided');
        $campaign->update(['status' => $data['decision']]);
        $project->recordEvent('marketing.campaign_decided', [
            'campaign_id' => $campaign->id, 'decision' => $data['decision'],
        ], 'customer:'.$request->user()->email);

        return response()->json(['status' => $campaign->status]);
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
