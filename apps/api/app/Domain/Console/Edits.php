<?php

namespace App\Domain\Console;

use App\Models\Customer;
use App\Models\Project;

/**
 * What pays for a change in the console (config/console.php): Care's monthly allowance, then the
 * customer's change credits. Credits belong to the customer and work for every project they own.
 */
class Edits
{
    /** Changes Care still covers this month; null means Care covers every change. */
    public static function careLeft(Project $project): ?int
    {
        if (! in_array($project->order?->brand ?? 'appmitki', (array) config('console.care_quota_brands'), true)) {
            return null;
        }
        $used = $project->changeRequests()->where('covered_by', 'care')->where('created_at', '>=', now()->startOfMonth())->count();

        return max(0, (int) config('console.care_edits_per_month') - $used);
    }

    public static function credits(Project $project): int
    {
        return (int) ($project->customer?->edit_credits ?? 0);
    }

    /** Takes one credit; false when there was none left (two tabs, one credit). */
    public static function spend(Customer $customer): bool
    {
        return Customer::whereKey($customer->id)->where('edit_credits', '>', 0)->decrement('edit_credits') === 1;
    }

    /** @return array<int, int> changes => EUR */
    public static function packs(): array
    {
        return array_map('intval', (array) config('console.packs'));
    }
}
