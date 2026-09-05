<?php

namespace Tests\Unit;

use App\Domain\Ai\PrototypeWriter;
use App\Domain\Design\DesignLibrary;
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
                && str_contains($text, 'Screen 1: map (note map)')
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
}
