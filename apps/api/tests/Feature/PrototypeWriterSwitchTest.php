<?php

namespace Tests\Feature;

use App\Domain\Ai\PrototypePhoto;
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
        $this->assertSame(1, preg_match_all('~<img class="mockup" src="data:image/(jpeg|png);base64,~', $out['html']));
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

    public function test_a_changed_picture_of_real_size_is_not_lost(): void
    {
        // A real mockup is a few MB of base64. The photo pass ran its slot patterns over it, PHP
        // gave up backtracking and the revised page was saved empty: a blank frame (2026-09-29).
        $big = base64_encode(random_bytes(1_000_000));
        Http::fake([
            'sidecar.test/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => self::BRIEF]]]]),
            'imagegen.test/v1/images' => Http::response(['base64' => $big, 'mime' => 'image/png']),
        ]);
        Setting::write('prototype.writer', 'codex');
        $first = app(PrototypeWriter::class)->build('Website für eine Bäckerei in Graz', 'site');

        $out = app(PrototypeWriter::class)->revise($first['html'], 'site', $first['qa'], 'Bäckerei', 'Mehr Blau bitte', app(PrototypePhoto::class));

        $this->assertStringContainsString('<img class="mockup" src="data:', $out['html']);
        $this->assertStringContainsString($big, $out['html']);
    }

    public function test_an_app_drawing_is_cut_into_its_screens(): void
    {
        // Four dark phones on a light background, at places the test knows to the pixel.
        $im = imagecreatetruecolor(1536, 1024);
        imagefill($im, 0, 0, imagecolorallocate($im, 238, 240, 244));
        $phones = [[60, 120, 300, 700], [420, 120, 300, 700], [780, 120, 300, 700], [1140, 120, 300, 700]];
        foreach ($phones as [$x, $y, $w, $h]) {
            imagefilledrectangle($im, $x, $y, $x + $w - 1, $y + $h - 1, imagecolorallocate($im, 20, 20, 30));
        }
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        // Claude's boxes are a little off, as a model's are; the pixels put them right.
        $claude = json_encode([['x' => 0.03, 'y' => 0.10, 'w' => 0.21, 'h' => 0.70], ['x' => 0.27, 'y' => 0.11, 'w' => 0.20, 'h' => 0.68],
            ['x' => 0.50, 'y' => 0.12, 'w' => 0.20, 'h' => 0.66], ['x' => 0.74, 'y' => 0.10, 'w' => 0.20, 'h' => 0.70]]);
        Http::fake([
            'sidecar.test/v1/chat/completions' => fn ($r) => Http::response(['choices' => [['message' => ['content' => str_contains((string) json_encode($r['messages']), 'image_url') ? $claude : self::BRIEF]]]]),
            'imagegen.test/v1/images' => Http::response(['base64' => base64_encode($png), 'mime' => 'image/png']),
        ]);
        Setting::write('prototype.writer', 'codex');

        $out = app(PrototypeWriter::class)->build('Eine App für Taxifahrten in Graz', 'app');

        $this->assertSame(1, preg_match('~data-screens="([^"]+)"~', $out['html'], $m));
        $this->assertEachPhoneAlone($phones, json_decode(html_entity_decode($m[1]), true));
        // The picture itself stays where a change request and the site build look for it.
        $this->assertSame(1, preg_match('~<img class="mockup" src="data:image/png;base64,~', $out['html']));
        $this->assertStringContainsString('<div class="screens"></div>', $out['html']);
    }

    public function test_phones_close_together_are_cut_in_the_gap_even_when_claude_overlaps_them(): void
    {
        // A real drawing (a taxi app, 2026-09-29): phones 16 px apart. Claude's boxes reach into
        // the neighbours; the cut still has to go through the gap.
        $im = imagecreatetruecolor(1536, 1024);
        imagefill($im, 0, 0, imagecolorallocate($im, 246, 246, 246));
        $phones = [[12, 90, 366, 820], [394, 90, 366, 820], [776, 90, 366, 820], [1158, 90, 366, 820]];
        foreach ($phones as [$x, $y, $w, $h]) {
            imagefilledrectangle($im, $x, $y, $x + $w - 1, $y + $h - 1, imagecolorallocate($im, 30, 30, 36));
        }
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        $claude = json_encode([['x' => 0.0, 'y' => 0.08, 'w' => 0.27, 'h' => 0.81], ['x' => 0.24, 'y' => 0.08, 'w' => 0.28, 'h' => 0.81],
            ['x' => 0.49, 'y' => 0.08, 'w' => 0.28, 'h' => 0.81], ['x' => 0.74, 'y' => 0.08, 'w' => 0.26, 'h' => 0.81]]);
        Http::fake(['sidecar.test/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => $claude]]]])]);

        $this->assertEachPhoneAlone($phones, app(\App\Domain\Ai\CodexPage::class)->screens($png));
    }

    public function test_a_soft_gradient_background_and_uneven_boxes_still_cut_in_the_gaps(): void
    {
        // A car rental app (2026-09-29): the background brightens towards the middle, and
        // Claude's boxes were uneven (one a fifth of the width, the next nearly a third).
        $im = imagecreatetruecolor(1536, 1024);
        for ($x = 0; $x < 1536; $x++) {
            $v = (int) round(228 + 24 * (1 - abs($x - 768) / 768));
            imageline($im, $x, 0, $x, 1023, imagecolorallocate($im, $v, $v, $v + 2));
        }
        $phones = [[14, 90, 362, 850], [396, 90, 360, 850], [778, 90, 360, 850], [1160, 90, 362, 850]];
        foreach ($phones as [$x, $y, $w, $h]) {
            imagefilledrectangle($im, $x, $y, $x + $w - 1, $y + $h - 1, imagecolorallocate($im, 25, 25, 30));
            imagefilledrectangle($im, $x + 12, $y + 12, $x + $w - 13, $y + $h - 13, imagecolorallocate($im, 250, 250, 252));
            imagefilledrectangle($im, $x + 40, $y + 200, $x + $w - 40, $y + 260, imagecolorallocate($im, 30, 90, 240));
        }
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        $claude = json_encode([['x' => 0.0, 'y' => 0.08, 'w' => 0.275, 'h' => 0.85], ['x' => 0.275, 'y' => 0.08, 'w' => 0.2, 'h' => 0.85],
            ['x' => 0.477, 'y' => 0.08, 'w' => 0.293, 'h' => 0.85], ['x' => 0.772, 'y' => 0.08, 'w' => 0.224, 'h' => 0.85]]);
        Http::fake(['sidecar.test/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => $claude]]]])]);

        $this->assertEachPhoneAlone($phones, app(\App\Domain\Ai\CodexPage::class)->screens($png));
    }

    /**
     * Every box holds its whole phone and none of the next one.
     *
     * @param  list<array{0:int,1:int,2:int,3:int}>  $phones
     * @param  list<array{0:int,1:int,2:int,3:int}>|null  $boxes
     */
    private function assertEachPhoneAlone(array $phones, ?array $boxes): void
    {
        $this->assertCount(count($phones), (array) $boxes);
        foreach ($phones as $i => [$x, $y, $w, $h]) {
            [$bx, $by, $bw, $bh] = $boxes[$i];
            $this->assertTrue($bx <= $x && $by <= $y && $bx + $bw >= $x + $w && $by + $bh >= $y + $h, "box $i misses part of its phone");
            if (isset($phones[$i + 1])) {
                $this->assertLessThanOrEqual($phones[$i + 1][0], $bx + $bw, "box $i reaches into the next phone");
            }
            if ($i > 0) {
                $this->assertGreaterThanOrEqual($phones[$i - 1][0] + $phones[$i - 1][2], $bx, "box $i reaches into the phone before");
            }
        }
    }

    public function test_a_site_drawing_is_not_cut(): void
    {
        $this->fake();
        Setting::write('prototype.writer', 'codex');

        $site = app(PrototypeWriter::class)->build('Website für eine Bäckerei in Graz', 'site');

        $this->assertStringNotContainsString('data-screens', $site['html']);
        Http::assertNotSent(fn ($r) => str_contains((string) json_encode($r->data()), 'image_url'));
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
