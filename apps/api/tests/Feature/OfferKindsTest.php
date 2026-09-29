<?php

namespace Tests\Feature;

use App\Jobs\BuildPrototype;
use App\Models\Customer;
use App\Models\Prototype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The owner's offer switch (2026-09-29): the storefront sells apps only for now. The other kinds
 * stay built; a customer cannot ask for them, an admin still can, and the switch brings them back.
 */
class OfferKindsTest extends TestCase
{
    use RefreshDatabase;

    private const PROMPT = 'Eine App für meine Tanzschule mit Kursbuchung';

    public function test_apps_only_by_default_and_the_storefront_is_told(): void
    {
        $this->getJson('/api/site-config')->assertOk()->assertJsonPath('kinds', ['app']);
    }

    public function test_a_customer_gets_an_app_and_is_refused_a_website(): void
    {
        Queue::fake();
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);

        $this->actingAs($customer, 'sanctum')->postJson('/api/prototypes', ['prompt' => self::PROMPT, 'kind' => 'site'])
            ->assertStatus(422)->assertJsonPath('code', 'kind_off');
        // No kind given: the first thing on offer, the app.
        $id = $this->actingAs($customer, 'sanctum')->postJson('/api/prototypes', ['prompt' => self::PROMPT])
            ->assertStatus(202)->json('id');

        $this->assertSame('app', Prototype::find($id)->kind);
        Queue::assertPushed(BuildPrototype::class, 1);
    }

    public function test_an_admin_switches_the_offer_and_may_still_test_every_kind(): void
    {
        Queue::fake();
        $admin = Customer::create(['email' => 'admin@example.com', 'locale' => 'de', 'is_admin' => true]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/prototypes', ['prompt' => self::PROMPT, 'kind' => 'ads'])->assertStatus(202);

        $token = $this->consoleToken($admin);
        $this->withToken($token)->postJson('/api/admin/offer-kinds', ['kinds' => ['site', 'app', 'nonsense']])->assertStatus(422);
        $this->withToken($token)->postJson('/api/admin/offer-kinds', ['kinds' => ['site', 'app']])
            ->assertOk()->assertJsonPath('offer_kinds', ['site', 'app']);

        $this->getJson('/api/site-config')->assertJsonPath('kinds', ['site', 'app']);
    }
}
