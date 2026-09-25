<?php

namespace Tests\Feature;

use App\Domain\Ai\PrototypeWriter;
use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The owner's writer switch: Claude (default) or Codex writes the site, app and e-mail prototypes. */
class PrototypeWriterSwitchTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = '<!doctype html><html lang="de"><head><title>Bäckerei</title><style>.x{}</style></head><body><main><h1>Frisches Brot</h1></main></body></html>';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't',
            'services.ai_image.codex_url' => 'http://imagegen.test', 'services.ai_image.codex_token' => 'c']);
    }

    private function fake(int $codexStatus = 200): void
    {
        Http::fake([
            'imagegen.test/v1/chat/completions' => $codexStatus === 200
                ? Http::response(['choices' => [['message' => ['content' => self::PAGE]]]])
                : Http::response(['error' => 'usage limit'], $codexStatus),
            'sidecar.test/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => self::PAGE]]]]),
        ]);
    }

    public function test_claude_writes_by_default(): void
    {
        $this->fake();

        $out = app(PrototypeWriter::class)->build('Website für eine Bäckerei in Graz', 'site');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sidecar.test'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'imagegen.test'));
        $this->assertSame('claude', $out['qa']['writer']);
    }

    public function test_codex_writes_the_page_when_switched_on(): void
    {
        $this->fake();
        Setting::write('prototype.writer', 'codex');

        $out = app(PrototypeWriter::class)->build('Website für eine Bäckerei in Graz', 'site');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'imagegen.test/v1/chat/completions')
            && str_contains(json_encode($r['messages'], JSON_UNESCAPED_UNICODE), 'Bäckerei in Graz'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'sidecar.test'));
        $this->assertSame('codex', $out['qa']['writer']);
        $this->assertStringContainsString('Frisches Brot', $out['html']);
    }

    public function test_claude_takes_over_when_codex_fails(): void
    {
        $this->fake(429);
        Setting::write('prototype.writer', 'codex');

        $out = app(PrototypeWriter::class)->build('Website für eine Bäckerei in Graz', 'site');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sidecar.test'));
        $this->assertSame('claude', $out['qa']['writer']);
        $this->assertStringContainsString('http 429', $out['qa']['writer_fallback']);
    }

    public function test_a_page_codex_wrote_is_changed_by_codex(): void
    {
        $this->fake();

        app(PrototypeWriter::class)->revise(self::PAGE, 'site', ['writer' => 'codex'], 'Bäckerei', 'Die Überschrift soll "Brot aus Graz" heißen');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'imagegen.test/v1/chat/completions'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'sidecar.test'));
    }

    public function test_unknown_writer_is_claude_and_only_an_admin_switches(): void
    {
        Setting::write('prototype.writer', 'gpt');
        $this->assertSame('claude', PrototypeWriter::writer());

        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        $this->actingAs($customer, 'sanctum')->postJson('/api/admin/prototype-writer', ['writer' => 'codex'])->assertForbidden();

        $admin = Customer::create(['email' => 'chef@example.com', 'locale' => 'de', 'is_admin' => true]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/prototype-writer', ['writer' => 'codex'])
            ->assertOk()->assertJson(['prototype_writer' => 'codex']);
        $this->assertSame('codex', PrototypeWriter::writer());
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/prototype-writer', ['writer' => 'gpt'])->assertStatus(422);
    }
}
