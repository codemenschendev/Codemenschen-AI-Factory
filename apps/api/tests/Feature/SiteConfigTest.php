<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Tag Manager container is set in the console and read by the storefront without a rebuild. */
class SiteConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_container_set_in_the_console_reaches_the_storefront(): void
    {
        $this->getJson('/api/site-config')->assertOk()->assertJsonPath('gtm_id', null);

        $admin = Customer::create(['email' => 'chef@example.com', 'locale' => 'de', 'is_admin' => true]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/ads/settings', ['gtm_id' => 'GTM-AB12CD3'])->assertOk();

        $this->getJson('/api/site-config')->assertOk()->assertJsonPath('gtm_id', 'GTM-AB12CD3');
    }

    public function test_something_that_is_not_a_container_id_is_never_served(): void
    {
        config(['services.analytics.gtm_id' => '"><script>alert(1)</script>']);

        $this->getJson('/api/site-config')->assertOk()->assertJsonPath('gtm_id', null);
    }
}
