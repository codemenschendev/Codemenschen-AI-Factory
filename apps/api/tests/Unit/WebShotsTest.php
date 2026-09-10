<?php

namespace Tests\Unit;

use App\Domain\Design\WebShots;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** The live homepages a site study looks at, photographed by the sidecar's Chromium. */
class WebShotsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't']);
        File::deleteDirectory(storage_path('app/web-shots'));
    }

    private function jpeg(): string
    {
        $bin = collect(['/usr/bin/magick', '/usr/bin/convert', '/opt/homebrew/bin/magick'])->first(fn (string $p) => is_executable($p));
        if ($bin === null) {
            $this->markTestSkipped('no imagemagick on this machine');
        }
        $out = storage_path('framework/testing/page-'.uniqid().'.jpg');
        (new Process([$bin, '-size', '1280x4000', 'plasma:tomato-steelblue', $out], null, null, null, 60))->run();
        $bytes = (string) file_get_contents($out);
        @unlink($out);

        return $bytes;
    }

    public function test_domains_become_homepages_and_the_top_of_each_page_comes_back(): void
    {
        $jpeg = base64_encode($this->jpeg());
        // One request per page, so three homepages load side by side instead of one after another.
        Http::fake(['sidecar.test/v1/pages/check' => function ($request) use ($jpeg) {
            $u = $request['urls'][0];

            return Http::response(['results' => [
                $u === 'https://figlmueller.at/'
                    ? ['url' => $u, 'screenshot_base64' => $jpeg, 'error' => null]
                    : ['url' => $u, 'screenshot_base64' => null, 'error' => 'timeout'],
            ]]);
        }]);

        $shots = app(WebShots::class)->forSites(['figlmueller.at', 'https://plachutta.at/menu', 'Café Sacher', ''], 3);

        // The name that is not a domain is dropped, the page that failed is dropped, the rest is a picture.
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r['urls'] === ['https://figlmueller.at/']);
        Http::assertSent(fn ($r) => $r['urls'] === ['https://plachutta.at/']);
        $this->assertCount(1, $shots);
        $this->assertSame('web:figlmueller.at', $shots[0]['id']);
        $this->assertSame('live homepage', $shots[0]['screen_type']);
        $this->assertSame('figlmueller.at', $shots[0]['app']);
        $this->assertStringStartsWith('data:image/webp;base64,', $shots[0]['data']);
        $this->assertLessThan(strlen($jpeg), strlen($shots[0]['data']), 'cropped to the first screens and downscaled');

        // Second time from disk: no trip to the sidecar.
        Http::fake();
        $again = app(WebShots::class)->forSites(['figlmueller.at'], 1);
        Http::assertNothingSent();
        $this->assertSame($shots[0]['data'], $again[0]['data']);
    }

    public function test_a_sidecar_that_is_down_means_no_pictures_not_a_failed_study(): void
    {
        Http::fake(['sidecar.test/*' => Http::response(null, 503)]);

        $this->assertSame([], app(WebShots::class)->forSites(['figlmueller.at']));
    }
}
