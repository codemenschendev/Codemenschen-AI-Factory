<?php

namespace Tests\Unit;

use App\Domain\Pricing\Estimator;
use PHPUnit\Framework\TestCase;

/** Parity suite — mirrors packages/pricing/src/estimate.test.ts exactly. */
class EstimatorTest extends TestCase
{
    public function test_web_consumer_floor_matches_ts_engine(): void
    {
        $e = Estimator::estimate('consumer', 'web', []);
        $this->assertSame(150, $e['devLo']);
        $this->assertSame(250, $e['devHi']);
        $this->assertSame(250, $e['price']); // 200 * 1.2 = 240 → rounded to 50
        $this->assertSame(3, $e['weeksLo']);
        $this->assertSame(6, $e['weeksHi']);
        $this->assertSame('A', $e['appType']);
        $this->assertSame(0, $e['hostingMonthly']);
    }

    public function test_loaded_b2b_both_platform_stays_under_cap(): void
    {
        $e = Estimator::estimate('b2b', 'both', ['auth', 'pay', 'dash', 'ai', 'notif', 'api']);
        // (450 + 75) * 1.15 = 603.75 → * 1.2 = 724.5 → 700; cap is 1500
        $this->assertSame(700, $e['price']);
        $this->assertSame(14, $e['weeksHi']);
        $this->assertSame('B', $e['appType']);
        $this->assertSame(19, $e['hostingMonthly']);
    }

    public function test_type_classification(): void
    {
        $this->assertSame('A', Estimator::appType(['offline', 'i18n', 'dash']));
        $this->assertSame('B', Estimator::appType(['auth']));
    }

    public function test_one_time_total_adds_selected_packages_only(): void
    {
        $total = Estimator::oneTimeTotal(250, ['storePublishing' => true, 'marketingLaunch' => true]);
        $this->assertSame(250 + 79 + 129, $total);
    }
}
