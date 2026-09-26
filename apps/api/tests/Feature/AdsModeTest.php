<?php

namespace Tests\Feature;

use App\Domain\Ai\PrototypeWriter;
use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The owner's three ad modes: hybrid (default), Claude alone, Codex alone. */
class AdsModeTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = '<!doctype html><html lang="de"><head><title>Anzeigen</title><style>.x{}</style></head><body><main class="ads">'
        .'<article class="ad ad-story"><div class="photo-wide" data-q="bread oven">Brot im Ofen</div></article></main></body></html>';

    private const BRIEF = 'A warm Christmas feed ad for a bakery in Graz. Headline "Frisch zu Weihnachten".';

    protected function setUp(): void
    {
        parent::setUp();
        $im = imagecreatetruecolor(40, 40);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't',
            'services.ai_image.backend' => 'codex', 'services.ai_image.prototype_renders' => 2,
            'services.ai_image.codex_url' => 'http://imagegen.test', 'services.ai_image.codex_token' => 'c']);
        Http::fake([
            // The art director's brief for a Codex ad; anything else is Claude's page.
            '*/v1/chat/completions' => fn ($r) => Http::response(['choices' => [['message' => ['content' => str_contains((string) json_encode($r['messages']), 'draw ONE finished ad') ? self::BRIEF : self::PAGE]]]]),
            'imagegen.test/v1/images' => Http::response(['base64' => base64_encode($png), 'mime' => 'image/png']),
        ]);
    }

    public function test_codex_alone_draws_the_whole_creative_from_claudes_brief(): void
    {
        Setting::write('ads.mode', 'codex');

        $out = app(PrototypeWriter::class)->build('Weihnachtsanzeigen für eine Bäckerei in Graz', 'ads');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'chat/completions')
            && str_contains($r['messages'][0]['content'], 'art director') && str_contains($r['messages'][0]['content'], '1080x1080')
            && str_contains($r['messages'][1]['content'], 'Weihnachtsanzeigen für eine Bäckerei in Graz')
            && str_contains($r['messages'][1]['content'], 'LANGUAGE:'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'imagegen.test') && $r['creative'] === true
            && $r['size'] === '1080x1080' && str_starts_with($r['prompt'], self::BRIEF) && str_contains($r['prompt'], 'LANGUAGE:'));
        Http::assertSentCount(2);
        $this->assertSame(self::BRIEF, $out['qa']['brief']);
        $this->assertSame('codex', $out['qa']['mode']);
        $this->assertSame(1, substr_count($out['html'], 'src="data:image/'));
        $this->assertArrayHasKey('render', $out['qa']['timing']);
    }

    public function test_a_render_is_cropped_to_the_platforms_exact_size(): void
    {
        if (! collect(['/usr/bin/magick', '/opt/homebrew/bin/magick'])->contains(fn (string $p) => is_executable($p))) {
            $this->markTestSkipped('no imagemagick on this machine');
        }
        $im = imagecreatetruecolor(1536, 1024);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();

        $out = PrototypeWriter::toSize($png, '1200x628');

        $this->assertSame([1200, 628], array_slice(getimagesizefromstring($out), 0, 2));
    }

    public function test_a_change_to_a_codex_ad_renders_it_again_from_the_current_picture(): void
    {
        Setting::write('ads.mode', 'codex');
        $out = app(PrototypeWriter::class)->build('Weihnachtsanzeigen für eine Bäckerei in Graz', 'ads');

        app(PrototypeWriter::class)->revise($out['html'], 'ads', $out['qa'], 'Weihnachtsanzeigen für eine Bäckerei in Graz', 'Den Button in Rot');
        $this->assertSame(['square' => '1080x1080'], PrototypeWriter::adFormat('Weihnachtsanzeigen für eine Bäckerei'));
        $this->assertSame(['banner' => '1200x628'], PrototypeWriter::adFormat('wp-giftcard.com làm cho tôi 1 ảnh banner Ads'));
        $this->assertSame(['story' => '1080x1920'], PrototypeWriter::adFormat('Instagram Story für ein Café'));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'imagegen.test') && str_contains($r['prompt'], 'change only this, as the customer asked: Den Button in Rot')
            && count($r['refs']) === 1 && $r['size'] === '1080x1080');
    }

    public function test_the_ad_speaks_the_websites_language_unless_the_request_names_one(): void
    {
        $rule = PrototypeWriter::languageRule(['lang' => 'en', 'text' => 'Sell gift cards on your WordPress site']);
        $this->assertStringContainsString('every word in the ad is in English (the language of the website)', $rule);
        $this->assertStringContainsString('the language the request itself is written in does not count', $rule);
        $this->assertStringContainsString('Sell gift cards on your WordPress site', $rule);

        $this->assertStringContainsString('the language the request is written in', PrototypeWriter::languageRule(null));
    }

    public function test_claude_alone_renders_nothing(): void
    {
        Setting::write('ads.mode', 'claude');

        app(PrototypeWriter::class)->build('Weihnachtsanzeigen für eine Bäckerei in Graz', 'ads');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'chat/completions'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'imagegen.test'));
    }

    public function test_hybrid_is_the_default_and_an_unknown_mode_falls_back_to_it(): void
    {
        $this->assertSame('hybrid', PrototypeWriter::adsMode());
        Setting::write('ads.mode', 'gpt');
        $this->assertSame('hybrid', PrototypeWriter::adsMode());
    }

    public function test_only_an_admin_switches_the_mode(): void
    {
        $customer = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        $this->actingAs($customer, 'sanctum')->postJson('/api/admin/ads-mode', ['mode' => 'codex'])->assertForbidden();

        $admin = Customer::create(['email' => 'chef@example.com', 'locale' => 'de', 'is_admin' => true]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/ads-mode', ['mode' => 'codex'])->assertOk()->assertJson(['ads_mode' => 'codex']);
        $this->assertSame('codex', PrototypeWriter::adsMode());
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/ads-mode', ['mode' => 'gpt'])->assertStatus(422);
    }
}
