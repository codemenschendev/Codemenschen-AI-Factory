<?php

namespace Tests\Feature;

use App\Domain\Sofabuilt\BuildStats;
use App\Models\PipelineRun;

/** Real build time and tokens against the desk's estimate, for setting the usual minutes. */
class BuildStatsTest extends PluginFeatureTest
{
    public function test_a_build_is_measured_against_its_estimate_with_cache_tokens_counted(): void
    {
        $project = $this->plugin();
        $project->runs()->delete();
        $t = now()->subHour();
        $run = fn (string $stage, int $sec, array $tokens) => PipelineRun::create(['project_id' => $project->id, 'stage' => $stage, 'attempt' => 1,
            'status' => 'succeeded', 'callback_token' => 'x', 'started_at' => $t, 'finished_at' => $t->copy()->addSeconds($sec)] + $tokens);
        $run('product', 60, ['tokens_in' => 3000, 'tokens_out' => 2000]);
        $run('coding', 180, ['tokens_in' => 4, 'tokens_out' => 9000, 'tokens_cache_read' => 800000, 'tokens_cache_write' => 40000]);
        $run('release', 600, []); // not a build stage

        $stats = BuildStats::recent();
        $row = $stats['rows'][0];
        $this->assertSame([4.0, 4, 854], [$row['real_minutes'], $row['estimated_minutes'], $row['real_tokens_k']]);
        // 3004 in x 2 + 11000 out x 10 + 800k cache read x 0.2 + 40k cache write x 2.5 = $0.376, in EUR.
        $this->assertEqualsWithDelta(0.35, $row['real_token_eur'], 0.001);
        $this->assertTrue($row['tokens_measured']);
        $this->assertSame(['wp-plugin' => 1.0], $stats['ratios']);
    }

    public function test_the_worker_reports_cache_tokens(): void
    {
        $project = $this->plugin();
        $run = PipelineRun::create(['project_id' => $project->id, 'stage' => 'coding', 'attempt' => 9, 'status' => 'running', 'started_at' => now(), 'callback_token' => 'cb']);
        $this->postJson("/api/internal/runs/{$run->id}/complete", ['status' => 'failed', 'error' => 'x', 'tokens_in' => 4, 'tokens_out' => 10, 'tokens_cache_read' => 5000, 'tokens_cache_write' => 300],
            ['Authorization' => 'Bearer cb'])->assertOk();
        $this->assertSame([5000, 300], [(int) $run->fresh()->tokens_cache_read, (int) $run->fresh()->tokens_cache_write]);
    }
}
