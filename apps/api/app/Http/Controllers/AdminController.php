<?php

namespace App\Http\Controllers;

use App\Domain\Qa\PageAudit;
use App\Jobs\RenderProjectAd;
use App\Models\Customer;
use App\Models\MarketingCampaign;
use App\Models\Order;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\ProjectAd;
use App\Models\Prototype;
use App\Services\PipelineOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The operator lane: one view of the whole factory, plus the few actions that get a stuck project
 * moving again.
 *
 * Everything here is deliberately narrow. Reading is unrestricted because running the factory means
 * seeing every project; writing is limited to what the artisan operator commands already do
 * (dispatch a stage, re-render an ad) plus a forced status, which is the one thing that has no
 * command and is needed exactly when the automatic transitions have painted a project into a
 * corner. Nothing here touches money: no refunds, no campaign activation, no deletions of paid
 * work. Those stay where they are, behind Stripe and behind the customer's own consent.
 */
class AdminController extends Controller
{
    /** Runs whose worker has been silent this long are shown as stalled (pipeline:tick reaps at 15). */
    private const STALE_MINUTES = 15;

    /** The dashboard: counts to see the shape of the day, and a list of what is actually broken. */
    public function overview(): JsonResponse
    {
        $byStatus = Project::query()->selectRaw('status, count(*) as n')->groupBy('status')
            ->pluck('n', 'status');

        return response()->json([
            'projects' => [
                'total' => (int) $byStatus->sum(),
                'by_status' => $byStatus,
            ],
            'runs' => [
                'queued' => PipelineRun::where('status', 'queued')->count(),
                'running' => PipelineRun::where('status', 'running')->count(),
                'failed_24h' => PipelineRun::where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
            ],
            'ads' => ProjectAd::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'campaigns' => MarketingCampaign::query()->selectRaw('platform_status, count(*) as n')
                ->groupBy('platform_status')->pluck('n', 'platform_status'),
            'customers' => Customer::count(),
            'revenue' => [
                'paid_orders' => Order::where('status', 'paid')->count(),
                'paid_eur' => (int) Order::where('status', 'paid')->sum('total_one_time_eur'),
                'hosting_monthly_eur' => (int) Order::where('status', 'paid')->sum('hosting_monthly_eur'),
                'ad_budget_monthly_eur' => (int) Order::where('status', 'paid')->sum('ad_budget_monthly_eur'),
            ],
            'attention' => $this->attention(),
        ]);
    }

    /**
     * What a person should look at right now, newest first. Deliberately one flat list: a stalled
     * run, a failed render and a project sitting in FAILED are the same job (someone has to push
     * it), and splitting them into three tabs only hides two of them.
     *
     * @return array<int,array<string,mixed>>
     */
    private function attention(): array
    {
        $items = [];

        foreach (Project::where('status', 'FAILED')->with('customer:id,email')->latest('updated_at')->limit(20)->get() as $p) {
            $items[] = [
                'kind' => 'project_failed', 'at' => $p->updated_at->toIso8601String(),
                'project' => ['id' => $p->id, 'name' => $p->name, 'status' => $p->status],
                'customer' => $p->customer?->email,
                'detail' => (string) $p->failed_reason,
            ];
        }

        $runs = PipelineRun::with('project:id,name,status,customer_id', 'project.customer:id,email')
            ->where(fn ($q) => $q->where('status', 'failed')->where('created_at', '>=', now()->subDays(3)))
            ->orWhere(fn ($q) => $q->where('status', 'running')->where('heartbeat_at', '<', now()->subMinutes(self::STALE_MINUTES)))
            ->latest()->limit(30)->get();

        foreach ($runs as $r) {
            $items[] = [
                'kind' => $r->status === 'failed' ? 'run_failed' : 'run_stalled',
                'at' => ($r->finished_at ?? $r->heartbeat_at ?? $r->created_at)->toIso8601String(),
                'project' => $r->project ? ['id' => $r->project->id, 'name' => $r->project->name, 'status' => $r->project->status] : null,
                'customer' => $r->project?->customer?->email,
                'stage' => $r->stage,
                'detail' => mb_substr((string) $r->error, 0, 300),
            ];
        }

        foreach (ProjectAd::where('status', 'failed')->with('project:id,name,status,customer_id', 'project.customer:id,email')
            ->latest()->limit(20)->get() as $a) {
            $items[] = [
                'kind' => 'ad_failed', 'at' => $a->updated_at->toIso8601String(),
                'project' => $a->project ? ['id' => $a->project->id, 'name' => $a->project->name, 'status' => $a->project->status] : null,
                'customer' => $a->project?->customer?->email,
                'ad' => ['id' => $a->id, 'kind' => $a->kind, 'name' => $a->name],
                'detail' => mb_substr((string) $a->error, 0, 300),
            ];
        }

        // A prototype that went out with something a browser could see was wrong. It is live and
        // the visitor has it either way, so this is not a failure to rescue: it is the list that
        // says which prompts the generator keeps getting wrong, which is how the brief improves.
        foreach (Prototype::whereNotNull('qa')->where('created_at', '>=', now()->subDays(3))
            ->latest()->limit(30)->get() as $proto) {
            if (! $proto->qaFailed()) {
                continue;
            }
            $checks = array_column(PageAudit::blocking((array) $proto->qa), 'check');
            $items[] = [
                'kind' => 'prototype_qa', 'at' => $proto->created_at->toIso8601String(),
                'project' => null,
                'customer' => null,
                'prototype' => ['id' => $proto->id, 'title' => $proto->title],
                'detail' => implode(', ', array_unique($checks)).': '.mb_substr((string) $proto->prompt, 0, 120),
            ];
        }

        usort($items, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return array_slice($items, 0, 40);
    }

    /** Every project in the factory, newest first. `q` matches the project name or the customer. */
    public function projects(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => 'nullable|in:'.implode(',', Project::STATUSES),
            'q' => 'nullable|string|max:120',
        ]);

        $projects = Project::query()
            ->with(['customer:id,email', 'order:id,status,total_one_time_eur'])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$term}%")
                ->orWhereHas('customer', fn ($c) => $c->where('email', 'like', "%{$term}%"))))
            ->withCount(['ads', 'campaigns', 'changeRequests'])
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'status' => $p->status,
                'stack' => $p->stack,
                'customer' => $p->customer?->email,
                'created_at' => $p->created_at->toIso8601String(),
                'order' => ['status' => $p->order?->status, 'total_one_time_eur' => (int) ($p->order?->total_one_time_eur ?? 0)],
                'counts' => ['ads' => $p->ads_count, 'campaigns' => $p->campaigns_count, 'change_requests' => $p->change_requests_count],
            ]);

        return response()->json([
            'projects' => $projects,
            'statuses' => Project::STATUSES,
            'stages' => PipelineRun::STAGES,
        ]);
    }

    /** One project, deep enough to judge what went wrong without opening a database console. */
    public function project(Project $project): JsonResponse
    {
        $project->load(['customer:id,email,name', 'order']);

        return response()->json([
            'id' => $project->id,
            'name' => $project->name,
            'status' => $project->status,
            'stack' => $project->stack,
            'failed_reason' => $project->failed_reason,
            'care_status' => $project->care_status ?? 'none',
            'build_starts_at' => $project->build_starts_at?->toIso8601String(),
            'created_at' => $project->created_at->toIso8601String(),
            'customer' => ['email' => $project->customer?->email, 'name' => $project->customer?->name],
            'order' => $project->order ? [
                'id' => $project->order->id,
                'status' => $project->order->status,
                'total_one_time_eur' => (int) $project->order->total_one_time_eur,
                'hosting_monthly_eur' => (int) $project->order->hosting_monthly_eur,
                'ad_budget_monthly_eur' => (int) $project->order->ad_budget_monthly_eur,
                'packages' => $project->order->packages,
            ] : null,
            'runs' => $project->runs()->latest()->limit(40)
                ->get(['id', 'stage', 'attempt', 'status', 'error', 'started_at', 'heartbeat_at', 'finished_at']),
            'ads' => $project->ads()->latest()->get(['id', 'kind', 'name', 'status', 'error', 'bytes', 'created_at']),
            'campaigns' => $project->campaigns()->latest()
                ->get(['id', 'platform', 'status', 'platform_status', 'ad_budget_monthly_eur', 'publish_error']),
            'change_requests' => $project->changeRequests()->latest('id')
                ->get(['id', 'round', 'status', 'text', 'price_eur', 'created_at']),
            'events' => $project->events()->latest('created_at')->limit(80)
                ->get(['type', 'payload', 'actor', 'created_at']),
        ]);
    }

    /** Customers with what they are worth and how much of the factory they are using. */
    public function customers(): JsonResponse
    {
        $customers = Customer::query()
            ->withCount(['projects', 'orders'])
            ->with(['orders:id,customer_id,status,total_one_time_eur'])
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'email' => $c->email,
                'name' => $c->name,
                'is_admin' => $c->isAdmin(),
                'projects' => $c->projects_count,
                'orders' => $c->orders_count,
                'paid_eur' => (int) $c->orders->where('status', 'paid')->sum('total_one_time_eur'),
                'created_at' => $c->created_at->toIso8601String(),
            ]);

        return response()->json(['customers' => $customers]);
    }

    /** Every ad in the factory, so a broken render can be found without asking the customer. */
    public function ads(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => 'nullable|in:queued,rendering,ready,failed']);

        $ads = ProjectAd::query()
            ->with(['project:id,name,customer_id', 'project.customer:id,email'])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest()->limit(200)->get()
            ->map(fn (ProjectAd $a) => [
                'id' => $a->id,
                'kind' => $a->kind,
                'name' => $a->name,
                'status' => $a->status,
                'error' => $a->error,
                'bytes' => (int) $a->bytes,
                'created_at' => $a->created_at->toIso8601String(),
                'project' => $a->project ? ['id' => $a->project->id, 'name' => $a->project->name] : null,
                'customer' => $a->project?->customer?->email,
            ]);

        return response()->json(['ads' => $ads]);
    }

    /**
     * Re-dispatch one stage, the way `factory:stage` does. The refusal while another stage is
     * running is the same one the command has: two workers in the same repo overwrite each other.
     */
    public function dispatchStage(Request $request, Project $project, PipelineOrchestrator $orchestrator): JsonResponse
    {
        $data = $request->validate(['stage' => 'required|in:'.implode(',', PipelineRun::STAGES)]);

        abort_if($project->runs()->where('status', 'running')->exists(), 409, 'Für dieses Projekt läuft schon ein Stage.');

        $run = $orchestrator->dispatch($project, $data['stage'], $this->actor($request));

        return response()->json(['run_id' => $run->id, 'stage' => $run->stage], 202);
    }

    /**
     * Force a status. The pipeline sets this itself and normally nobody should touch it; it exists
     * because a project that the transitions have parked in the wrong state cannot be rescued from
     * inside the pipeline. It is written to the event log with the operator's name for that reason.
     */
    public function setStatus(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:'.implode(',', Project::STATUSES),
            'reason' => 'nullable|string|max:300',
        ]);

        $from = $project->status;
        $project->update([
            'status' => $data['status'],
            'failed_reason' => $data['status'] === 'FAILED' ? ($data['reason'] ?? $project->failed_reason) : null,
        ]);
        $project->recordEvent('status.changed', [
            'from' => $from, 'to' => $data['status'], 'forced' => true, 'reason' => $data['reason'] ?? null,
        ], $this->actor($request));

        return response()->json(['status' => $project->status]);
    }

    /**
     * Put a finished or failed ad back on the queue. The row keeps its prompt and its spec, but the
     * scenes are dropped so the copy is written again: re-rendering the same broken scene script
     * would only pay for the same broken pictures a second time.
     */
    public function rerenderAd(Request $request, ProjectAd $ad): JsonResponse
    {
        abort_if(in_array($ad->status, ['queued', 'rendering'], true), 409, 'Diese Anzeige läuft gerade.');
        abort_if($ad->project?->ads()->whereIn('status', ['queued', 'rendering'])->exists(), 409, 'Für dieses Projekt läuft schon ein Render.');

        $spec = (array) ($ad->spec ?? []);
        unset($spec['scenes']);
        $ad->update(['status' => 'queued', 'error' => null, 'spec' => $spec]);
        $ad->project?->recordEvent('ad.rerender', ['ad_id' => $ad->id], $this->actor($request));
        RenderProjectAd::dispatch($ad->id);

        return response()->json(['id' => $ad->id, 'status' => $ad->status], 202);
    }

    private function actor(Request $request): string
    {
        return 'admin:'.$request->user()->email;
    }
}
