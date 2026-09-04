<?php

namespace Tests\Feature;

use App\Jobs\BuildPrototype;
use App\Models\Customer;
use App\Models\Prototype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The free tier's daily cap.
 *
 * It counts per visitor, which only works if the framework knows who the visitor is. Behind the
 * reverse proxy every request arrived from the docker gateway, so the cap was global: five
 * prototypes a day for the whole internet, and the fifth visitor locked out the sixth.
 */
class PrototypeCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function build(array $headers = []): TestResponse
    {
        return $this->postJson('/api/prototypes', [
            'prompt' => 'Eine App für ein Friseurstudio in Wien mit Terminbuchung',
            'kind' => 'app',
        ], $headers);
    }

    /** @param string $ip fills the cap for one address */
    private function fill(string $ip, int $n = 5): void
    {
        for ($i = 0; $i < $n; $i++) {
            Prototype::create(['status' => 'ready', 'prompt' => 'x', 'ip' => $ip, 'expires_at' => now()->addDay()]);
        }
    }

    public function test_a_visitor_gets_five_a_day(): void
    {
        $this->fill('203.0.113.7');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->build()->assertStatus(429);
    }

    public function test_one_visitor_hitting_the_cap_does_not_block_another(): void
    {
        // The whole point of "per IP". Before trusted proxies this failed: both requests came
        // from the gateway, so the second visitor was refused for the first one's usage.
        $this->fill('203.0.113.7');

        $this->build(['X-Forwarded-For' => '198.51.100.4'])->assertStatus(202);
    }

    public function test_the_forwarded_address_is_the_one_that_is_counted(): void
    {
        $this->fill('198.51.100.4');

        $this->build(['X-Forwarded-For' => '198.51.100.4'])->assertStatus(429);
        $this->build(['X-Forwarded-For' => '198.51.100.9'])->assertStatus(202);
    }

    public function test_an_admin_is_not_capped(): void
    {
        $this->fill('198.51.100.4');
        $token = Customer::create(['email' => 'ops@example.com', 'locale' => 'de', 'is_admin' => true])
            ->createToken('portal')->plainTextToken;

        $this->build(['X-Forwarded-For' => '198.51.100.4', 'Authorization' => 'Bearer '.$token])
            ->assertStatus(202);

        Queue::assertPushed(BuildPrototype::class);
    }

    public function test_a_signed_in_customer_is_still_capped(): void
    {
        $this->fill('198.51.100.4');
        $token = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de'])
            ->createToken('portal')->plainTextToken;

        $this->build(['X-Forwarded-For' => '198.51.100.4', 'Authorization' => 'Bearer '.$token])
            ->assertStatus(429);
    }

    public function test_the_prototype_records_the_real_address(): void
    {
        $this->build(['X-Forwarded-For' => '198.51.100.22'])->assertStatus(202);

        $this->assertSame('198.51.100.22', Prototype::latest()->first()->ip);
    }
}
