<?php

namespace App\Domain\Sofabuilt;

use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * What a build really took against what the desk estimated (owner, 2026-10-06: "the time
 * estimate has to come from real numbers"). Per project: the minutes the AI worked in the build
 * stages and every token it used, cache included, with their cost at the model's list price. The
 * median ratio per platform says how far the usual minutes are from reality, so they can be set
 * from measurements and the price level from the platform's factor.
 */
class BuildStats
{
    /** The stages of a first build; change rounds and the store build are left out. */
    public const STAGES = ['product', 'uiux', 'coding', 'test', 'fix'];

    /** @return array{rows: list<array<string, mixed>>, ratios: array<string, float>} */
    public static function recent(int $limit = 20): array
    {
        $projects = Project::whereIn('kind', ['app', 'plugin'])
            ->whereHas('runs', fn ($q) => $q->whereIn('stage', self::STAGES)->whereNotNull('finished_at'))
            ->with(['order.quote', 'runs' => fn ($q) => $q->whereIn('stage', self::STAGES)->whereNotNull('finished_at')->whereNotNull('started_at')])
            ->latest()->limit($limit)->get();

        $rows = $projects->map(function (Project $p) {
            $runs = $p->runs;
            $seconds = $runs->sum(fn ($r) => max(0, $r->finished_at->getTimestamp() - $r->started_at->getTimestamp()));
            $lines = collect($p->order?->quote?->breakdown['lines'] ?? []);
            $estimated = $lines->sum('minutes');

            return [
                'name' => $p->name,
                'stack' => $p->stack,
                'status' => $p->status,
                'runs' => $runs->count(),
                'real_minutes' => round($seconds / 60, 1),
                'estimated_minutes' => $estimated > 0 ? (int) $estimated : null,
                'real_tokens_k' => (int) round(($runs->sum('tokens_in') + $runs->sum('tokens_out') + $runs->sum('tokens_cache_read') + $runs->sum('tokens_cache_write')) / 1000),
                'estimated_tokens_k' => $lines->sum('tokens_k') ?: null,
                'real_token_eur' => round(self::cost($runs), 2),
                'tokens_measured' => $runs->sum('tokens_cache_read') + $runs->sum('tokens_cache_write') > 0,
            ];
        });

        return ['rows' => $rows->values()->all(), 'ratios' => self::ratios($rows)];
    }

    /** Real over estimated minutes, the median per stack, for builds that had a desk estimate. */
    private static function ratios(Collection $rows): array
    {
        return $rows->filter(fn ($r) => $r['estimated_minutes'] && $r['real_minutes'] > 0)
            ->groupBy('stack')
            ->map(fn ($g) => round($g->map(fn ($r) => $r['real_minutes'] / $r['estimated_minutes'])->median(), 3))
            ->all();
    }

    private static function cost(Collection $runs): float
    {
        $t = (array) config('sofabuilt.tokens');
        $p = (array) ($t['usd_per_mtok'] ?? []);
        $usd = ($runs->sum('tokens_in') * ($p['in'] ?? 2) + $runs->sum('tokens_out') * ($p['out'] ?? 10)
            + $runs->sum('tokens_cache_read') * ($p['cache_read'] ?? 0.2) + $runs->sum('tokens_cache_write') * ($p['cache_write'] ?? 2.5)) / 1_000_000;

        return $usd * (float) ($t['usd_eur'] ?? 0.92);
    }
}
