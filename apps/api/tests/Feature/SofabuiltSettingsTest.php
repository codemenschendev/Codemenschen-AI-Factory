<?php

namespace Tests\Feature;

use App\Domain\Sofabuilt\Platforms;
use App\Domain\Sofabuilt\Pricing;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sofabuilt's own switches in the admin console (Domain\Sofabuilt\Settings). */
class SofabuiltSettingsTest extends TestCase
{
    use RefreshDatabase;

    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Prices here are build time only; the token cost has its own test (AppDeskTest).
        \App\Domain\Sofabuilt\Settings::write(['token_pct' => 0], 'test');
        $admin = Customer::create(['email' => 'admin@example.com', 'locale' => 'de', 'is_admin' => true]);
        $this->admin = ['Authorization' => 'Bearer '.$this->consoleToken($admin)];
    }

    public function test_only_an_admin_reads_and_changes_them(): void
    {
        $customer = Customer::create(['email' => 'c@example.com']);
        $this->withHeader('Authorization', 'Bearer '.$customer->createToken('portal', ['portal'])->plainTextToken)
            ->getJson('/api/admin/sofabuilt')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->admin)->getJson('/api/admin/sofabuilt')->assertOk()
            ->assertJsonPath('settings.price_pct', 100)->assertJsonPath('settings.desk_open', true);
    }

    public function test_the_switches_change_prices_platforms_and_the_desk(): void
    {
        $base = Pricing::quote(['platform' => 'wordpress'])['build_eur'];
        $this->withHeaders($this->admin)->postJson('/api/admin/sofabuilt', ['price_pct' => 50, 'offered' => ['shopify'], 'desk_open' => false])
            ->assertOk()->assertJsonPath('settings.price_pct', 50);

        $this->assertSame((int) round($base / 2), Pricing::quote(['platform' => 'wordpress'])['build_eur']);
        $this->assertSame(['shopify'], Platforms::offered());
        $this->postJson('/api/desk', ['door' => 'idea', 'platform' => 'shopify'])->assertStatus(503);

        $this->withHeaders($this->admin)->postJson('/api/admin/sofabuilt', ['price_pct' => 5])->assertUnprocessable();
    }
}
