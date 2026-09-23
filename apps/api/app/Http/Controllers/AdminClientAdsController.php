<?php

namespace App\Http\Controllers;

use App\Domain\Ads\AccountLink;
use App\Models\AdAccountLink;
use App\Models\Customer;
use App\Models\MarketingCampaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Every client's advertising on one screen (2026-09-23).
 *
 * Clients only. A campaign belongs here when it hangs off a customer's project; Appwerk's own
 * campaigns and the prototype validation tests have no project and stay out. What Codemenschen
 * spends on itself is its own invoice and is kept apart from what it runs for somebody else.
 *
 * Read-only apart from checking an account again. Starting and pausing a campaign go through the
 * routes that already exist for that, behind the spend guard, so there is one door for money.
 */
class AdminClientAdsController extends Controller
{
    /** A spend check older than this on a running campaign is a problem worth seeing. */
    private const STALE_MINUTES = 60;

    public function index(): JsonResponse
    {
        $campaigns = MarketingCampaign::query()
            ->whereNotNull('project_id')
            ->with(['project:id,name,customer_id'])
            ->latest()
            ->limit(1000)
            ->get()
            ->filter(fn (MarketingCampaign $c) => $c->project?->customer_id !== null);

        $links = AdAccountLink::query()->orderBy('platform')->get();

        $customerIds = $campaigns->pluck('project.customer_id')->merge($links->pluck('customer_id'))->unique()->values();
        $customers = Customer::whereIn('id', $customerIds)->get(['id', 'email'])->keyBy('id');

        $clients = $customerIds->map(function ($id) use ($customers, $campaigns, $links) {
            // Loose on purpose: a foreign key comes back as a string from some drivers and an int from others.
            $mine = $campaigns->filter(fn (MarketingCampaign $c) => $c->project->customer_id == $id)->values();
            $accounts = $links->where('customer_id', $id)->values();
            $rows = $mine->map(fn (MarketingCampaign $c) => self::campaign($c, $accounts));
            $accountRows = $accounts->map(fn (AdAccountLink $l) => self::account($l));

            return [
                'id' => $id,
                'email' => $customers[$id]->email ?? '?',
                'accounts' => $accountRows->all(),
                'campaigns' => $rows->all(),
                'running' => $rows->where('status', 'active')->count(),
                'spent_eur' => round((float) $rows->sum('spent_eur'), 2),
                'spent_today_eur' => round((float) $rows->sum('spent_today_eur'), 2),
                'problems' => $rows->sum(fn ($r) => count($r['problems'])) + $accountRows->sum(fn ($r) => count($r['problems'])),
            ];
        })
            // What needs a person first: problems, then what is spending, then the rest.
            ->sortBy([['problems', 'desc'], ['running', 'desc'], ['spent_eur', 'desc']])
            ->values();

        return response()->json([
            'totals' => [
                'clients' => $clients->count(),
                'campaigns' => $campaigns->count(),
                'running' => $clients->sum('running'),
                'spent_today_eur' => round((float) $clients->sum('spent_today_eur'), 2),
                'spent_eur' => round((float) $clients->sum('spent_eur'), 2),
                'problems' => $clients->sum('problems'),
                'accounts' => [
                    'active' => $links->where('status', 'active')->count(),
                    'pending' => $links->where('status', 'pending')->count(),
                    'refused' => $links->whereIn('status', ['refused', 'removed'])->count(),
                ],
            ],
            'clients' => $clients,
        ]);
    }

    /** "Look again": asks the platform, same as the customer's own button. */
    public function refreshAccount(AdAccountLink $adAccount, AccountLink $links): JsonResponse
    {
        return response()->json(['account' => self::account($links->refresh($adAccount))]);
    }

    /**
     * @param  Collection<int,AdAccountLink>  $accounts  the client's connected accounts
     * @return array<string,mixed>
     */
    private static function campaign(MarketingCampaign $c, Collection $accounts): array
    {
        // Whose account it runs on is decided at publish time by AdTarget: the client's own when
        // they connected one for this platform, ours otherwise. Shown because it decides whose
        // card is charged.
        $own = $accounts->first(fn (AdAccountLink $l) => $l->platform === $c->platform && $l->status === 'active');

        // A code the screen puts into words in its own language, and the platform's own message
        // as it came, when there is one: that part is evidence and is not translated.
        $problems = [];
        if ($c->platform_status === 'failed' || $c->publish_error) {
            $problems[] = ['code' => 'publish_failed', 'detail' => mb_substr((string) $c->publish_error, 0, 200) ?: null];
        }
        if ($c->platform_status === 'active' && ($c->spend_checked_at === null || $c->spend_checked_at->lt(now()->subMinutes(self::STALE_MINUTES)))) {
            $problems[] = ['code' => 'spend_stale', 'detail' => null];
        }
        if ($c->platform_status === 'active' && $c->spend_cap_eur !== null && (float) $c->spent_eur >= (float) $c->spend_cap_eur) {
            $problems[] = ['code' => 'over_total', 'detail' => null];
        }

        return [
            'id' => $c->id,
            'project' => ['id' => $c->project->id, 'name' => $c->project->name],
            'platform' => $c->platform,
            'status' => $c->platform_status ?: 'draft',
            'runs_on' => $own !== null ? 'client' : 'appwerk',
            'account' => $own?->platform === 'google' ? AccountLink::dashed((string) $own->external_id) : $own?->external_id,
            'budget_monthly_eur' => (int) $c->ad_budget_monthly_eur,
            'spend_cap_eur' => $c->spend_cap_eur,
            'spent_eur' => (float) $c->spent_eur,
            'spent_today_eur' => (float) $c->spent_today_eur,
            'impressions' => (int) $c->impressions,
            'clicks' => (int) $c->link_clicks,
            'ends_at' => $c->ends_at?->toIso8601String(),
            'checked_at' => $c->spend_checked_at?->toIso8601String(),
            'stopped_reason' => $c->stopped_reason,
            'problems' => $problems,
        ];
    }

    /** @return array<string,mixed> */
    private static function account(AdAccountLink $l): array
    {
        $problems = [];
        if (in_array($l->status, ['refused', 'removed'], true)) {
            $problems[] = ['code' => 'account_'.$l->status, 'detail' => null];
        }
        if ($l->error) {
            $problems[] = ['code' => 'account_error', 'detail' => mb_substr((string) $l->error, 0, 200)];
        }
        if ($l->platform === 'meta' && $l->status === 'active' && (string) $l->page_id === '') {
            $problems[] = ['code' => 'meta_no_page', 'detail' => null];
        }

        return [
            'id' => $l->id,
            'platform' => $l->platform,
            'external_id' => $l->platform === 'google' ? AccountLink::dashed((string) $l->external_id) : $l->external_id,
            'name' => $l->name,
            'page_name' => $l->page_name,
            'status' => $l->status,
            'checked_at' => $l->checked_at?->toIso8601String(),
            'problems' => $problems,
        ];
    }
}
