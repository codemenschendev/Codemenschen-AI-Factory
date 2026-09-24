<?php

namespace App\Http\Controllers;

use App\Domain\Analytics\Analytics;
use App\Domain\Pricing\Estimator;
use App\Domain\Sites\Imprint;
use App\Domain\Sites\SiteService;
use App\Models\ChangeMessage;
use App\Models\Project;
use App\Services\CareService;
use App\Services\ChangeChat;
use App\Services\ChangeShots;
use App\Services\PipelineOrchestrator;
use App\Services\PublishingService;
use App\Services\Refiner;
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
            'kind' => $project->kind,
            'site' => $project->kind === 'site' ? self::site($project) : null,
            'status' => $project->status,
            'fix_attempts' => $project->fix_attempts,
            'revision_rounds' => $project->revision_rounds,
            'max_revision_rounds' => PipelineOrchestrator::MAX_REVISION_ROUNDS,
            'free_rounds_left' => $orchestrator->freeRoundsLeft($project),
            'change_request_mode' => $orchestrator->changeRequestMode($project),
            'revision_price_eur' => Estimator::REVISION_PRICE_EUR,
            'care_monthly_eur' => Estimator::CARE_MONTHLY_EUR,
            'care_status' => $project->care_status ?? 'none',
            'care_ends_at' => $project->care_ends_at?->toIso8601String(),
            'change_requests' => $project->changeRequests()->latest('id')
                ->get(['id', 'round', 'text', 'items', 'status', 'agent_summary', 'result_items', 'price_eur', 'checkout_url', 'created_at']),
            'change_chat' => ChangeChat::enabledFor($request->user()),
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

    /** Start Appwerk Care (€/month, unlimited change rounds): Stripe subscription checkout. */
    public function startCare(Request $request, Project $project, CareService $care): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id, 404);
        // Service starts with the first change round, so the FAGG § 18 express
        // start consent is an explicit choice here too (never pre-ticked).
        $request->validate(['fagg_waiver' => 'required|accepted']);
        abort_if(($project->care_status ?? 'none') === 'active', 409, 'Care is already active');
        abort_unless(in_array($project->status, PipelineOrchestrator::REVISABLE_STATUSES, true), 409, 'App is not ready for Care yet');

        $project->recordEvent('care.checkout_requested', ['fagg_waiver_at' => now()->toIso8601String(), 'ip' => $request->ip()], 'customer:'.$request->user()->email);
        $url = $care->createCheckout($project);
        if (! $url) {
            return response()->json(['payment' => 'unconfigured', 'message' => 'Stripe is not configured yet (staging).'], 503);
        }

        return response()->json(['checkout_url' => $url]);
    }

    /** Cancel Care — stays active until the end of the paid month. */
    public function cancelCare(Request $request, Project $project, CareService $care): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id, 404);
        abort_unless(($project->care_status ?? 'none') === 'active', 409, 'Care is not active');
        abort_if((bool) $project->care_ends_at, 409, 'Care is already cancelled');
        $care->cancel($project, 'customer:'.$request->user()->email);
        $project = $project->fresh();

        return response()->json(['care_status' => $project->care_status, 'care_ends_at' => $project->care_ends_at?->toIso8601String()]);
    }

    /** Customer approves the preview build: REVIEW → READY (guarded). */
    public function approveReview(Request $request, Project $project, PipelineOrchestrator $orchestrator): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id, 404);
        $orchestrator->approveReview($project, 'customer:'.$request->user()->email);
        app(Analytics::class)->record('review_approved', $request, ['order_id' => $project->order_id, 'customer_id' => $project->customer_id]);

        return response()->json(['status' => $project->fresh()->status]);
    }

    /**
     * "Sharpen my change request": the draft comes back precise and testable,
     * with the scope verdict BEFORE the customer pays a round. Limits: Refiner.
     */
    public function refineChangeRequest(Request $request, Project $project, Refiner $refiner): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id, 404);
        $data = $request->validate([
            'text' => 'required|string|min:10|max:800',
            'locale' => 'nullable|in:de,en',
            'answers' => 'array|max:6',
            'answers.*' => 'string|max:200',
        ]);
        $res = $refiner->run([
            'mode' => 'change',
            'text' => $data['text'],
            'locale' => $data['locale'] ?? 'de',
            'answers' => array_values($data['answers'] ?? []),
            'project_id' => $project->id,
            'features' => array_values((array) ($project->order?->quote?->features ?? [])),
        ], $request->user(), (string) $request->ip());

        return response()->json($res['body'], $res['status']);
    }

    /** The change chat thread. `after` = last message id the portal has, for polling. */
    public function changeMessages(Request $request, Project $project, ChangeChat $chat): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id, 404);
        abort_unless(ChangeChat::enabledFor($request->user()), 404);
        ChangeChat::seen($project);

        return response()->json(['messages' => $chat->thread($project, (int) $request->query('after', 0))]);
    }

    /** Picture n of a message in the thread; the portal fetches it with the token and shows a blob. */
    public function changeMessageImage(Request $request, Project $project, ChangeMessage $message, int $n, ChangeShots $shots): BinaryFileResponse
    {
        abort_unless($project->customer_id === $request->user()->id && $message->project_id === $project->id, 404);
        $path = $shots->path($message, $n);
        abort_if($path === null, 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=86400']);
    }

    public function sendChangeMessage(Request $request, Project $project, ChangeChat $chat): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id, 404);
        abort_unless(ChangeChat::enabledFor($request->user()), 404);
        $data = $request->validate([
            'body' => 'nullable|string|max:2000|required_without:images',
            'images' => 'nullable|array|max:'.ChangeShots::MAX_PER_MESSAGE,
            'images.*' => 'string|max:'.(int) ceil(ChangeShots::MAX_BYTES * 4 / 3 + 64),
        ]);

        $res = $chat->customerSays($project, $request->user(), trim((string) ($data['body'] ?? '')), $data['images'] ?? []);
        app(Analytics::class)->record('chat_message', $request, ['order_id' => $project->order_id, 'customer_id' => $project->customer_id], [
            'images' => count($data['images'] ?? []), 'assistant' => $res['status'] === 503 ? 'unavailable' : 'ok',
        ]);

        return response()->json([
            'messages' => $chat->thread($project, $res['messages'][0]->id - 1),
            'assistant' => $res['status'] === 503 ? 'unavailable' : 'ok',
        ], $res['status']);
    }

    /** "Umsetzen" on the newest summary card: starts the round (or the payment for it). */
    public function confirmChange(Request $request, Project $project, ChangeChat $chat): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id, 404);
        abort_unless(ChangeChat::enabledFor($request->user()), 404);
        $data = $request->validate(['fagg_waiver' => 'nullable|boolean']);

        $cr = $chat->confirm($project, $request->user(), (bool) ($data['fagg_waiver'] ?? false), $request->ip());
        app(Analytics::class)->record('change_confirmed', $request, ['order_id' => $project->order_id, 'customer_id' => $project->customer_id], [
            'round' => $cr->round, 'price_eur' => $cr->price_eur,
        ]);

        return response()->json([
            'change_request_id' => $cr->id,
            'status' => $cr->status,
            'checkout_url' => $cr->status === 'awaiting_payment' ? $cr->checkout_url : null,
            'payment' => $cr->status === 'awaiting_payment' && ! $cr->checkout_url ? 'unconfigured' : null,
        ], 201);
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

    /** A bought website: where it is, when it goes live, and the domain the customer asked for. */
    private static function site(Project $project): array
    {
        return [
            'url' => SiteService::url($project),
            'live_at' => $project->prototype?->published_at?->toIso8601String(),
            'live_starts_at' => $project->build_starts_at?->toIso8601String(),
            'prototype_id' => $project->prototype?->id,
            'domain' => $project->domain,
            'domain_requested_at' => $project->domain_requested_at?->toIso8601String(),
            'server_ip' => SiteService::serverIp(),
            'hosting_monthly_eur' => Estimator::SITE_HOSTING_MONTHLY_EUR,
            'hosting_free_months' => Estimator::SITE_HOSTING_FREE_MONTHS,
            'imprint' => $project->imprint,
            'imprint_complete' => Imprint::complete($project->imprint),
            'imprint_url' => Imprint::complete($project->imprint) && $project->prototype?->published_at !== null
                ? LandingController::url($project->prototype).'/impressum' : null,
        ];
    }

    /** The customer fills in their Impressum; the site's footer links it from then on. */
    public function imprint(Request $request, Project $project): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id && $project->kind === 'site', 404);
        $data = $request->validate(Imprint::rules());
        $clean = [];
        foreach ([...Imprint::REQUIRED, ...Imprint::OPTIONAL] as $f) {
            $clean[$f] = trim((string) ($data[$f] ?? ''));
        }
        $project->update(['imprint' => $clean]);
        $project->recordEvent('site.imprint_saved', []);

        return response()->json(self::site($project->fresh()));
    }

    /** The customer names their domain (or clears it); a person connects it. */
    public function domain(Request $request, Project $project, SiteService $sites): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id && $project->kind === 'site', 404);
        $data = $request->validate(['domain' => 'nullable|string|max:300']);
        $domain = SiteService::normalizeDomain((string) ($data['domain'] ?? ''));
        abort_if($domain === false, 422, 'That is not a domain. Enter it like example.at.');
        $sites->requestDomain($project, $domain);

        return response()->json(self::site($project->fresh()));
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
                'kind' => $p->kind,
                'site_url' => $p->kind === 'site' ? SiteService::url($p) : null,
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
            // Only so the portal can show the operator its own entrance. The flag decides nothing:
            // every admin route checks it again on the server.
            'admin' => $request->user()->isAdmin(),
            'projects' => $projects,
        ]);
    }
}
