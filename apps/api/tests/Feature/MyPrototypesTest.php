<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Prototype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The account's own prototypes, on any browser (2026-09-26). */
class MyPrototypesTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_only_the_customers_own_top_level_prototypes(): void
    {
        $me = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        $other = Customer::create(['email' => 'andere@example.com', 'locale' => 'de']);
        $make = fn (array $a) => Prototype::create($a + ['kind' => 'site', 'prompt' => 'Bootsschule', 'status' => 'ready', 'expires_at' => now()->addDays(3)]);

        $mine = $make(['customer_id' => $me->id, 'title' => 'AC Nautik']);
        $old = $make(['customer_id' => $me->id, 'expires_at' => now()->subDay()]);
        $campaign = $make(['customer_id' => $me->id, 'kind' => 'campaign']);
        $make(['customer_id' => $me->id, 'parent_id' => $campaign->id]);
        $make(['customer_id' => $me->id, 'status' => 'waiting']);
        $make(['customer_id' => $other->id]);

        $rows = $this->actingAs($me, 'sanctum')->getJson('/api/me/prototypes')->assertOk()->json('prototypes');

        $this->assertEqualsCanonicalizing([$mine->id, $old->id, $campaign->id], array_column($rows, 'id'));
        $this->assertSame('expired', collect($rows)->firstWhere('id', $old->id)['status']);
        $this->assertSame('AC Nautik', collect($rows)->firstWhere('id', $mine->id)['title']);
    }

    public function test_needs_a_sign_in(): void
    {
        $this->getJson('/api/me/prototypes')->assertUnauthorized();
    }
}
