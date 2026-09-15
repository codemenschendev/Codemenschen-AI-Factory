<?php

namespace App\Domain\Analytics;

/**
 * The analytics report as a chat message: short, English, numbers first. Posted to
 * #appwerk-agents weekly and on "!stats appwerk", where the team and the agent read it. Only
 * aggregates leave the server this way, never a single visitor.
 */
class AnalyticsDigest
{
    public function __construct(private AnalyticsReport $report) {}

    public function text(int $days): string
    {
        $r = $this->report->summary($days);
        $pct = fn (int $a, int $b) => $b > 0 ? round($a / $b * 100).'%' : '-';
        $list = fn (array $rows, string $count = 'visitors', int $n = 5) => $rows
            ? implode(', ', array_map(fn ($x) => "{$x['key']} {$x[$count]}", array_slice($rows, 0, $n)))
            : 'none';

        $lines = [
            "Appwerk analytics, last {$days} ".($days === 1 ? 'day' : 'days').' (visitors counted per day, no cookies)',
            '',
            "Visitors {$r['totals']['visitors']}, page views {$r['totals']['page_views']}, paid orders {$r['totals']['orders_paid']}, revenue {$r['totals']['revenue_eur']} EUR",
            '',
            'Funnel:',
        ];
        $prev = null;
        foreach ($r['funnel'] as $step) {
            $lines[] = "- {$step['step']}: {$step['visitors']}".($prev ? ' ('.$pct($step['visitors'], $prev).' of the step before)' : '');
            $prev = $step['visitors'];
        }
        $p = $r['prototypes'];
        $lines[] = '';
        $lines[] = "Prototypes: requested {$p['requested']}, viewed {$p['viewed']}, went on to a real app {$p['made_real']}";
        $lines[] = 'Orders came from: '.$list($r['paid_sources'], 'orders');
        $lines[] = 'Referrers: '.$list($r['referrers']);
        $lines[] = 'Campaigns: '.$list($r['campaigns']);
        $lines[] = 'Top pages: '.$list($r['pages']);
        $lines[] = 'Devices: '.$list($r['devices'], 'visitors', 3);

        return implode("\n", $lines);
    }
}
