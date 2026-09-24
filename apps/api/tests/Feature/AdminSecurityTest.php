<?php

namespace Tests\Feature;

use App\Domain\Security\Audit;
use App\Domain\Security\Totp;
use App\Models\AuditLog;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Customer
    {
        return Customer::create(['email' => 'chef@example.com', 'locale' => 'de', 'is_admin' => true]);
    }

    private function bearer(string $token): array
    {
        // The test app keeps the guard's user between requests; a different token must be read afresh.
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$token];
    }

    /** Sets 2FA up on a fresh sign-in and returns the secret. */
    private function enrol(Customer $admin, string $token): string
    {
        $secret = $this->postJson('/api/admin/2fa/setup', [], $this->bearer($token))->assertOk()->json('secret');
        $this->postJson('/api/admin/2fa/enable', ['code' => Totp::at($secret, intdiv(time(), 30))], $this->bearer($token))
            ->assertOk()->assertJsonCount(10, 'recovery_codes');

        return $secret;
    }

    public function test_totp_matches_the_rfc_6238_vectors(): void
    {
        // RFC 6238 appendix B, SHA-1 secret "12345678901234567890", last six of the eight digits.
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', Totp::at($secret, intdiv(59, 30)));
        $this->assertSame('081804', Totp::at($secret, intdiv(1111111109, 30)));
        $this->assertSame(1, Totp::verify($secret, '287 082', null, 59));
        $this->assertNull(Totp::verify($secret, '287082', 1, 59), 'a used step is refused');
        $this->assertSame(32, strlen(Totp::secret()));
    }

    public function test_the_console_stays_shut_until_two_factor_is_set_up(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('ops')->plainTextToken;

        $this->getJson('/api/admin/overview', $this->bearer($token))->assertForbidden()->assertJson(['two_factor' => 'setup']);
        $this->getJson('/api/admin/2fa', $this->bearer($token))->assertOk()->assertJson(['enabled' => false, 'passed' => false]);

        $this->enrol($admin, $token);

        $this->getJson('/api/admin/overview', $this->bearer($token))->assertOk();
        $this->assertNull($admin->fresh()->toArray()['two_factor_secret'] ?? null, 'the secret never leaves in JSON');
        $this->assertNotSame($admin->fresh()->two_factor_secret, $admin->fresh()->getRawOriginal('two_factor_secret'), 'stored encrypted');
    }

    public function test_a_new_sign_in_asks_for_a_fresh_code_and_refuses_a_used_one(): void
    {
        $admin = $this->admin();
        $secret = $this->enrol($admin, $admin->createToken('ops')->plainTextToken);
        $next = $admin->createToken('ops')->plainTextToken;
        $now = intdiv(time(), 30);

        $this->getJson('/api/admin/overview', $this->bearer($next))->assertForbidden()->assertJson(['two_factor' => 'verify']);
        $this->postJson('/api/admin/2fa/verify', ['code' => '000000'], $this->bearer($next))->assertStatus(422);
        // The code that switched 2FA on was used; the same step must not open a second session.
        $this->postJson('/api/admin/2fa/verify', ['code' => Totp::at($secret, $now)], $this->bearer($next))->assertStatus(422);
        $this->postJson('/api/admin/2fa/verify', ['code' => Totp::at($secret, $now + 1)], $this->bearer($next))->assertOk();

        $this->getJson('/api/admin/overview', $this->bearer($next))->assertOk();
    }

    public function test_a_recovery_code_works_once(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('ops')->plainTextToken;
        $secret = $this->postJson('/api/admin/2fa/setup', [], $this->bearer($token))->json('secret');
        $codes = $this->postJson('/api/admin/2fa/enable', ['code' => Totp::at($secret, intdiv(time(), 30))], $this->bearer($token))->json('recovery_codes');

        $first = $admin->createToken('ops')->plainTextToken;
        $this->postJson('/api/admin/2fa/verify', ['code' => strtoupper($codes[0])], $this->bearer($first))
            ->assertOk()->assertJson(['recovery_left' => 9]);
        $second = $admin->createToken('ops')->plainTextToken;
        $this->postJson('/api/admin/2fa/verify', ['code' => $codes[0]], $this->bearer($second))->assertStatus(422);
    }

    public function test_setup_cannot_replace_an_active_secret_and_customers_have_no_door(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('ops')->plainTextToken;
        $this->enrol($admin, $token);
        $this->postJson('/api/admin/2fa/setup', [], $this->bearer($token))->assertStatus(409);

        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        $this->postJson('/api/admin/2fa/setup', [], $this->bearer($customer->createToken('portal')->plainTextToken))->assertForbidden();
    }

    public function test_console_changes_are_audited_with_secrets_removed(): void
    {
        $admin = $this->admin();
        $token = $this->consoleToken($admin);

        $this->postJson('/api/admin/ads/kill', ['on' => true], $this->bearer($token))->assertOk();
        $this->getJson('/api/admin/overview', $this->bearer($token))->assertOk();

        $entries = AuditLog::all();
        $this->assertCount(1, $entries, 'reading is not logged');
        $this->assertSame('POST admin/ads/kill', $entries[0]->action);
        $this->assertSame('chef@example.com', $entries[0]->actor);
        $this->assertSame(200, $entries[0]->status);
        $this->assertTrue($entries[0]->data['on']);

        $this->assertSame(['api_key' => '[removed]', 'code' => '[removed]', 'name' => 'x', 'nested' => ['access_token' => '[removed]']],
            Audit::clean(['api_key' => 'k', 'code' => '123456', 'name' => 'x', 'nested' => ['access_token' => 't']]));

        $list = $this->getJson('/api/admin/audit', $this->bearer($token))->assertOk()->json('entries');
        $this->assertSame('POST admin/ads/kill', $list[0]['action']);
    }

    public function test_the_shell_reset_switches_two_factor_off_and_signs_the_admin_out(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('ops')->plainTextToken;
        $this->enrol($admin, $token);

        $this->artisan('factory:admin-2fa-reset', ['email' => 'chef@example.com'])->assertSuccessful();

        $this->assertNull($admin->fresh()->two_factor_enabled_at);
        $this->assertSame(0, $admin->tokens()->count());
        $this->assertTrue(AuditLog::where('action', '2fa.reset')->exists());
    }
}
