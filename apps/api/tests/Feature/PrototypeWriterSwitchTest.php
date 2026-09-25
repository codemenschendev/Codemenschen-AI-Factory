<?php

namespace Tests\Feature;

use App\Domain\Ai\PrototypeWriter;
use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The owner's writer switch: Claude (default) writes the site, app and e-mail prototypes as pages;
 * with Codex, Claude writes a design brief and Codex draws the whole prototype as one picture.
 */
class PrototypeWriterSwitchTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = '<!doctype html><html lang="de"><head><title>Bäckerei</title><style>.x{}</style></head><body><main><h1>Frisches Brot</h1></main></body></html>';

    private const BRIEF = 'A warm bakery homepage in Graz. Palette #3b2a1a, #f4ead8. Headline "Frisches Brot aus Graz".';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't',
            'services.ai_image.codex_url' => 'http://imagegen.test', 'services.ai_image.codex_token' => 'c']);
    }

    private function fake(int $codexStatus = 200): void
    {
        $im = imagecreatetruecolor(40, 60);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        Http::fake([
            // The brief is asked for with the mockup prompt; anything else is the Claude page.
            'sidecar.test/v1/chat/completions' => fn ($r) => Http::response(['choices' => [['message' => ['content' => str_contains((string) json_encode($r['messages']), 'art director') ? self::BRIEF : self::PAGE]]]]),
            'imagegen.test/v1/images' => $codexStatus === 200
                ? Http::response(['base64' => base64_encode($png), 'mime' => 'image/png'])
                : Http::response(['error' => 'usage limit'], $codexStatus),
        ]);
    }

    public function test_claude_writes_by_default(): void
    {
        $this->fake();

        $out = app(PrototypeWriter::class)->build('Website für eine Bäckerei in Graz', 'site');

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'imagegen.test'));
        $this->assertSame('claude', $out['qa']['writer']);
        $this->assertStringContainsString('Frisches Brot', $out['html']);
    }

    public function test_claude_briefs_and_codex_draws_the_prototype_as_one_picture(): void
    {
        $this->fake();
        Setting::write('prototype.writer', 'codex');

        $out = app(PrototypeWriter::class)->build('Website für eine Bäckerei in Graz', 'site');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sidecar.test')
            && str_contains($r['messages'][1]['content'], 'Website für eine Bäckerei in Graz'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'imagegen.test/v1/images')
            && $r['prompt'] === self::BRIEF && $r['mockup'] === 'site' && $r['size'] === '1024x1536');
        $this->assertSame('codex', $out['qa']['writer']);
        $this->assertSame(self::BRIEF, $out['qa']['brief']);
        $this->assertSame(1, substr_count($out['html'], '<img class="mockup" src="data:image/jpeg;base64,'));
    }

    public function test_claude_takes_over_when_codex_fails(): void
    {
        $this->fake(429);
        Setting::write('prototype.writer', 'codex');

        $out = app(PrototypeWriter::class)->build('Website für eine Bäckerei in Graz', 'site');

        $this->assertSame('claude', $out['qa']['writer']);
        $this->assertStringContainsString('codex failed', $out['qa']['writer_fallback']);
        $this->assertStringContainsString('Frisches Brot', $out['html']);
    }

    public function test_a_change_sends_the_current_picture_back_to_codex(): void
    {
        $this->fake();
        Setting::write('prototype.writer', 'codex');
        $first = app(PrototypeWriter::class)->build('Website für eine Bäckerei in Graz', 'site');

        app(PrototypeWriter::class)->revise($first['html'], 'site', $first['qa'], 'Bäckerei', 'Die Überschrift soll "Brot aus Graz" heißen');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'imagegen.test/v1/images') && count($r['refs']) === 1
            && str_contains($r['prompt'], 'Brot aus Graz') && $r['mockup'] === 'site');
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
