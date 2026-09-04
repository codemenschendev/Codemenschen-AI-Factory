<?php

namespace Tests\Unit;

use App\Domain\Ai\ImageService;
use App\Domain\Ai\PrototypePhoto;
use App\Domain\Library\ImageLibrary;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The one photograph an app prototype is allowed to buy.
 *
 * The line the model wrote in the picture band is the photo brief, so what matters here is that
 * the right words are sent, that the picture goes back where the words were, that a second band
 * never costs a second call, and above all that every way this can fail leaves the page standing.
 */
class PrototypePhotoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/photo-'.uniqid());
        File::makeDirectory($this->dir.'/img', 0755, true);
        config(['services.media.library_path' => $this->dir, 'services.ai_image.quality' => 'medium']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /**
     * A photograph-sized PNG, drawn by the same imagemagick the code re-encodes with.
     *
     * Size matters: the encoder only shrinks, and it refuses a file under a kilobyte because that
     * is what a failed conversion leaves behind. An 8x8 fixture would trip that guard and the test
     * would skip while claiming imagemagick was missing.
     */
    private function png(): ?string
    {
        $bin = collect(['/usr/bin/magick', '/usr/bin/convert', '/opt/homebrew/bin/magick'])
            ->first(fn (string $p) => is_executable($p));
        if ($bin === null) {
            return null;
        }

        $out = $this->dir.'/fixture.png';
        (new Process([
            $bin, '-size', '1536x1024', 'plasma:tomato-steelblue', $out,
        ], null, null, null, 60))->run();

        return is_file($out) ? (string) file_get_contents($out) : null;
    }

    private function photo(ImageService $images): PrototypePhoto
    {
        return new PrototypePhoto($images, new ImageLibrary($this->dir));
    }

    private function page(string $band = '<div class="app-art">Lena am Waschbecken, warmes Licht</div>'): string
    {
        return '<!doctype html><html><body class="t-rose app-page"><div class="app">'
            .'<section class="screen">'.$band.'</section></div></body></html>';
    }

    /** @param list<string> $captured filled with the prompts the service was asked for */
    private function service(array &$captured, ?string $throws = null): ImageService
    {
        $png = $this->png();
        if ($png === null) {
            $this->markTestSkipped('no imagemagick on this machine');
        }

        return new class($captured, $throws, $png) extends ImageService
        {
            public function __construct(private array &$captured, private ?string $throws, private string $png) {}

            public function generate(string $prompt, string $size): string
            {
                $this->captured[] = $prompt.' @ '.$size;
                if ($this->throws !== null) {
                    throw new RuntimeException($this->throws);
                }

                return $this->png;
            }
        };
    }

    public function test_the_band_becomes_the_photograph(): void
    {
        $sent = [];
        $out = $this->photo($this->service($sent))->apply($this->page());

        $this->assertStringContainsString('data:image/webp;base64,', $out['html']);
        $this->assertSame('Lena am Waschbecken, warmes Licht', $out['photo']);
        $this->assertStringContainsString('class="app-art has-photo"', $out['html']);
        $this->assertStringContainsString('alt="Lena am Waschbecken, warmes Licht"', $out['html']);
        // The words are gone from the band: the screen around it already carries them.
        $this->assertStringNotContainsString('>Lena am Waschbecken', $out['html']);
    }

    public function test_the_model_s_own_line_is_what_gets_photographed(): void
    {
        $sent = [];
        $this->photo($this->service($sent))->apply($this->page());

        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Lena am Waschbecken, warmes Licht', $sent[0]);
        // No lettering: a generated sign in the wrong language is what makes a mockup look fake.
        $this->assertStringContainsString('no text', $sent[0]);
        $this->assertStringContainsString('1536x1024', $sent[0]);
    }

    public function test_a_second_band_never_costs_a_second_call(): void
    {
        $sent = [];
        $page = $this->page(
            '<div class="app-art">Erste Aufnahme</div><div class="app-art">Zweite Aufnahme</div>'
        );

        $out = $this->photo($this->service($sent))->apply($page);

        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Erste Aufnahme', $sent[0]);
        // The second band keeps its gradient rather than buying a picture nobody asked for.
        $this->assertStringContainsString('<div class="app-art">Zweite Aufnahme</div>', $out['html']);
    }

    public function test_a_page_without_a_band_buys_nothing(): void
    {
        $sent = [];
        $out = $this->photo($this->service($sent))->apply('<html><body><p>kein Bild</p></body></html>');

        $this->assertSame([], $sent);
        $this->assertNull($out['photo']);
    }

    public function test_a_failed_generation_leaves_the_page_exactly_as_it_was(): void
    {
        $sent = [];
        $page = $this->page();

        $out = $this->photo($this->service($sent, 'sidecar down'))->apply($page);

        $this->assertSame($page, $out['html']);
        $this->assertNull($out['photo']);
    }

    public function test_the_photo_is_filed_so_the_next_prototype_reuses_it(): void
    {
        $sent = [];
        $lib = new ImageLibrary($this->dir);
        (new PrototypePhoto($this->service($sent), $lib))->apply($this->page());

        $rows = $lib->all();
        $this->assertCount(1, $rows);
        $this->assertSame('prototype', $rows[0]['project']);
        $this->assertSame('Lena am Waschbecken, warmes Licht', $rows[0]['caption']);
    }
}
