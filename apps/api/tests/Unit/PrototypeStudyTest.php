<?php

namespace Tests\Unit;

use App\Domain\Ai\PrototypeWriter;
use App\Domain\Design\DesignLibrary;
use App\Domain\Qa\PageAudit;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * An app build studies the trade's best apps before it draws, and the builder is handed what the
 * study wrote and what it looked at. Without a study it builds the way it always did.
 */
class PrototypeStudyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/pstudy-'.uniqid());
        File::makeDirectory($this->dir.'/images/app-screen/detail', 0755, true);
        $recs = [];
        foreach (['map', 'list_feed', 'form_input', 'success_confirmation'] as $i => $type) {
            $recs[] = ['id' => "r$i", 'medium' => 'app', 'file' => "images/app-screen/detail/r$i.webp", 'byte_size' => 10,
                'visual' => ['category' => 'app-screen', 'grade' => 'detail', 'width' => 1179, 'height' => 2556, 'orientation' => 'portrait'],
                'sources' => [], 'ai_index' => ['search_text' => "r$i"],
                'labels' => ['screen_type' => $type, 'industry' => 'transport_mobility', 'layout_patterns' => [],
                    'density' => 'medium', 'palette' => ['scheme' => 'dark', 'accent' => 'green'], 'notes' => "note $type"]];
            File::put($this->dir."/images/app-screen/detail/r$i.webp", "bytes-$i");
        }
        // One landing page too, so a site study has something to look at.
        File::makeDirectory($this->dir.'/images/web-screen/detail', 0755, true);
        $recs[] = ['id' => 'w0', 'medium' => 'web', 'file' => 'images/web-screen/detail/w0.webp', 'byte_size' => 10,
            'visual' => ['category' => 'web-screen', 'grade' => 'detail', 'width' => 1440, 'height' => 4000, 'orientation' => 'portrait'],
            'sources' => [], 'ai_index' => ['search_text' => 'w0'],
            'labels' => ['page_type' => 'landing', 'industry' => 'agency', 'hero_style' => 'centered_text', 'hero_visual' => 'none',
                'sections' => ['feature_grid'], 'density' => 'medium', 'palette' => ['scheme' => 'light', 'accent' => 'green'], 'nav_cta' => true, 'notes' => 'w0']];
        File::put($this->dir.'/images/web-screen/detail/w0.webp', 'bytes-w0');
        File::put($this->dir.'/catalog.json', json_encode(['images' => $recs]));
        config([
            'services.media.design_library_path' => $this->dir,
            'services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function sidecar(string ...$answers): void
    {
        $seq = Http::sequence();
        foreach ($answers as $a) {
            $seq->pushResponse(Http::response(['choices' => [['message' => ['content' => $a]]]]));
        }
        Http::fake([
            '*/v1/chat/completions' => $seq->whenEmpty(Http::response(null, 502)),
            'itunes.apple.com/*' => Http::response(['results' => []]),   // no store shots in this test
        ]);
    }

    public function test_the_builder_gets_the_brief_and_the_screens_it_was_written_from(): void
    {
        $this->sidecar(
            '{"industry":"transport_mobility","screens":["map","list_feed","form_input","success_confirmation"],"apps":["Grab","Be"],"country":"vn"}',
            'Every leading app opens on a map with the search in a sheet.',
            '<!doctype html><html><head><title>Xe</title></head><body class="app-page"><div class="app"></div></body></html>',
        );

        $out = app(PrototypeWriter::class)->build('App gọi xe ở Hà Nội', 'app', null, null, app(DesignLibrary::class));

        Http::assertSentCount(5);   // plan, two store searches (Grab, Be), study, build
        $this->assertSame('transport_mobility', $out['qa']['study']['industry']);
        $this->assertSame(['r0', 'r1', 'r2', 'r3'], $out['qa']['study']['references']);
        $this->assertStringContainsString('opens on a map', $out['qa']['study']['brief']);
        $this->assertArrayHasKey('study', $out['qa']['timing']);

        Http::assertSent(function ($request) {
            if (! str_contains((string) $request->url(), 'chat/completions')) {
                return false;
            }
            $messages = $request['messages'];
            $last = end($messages);
            if (! is_array($last['content'] ?? null)) {
                return false;
            }
            $text = implode("\n", array_column(array_filter($last['content'], fn ($c) => $c['type'] === 'text'), 'text'));
            if (! str_contains($text, 'Build a prototype for')) {
                return false;   // the plan or the study, not the build
            }
            $images = array_filter($last['content'], fn ($c) => $c['type'] === 'image_url');

            return str_contains($text, 'the brief wins')
                && str_contains($text, 'opens on a map')
                && str_contains($text, 'Image 1: map (note map)')
                && count($images) === 3;   // the builder sees three, the study saw them all
        });
    }

    public function test_without_a_plan_the_build_goes_on_the_old_way(): void
    {
        $this->sidecar(
            'no json here',
            '<!doctype html><html><head><title>Xe</title></head><body class="app-page"><div class="app"></div></body></html>',
        );

        $out = app(PrototypeWriter::class)->build('Eine Taxi-App für Wien', 'app', null, null, app(DesignLibrary::class));

        Http::assertSentCount(2);
        $this->assertArrayNotHasKey('study', $out['qa']);
        $this->assertSame('Xe', $out['title']);
    }

    public function test_a_competitor_the_study_looked_at_never_stays_in_the_page(): void
    {
        // The link ad of one build printed the three agencies the study had looked at as if
        // they were the customer's references. The builder is told, and the page is checked.
        $this->sidecar(
            '{"industry":"agency","sites":["wixx.at","webdorf.at"],"country":"at"}',
            'Brief: cream and green.',
            '<!doctype html><html><head><title>Agentur</title></head><body><p>Gebaut wie wixx.at und webdorf.at.</p></body></html>',
            '<!doctype html><html><head><title>Agentur</title></head><body><p>Gebaut von uns.</p></body></html>',
        );
        Http::fake(['sidecar.test/v1/pages/check' => Http::response(['results' => []])]);
        $audit = new class('/dev/null', null) extends PageAudit
        {
            public function run(string $html): array
            {
                return ['ok' => true, 'findings' => []];
            }
        };

        $out = app(PrototypeWriter::class)->build('Eine Website für eine Webagentur in Linz', 'site', null, $audit, new DesignLibrary($this->dir));

        $this->assertStringNotContainsString('wixx.at', $out['html']);
        $this->assertSame(['competitor-named: wixx.at'], $out['qa']['first_faults']);
        $this->assertTrue($out['qa']['repaired']);
        Http::assertSent(fn ($r) => is_array($r['messages'][1]['content'] ?? null)
            && str_contains(json_encode($r['messages'][1]['content']), 'COMPETITORS the designer looked at'));
        Http::assertSent(fn ($r) => count($r['messages'] ?? []) === 4 && str_contains((string) $r['messages'][3]['content'], 'competitor-named'));
    }

    public function test_a_site_whose_study_finds_no_pictures_still_builds_the_old_way(): void
    {
        // Shadowing the DesignRefs parameter with the library's references crashed exactly here:
        // no pictures, no brief, and the fallback called pick() on an array.
        File::put($this->dir.'/catalog.json', json_encode(['images' => []]));
        $this->sidecar(
            '{"industry":"agency","sites":[],"country":"at"}',
            '<!doctype html><html><head><title>Agentur</title></head><body><p>Ohne Studie.</p></body></html>',
        );

        $out = app(PrototypeWriter::class)->build('Eine Website für eine Webagentur', 'site', null, null, new DesignLibrary($this->dir));

        $this->assertStringContainsString('Ohne Studie', $out['html']);
        $this->assertNull($out['qa']['study']['brief'] ?? null);
    }
}
