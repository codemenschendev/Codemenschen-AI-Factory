<?php

namespace Tests\Feature;

use App\Jobs\BuildPrototype;
use App\Models\Customer;
use App\Models\Prototype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** One free prototype per account (owner's decision 2026-09-25); a failed build does not count. */
class OneFreePrototypeTest extends TestCase
{
    use RefreshDatabase;

    private const BRIEF = ['prompt' => 'Website für eine Bootsschule in Rijeka', 'kind' => 'site'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_a_signed_in_customer_gets_one_and_a_failed_build_gives_it_back(): void
    {
        $me = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        $this->actingAs($me, 'sanctum')->postJson('/api/prototypes', self::BRIEF)->assertStatus(202);
        $this->actingAs($me, 'sanctum')->postJson('/api/prototypes', self::BRIEF)->assertForbidden()->assertJson(['code' => 'used']);

        Prototype::sole()->update(['status' => 'failed']);
        $this->actingAs($me, 'sanctum')->postJson('/api/prototypes', self::BRIEF)->assertStatus(202);
        Queue::assertPushed(BuildPrototype::class, 2);
    }

    public function test_an_address_that_used_its_free_one_gets_no_mail_for_another(): void
    {
        $this->postJson('/api/prototypes', self::BRIEF + ['email' => 'Neu@Example.com'])->assertStatus(202);
        // Waiting for the link already counts: the second request is stopped before any mail.
        $this->postJson('/api/prototypes', self::BRIEF + ['email' => 'neu@example.com'])->assertForbidden()->assertJson(['code' => 'used']);
        $this->assertSame(1, Prototype::count());
    }

    public function test_the_second_of_two_links_opened_builds_nothing(): void
    {
        $a = Prototype::create(['status' => 'waiting', 'kind' => 'site', 'prompt' => 'x', 'ip' => '203.0.113.9', 'qa' => ['pending_email' => 'zwei@example.com'], 'expires_at' => now()->addDay()]);
        $b = Prototype::create(['status' => 'waiting', 'kind' => 'site', 'prompt' => 'y', 'ip' => '203.0.113.9', 'qa' => ['pending_email' => 'zwei@example.com'], 'expires_at' => now()->addDay()]);
        $link = fn (Prototype $p) => URL::temporarySignedRoute('prototypes.confirm', now()->addDay(), ['prototype' => $p->id, 'locale' => 'de']);

        $this->get($link($a))->assertRedirect();
        $this->get($link($b))->assertRedirect();

        $this->assertSame('queued', $a->fresh()->status);
        $this->assertSame('failed', $b->fresh()->status);
        Queue::assertPushed(BuildPrototype::class, 1);
    }

    public function test_an_admin_is_not_counted(): void
    {
        $admin = Customer::create(['email' => 'chef@example.com', 'locale' => 'de', 'is_admin' => true]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/prototypes', self::BRIEF)->assertStatus(202);
        $this->actingAs($admin, 'sanctum')->postJson('/api/prototypes', self::BRIEF)->assertStatus(202);
    }
}
