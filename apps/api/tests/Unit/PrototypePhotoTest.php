<?php

namespace Tests\Unit;

use App\Domain\Ai\PrototypePhoto;
use App\Domain\Ai\StockPhotos;
use App\Domain\Library\ImageLibrary;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The one photograph an app prototype puts in its picture band.
 *
 * It is always free: the shared library, then Pexels, then nothing. Prototypes are given away by
 * the hundred, so what is tested hardest is that they never buy anything and that every way this
 * can fail leaves the page standing.
 */
class PrototypePhotoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/photo-'.uniqid());
        File::makeDirectory($this->dir.'/img', 0755, true);
        config(['services.media.library_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function magick(): ?string
    {
        return collect(['/usr/bin/magick', '/usr/bin/convert', '/opt/homebrew/bin/magick'])
            ->first(fn (string $p) => is_executable($p));
    }

    /**
     * A photograph-sized PNG, drawn by the same imagemagick the code re-encodes with.
     *
     * Size matters: the encoder only shrinks, and it refuses a file under a kilobyte because that
     * is what a failed conversion leaves behind. A tiny fixture would trip that guard.
     */
    private function png(): string
    {
        $bin = $this->magick();
        if ($bin === null) {
            $this->markTestSkipped('no imagemagick on this machine');
        }

        $out = $this->dir.'/fixture.png';
        (new Process([$bin, '-size', '1536x1024', 'plasma:tomato-steelblue', $out], null, null, null, 60))->run();

        return (string) file_get_contents($out);
    }

    private function page(string $band = '<div class="app-art">Lena am Waschbecken, warmes Licht</div>'): string
    {
        return '<!doctype html><html><body class="t-rose app-page"><div class="app">'
            .'<section class="screen">'.$band.'</section></div></body></html>';
    }

    /** A source with no key: present, as the container always provides it, and with nothing to give. */
    private function noStock(): StockPhotos
    {
        config(['services.stock.pexels_key' => '']);

        return new StockPhotos;
    }

    /** A source that answers with this PNG and this photographer. */
    private function stock(string $png, string $credit = 'Anna Fotografin'): StockPhotos
    {
        return new class($png, $credit) extends StockPhotos
        {
            /** @var list<?string> the search phrase handed over per call, null when there was none */
            public array $searches = [];

            public function __construct(private string $png, private string $credit) {}

            public function configured(): bool
            {
                return true;
            }

            public function findMany(array $wants): array
            {
                $out = [];
                foreach ($wants as $i => $w) {
                    $this->searches[] = $w['search'] ?? null;
                    $out[$i] = ['bytes' => $this->png, 'credit' => $this->credit, 'url' => 'https://www.pexels.com/photo/1'];
                }

                return $out;
            }
        };
    }

    private function photo(?StockPhotos $stock = null): PrototypePhoto
    {
        return new PrototypePhoto(new ImageLibrary($this->dir), $stock ?? $this->noStock());
    }

    public function test_the_business_s_own_picture_comes_first_and_stays_out_of_the_library(): void
    {
        $png = $this->png();
        $fetched = [];
        $page = '<!doctype html><html><head><style>.x{}</style></head><body>'
            .'<span class="site-logo">WP Gift Card</span>'
            .'<div class="photo-wide" data-site="2" data-q="gift voucher">Der echte Weihnachtsgutschein</div>'
            .'<div class="photo-card" data-q="laptop desk">Laptop am Schreibtisch</div></body></html>';
        $site = [
            'images' => [['url' => 'https://wp-giftcard.com/a.png', 'alt' => 'a', 'kind' => 'photo'], ['url' => 'https://wp-giftcard.com/voucher.png', 'alt' => 'voucher', 'kind' => 'photo']],
            'logo' => 'https://wp-giftcard.com/logo.svg',
            'fetch' => function (string $url) use (&$fetched, $png): ?string {
                $fetched[] = $url;

                return str_ends_with($url, '.svg') ? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"></svg>' : $png;
            },
        ];

        $out = $this->photo($this->stock($png))->apply($page, $site);

        $this->assertSame(['site', 'stock'], $out['sources']);
        $this->assertContains('https://wp-giftcard.com/voucher.png', $fetched);
        $this->assertStringContainsString('<span class="site-logo"><img src="data:image/svg+xml;base64,', $out['html']);
        $this->assertStringContainsString('alt="WP Gift Card"', $out['html']);
        // Only the stock photograph is filed for other prototypes; the business's own is not.
        $this->assertCount(1, glob($this->dir.'/img/*') ?: []);
    }

    public function test_a_screenshot_is_framed_whole_and_a_logo_in_a_sentence_stays_text(): void
    {
        $png = $this->png();
        $page = '<!doctype html><html><head><style>.x{}</style></head><body>'
            .'<p>Ads for <span class="site-logo">Gift Card</span> (wp-giftcard.com)</p>'
            .'<div class="head"><span class="site-logo">Gift Card</span><small>Sponsored</small></div>'
            .'<div class="photo-card" data-site="1" data-q="plugin panel">Das AI-Panel</div>'
            .'<div class="photo-wide" data-q="fence">Zaun</div></body></html>';
        $site = [
            'images' => [['url' => 'https://g.com/ai-panel.jpg', 'alt' => '', 'kind' => 'screen'], ['url' => 'https://g.com/xmas.jpg', 'alt' => '', 'kind' => 'photo']],
            'logo' => 'https://g.com/logo.svg',
            'fill' => true,
            'fetch' => fn (string $url) => str_ends_with($url, '.svg') ? '<svg xmlns="http://www.w3.org/2000/svg"></svg>' : $png,
        ];

        $out = $this->photo($this->stock($png))->apply($page, $site);

        $this->assertStringContainsString('class="has-photo is-screen photo-card"', $out['html']);
        $this->assertStringContainsString('.has-photo.is-screen>img{', $out['html']);
        // The unmarked slot took the spare Christmas photograph, not a stock fence.
        $this->assertSame(['site', 'site'], $out['sources']);
        $this->assertStringContainsString('<p>Ads for Gift Card (wp-giftcard.com)</p>', $out['html']);
        $this->assertSame(1, substr_count($out['html'], 'data:image/svg+xml'));
    }

    public function test_a_picture_the_site_cannot_give_falls_back_to_stock(): void
    {
        $page = '<!doctype html><html><body><div class="photo-wide" data-site="1" data-q="bakery">Brot im Korb</div></body></html>';
        $site = ['images' => [['url' => 'https://b.at/1.jpg', 'alt' => '', 'kind' => 'photo']], 'fetch' => fn () => null];

        $out = $this->photo($this->stock($this->png()))->apply($page, $site);

        $this->assertSame(['stock'], $out['sources']);
    }

    public function test_the_band_becomes_the_photograph(): void
    {
        $out = $this->photo($this->stock($this->png()))->apply($this->page());

        $this->assertStringContainsString('data:image/webp;base64,', $out['html']);
        $this->assertStringContainsString('class="has-photo app-art"', $out['html']);
        $this->assertStringContainsString('alt="Lena am Waschbecken, warmes Licht"', $out['html']);
        // The words are gone from the band: the screen around it already carries them.
        $this->assertStringNotContainsString('>Lena am Waschbecken', $out['html']);
        $this->assertSame('stock', $out['source']);
        $this->assertSame('Anna Fotografin', $out['credit']);
    }

    public function test_the_photograph_is_made_to_fill_the_shape_the_model_drew(): void
    {
        $withStyle = '<!doctype html><html><head><style>.photo-card{aspect-ratio:1200/628}</style></head><body>'
            .'<div class="photo-card" data-q="laptop code">Laptop mit Code</div></body></html>';

        $out = $this->photo($this->stock($this->png()))->apply($withStyle);

        // Last in the stylesheet, so it beats the model's own rule; without it a 4:3 photo turned
        // a 1.91:1 link ad into a 358x318 box.
        $this->assertMatchesRegularExpression('~\.has-photo>img\{display:block;width:100%;height:100%;object-fit:cover\}</style>~', $out['html']);

        $noStyle = $this->photo($this->stock($this->png()))->apply(str_replace('<style>.photo-card{aspect-ratio:1200/628}</style>', '', $withStyle));
        $this->assertStringContainsString('object-fit:cover}</style></head>', $noStyle['html']);
    }

    public function test_a_slot_the_model_named_itself_is_still_a_slot(): void
    {
        // The Linz bakery's hero: class="photo-hero", a search phrase, a brief, and no picture,
        // because only the three names were looked for. Anything "photo-…" with a data-q is one.
        $stock = $this->stock($this->png());
        $out = $this->photo($stock)->apply($this->page(
            '<header class="hero"><div class="photo-hero" data-q="fresh bread rolls basket steam">Korb voller warmer Weckerl</div><h1>Frisch</h1></header>'
            .'<div class="photo-grid"><div class="photo-card" data-q="bakery counter">Theke</div></div>'
        ));

        $this->assertStringContainsString('class="has-photo photo-hero"', $out['html']);
        $this->assertStringNotContainsString('Korb voller warmer Weckerl</div>', $out['html']);
        $this->assertStringContainsString('alt="Korb voller warmer Weckerl"', $out['html']);
        // The grid around the card is a layout, not a picture: no data-q, no slot.
        $this->assertStringContainsString('<div class="photo-grid">', $out['html']);
        $this->assertCount(2, $out['photos']);
        $this->assertEqualsCanonicalizing(['fresh bread rolls basket steam', 'bakery counter'], $stock->searches);
    }

    public function test_a_wide_band_nobody_could_fill_keeps_its_caption(): void
    {
        // The band is a deliberate design and wide enough to carry a line of text. A prototype
        // never buys its way out of an empty one.
        $page = $this->page();

        $out = $this->photo()->apply($page);

        $this->assertSame($page, $out['html']);
        $this->assertNull($out['photo']);
        $this->assertNull($out['source']);
    }

    public function test_the_slot_is_found_among_the_model_s_own_classes(): void
    {
        // A free page styles every slot, so the class attribute is never just the slot name.
        // Requiring an exact match meant almost none of them were ever processed.
        $out = $this->photo($this->stock($this->png()))
            ->apply($this->page('<span class="photo-thumb avatar" id="x">Lena am Waschbecken</span>'));

        $this->assertCount(1, $out['photos']);
        $this->assertStringContainsString('class="has-photo photo-thumb avatar"', $out['html']);
        $this->assertStringContainsString('id="x"', $out['html'], 'the model keeps its own attributes');
    }

    public function test_every_slot_in_the_app_gets_a_picture(): void
    {
        // Two thirds of real app screens carry a picture and a fifth carry one in more than one
        // place. A list of dishes is photographs with prices beside them, not icons in squares.
        $page = $this->page(
            '<div class="app-art">Die Backstube am Morgen</div>'
            .'<div class="app-line"><i class="app-thumb">Kaisersemmel</i><div><b>Semmel</b></div></div>'
            .'<div class="app-card"><div class="app-cover">Mohnzopf auf dem Tresen</div><b>Zopf</b></div>'
        );

        $out = $this->photo($this->stock($this->png()))->apply($page);

        $this->assertCount(3, $out['photos']);
        $this->assertStringContainsString('class="has-photo app-art"', $out['html']);
        $this->assertStringContainsString('class="has-photo app-thumb"', $out['html']);
        $this->assertStringContainsString('class="has-photo app-cover"', $out['html']);
        // The tag is preserved, so an <i> in a row stays an <i> and the layout does not shift.
        $this->assertStringContainsString('<i class="has-photo app-thumb">', $out['html']);
    }

    public function test_a_seventh_slot_keeps_its_gradient(): void
    {
        // Every one of these is bytes in a page served on every view, so the ceiling stays low.
        $bands = '';
        foreach (range(1, 8) as $n) {
            $bands .= '<div class="app-art">Aufnahme Nummer '.$n.'</div>';
        }

        $out = $this->photo($this->stock($this->png()))->apply($this->page($bands));

        $this->assertCount(6, $out['photos']);
        $this->assertStringContainsString('<div class="app-art">Aufnahme Nummer 7</div>', $out['html']);
    }

    public function test_a_thumbnail_is_encoded_smaller_than_a_band(): void
    {
        // A 58px stamp encoded at the band's width is ten times the bytes for the same pixels.
        $png = $this->png();
        $band = $this->photo($this->stock($png))->apply($this->page());
        $thumb = $this->photo($this->stock($png))->apply(
            $this->page('<i class="app-thumb">Kaisersemmel im Korb</i>')
        );

        $sizeOf = fn (string $html) => strlen(explode('"', explode('src="', $html)[1])[0]);

        $this->assertLessThan($sizeOf($band['html']) / 2, $sizeOf($thumb['html']));
    }

    public function test_a_slot_wrapped_around_a_whole_card_is_left_alone(): void
    {
        // A ride-hailing app put the class on the card instead of on a picture inside it, and
        // flattening that gave a "brief" like "Praterstern to Hauptbahnhof, yesterday, 9,40 euro".
        // No stock library has that, and emptying it would delete the card.
        $card = '<div class="photo-card"><b>Praterstern</b><span>Gestern, 9,40 Euro</span></div>';
        $sent = [];
        $stock = $this->stock($this->png());

        $out = $this->photo($stock)->apply($this->page($card));

        $this->assertStringContainsString('<b>Praterstern</b>', $out['html']);
        $this->assertSame([], $out['photos']);
    }

    public function test_a_slot_nobody_could_fill_keeps_its_shape_and_loses_its_words(): void
    {
        // Six words of direction for a photographer, crammed into a 58px square, read as a bug.
        $page = $this->page('<span class="photo-thumb avatar">Porträt eines lächelnden Mannes</span>');

        $out = $this->photo()->apply($page);

        $this->assertStringContainsString('class="photo-thumb avatar"></span>', $out['html']);
        $this->assertStringNotContainsString('Porträt eines', $out['html']);
    }

    public function test_thumbnails_past_the_ceiling_lose_their_words_too(): void
    {
        // A ride-hailing page wrote eleven driver portraits. Six got a photograph and the other
        // five were left saying "Porträt eines Fahrers mit Kappe" in a circle the size of a coin.
        $thumbs = '';
        foreach (range(1, 8) as $n) {
            $thumbs .= '<i class="photo-thumb pt-'.$n.'">Porträt Nummer '.$n.'</i>';
        }

        $out = $this->photo($this->stock($this->png()))->apply($this->page($thumbs));

        $this->assertCount(6, $out['photos']);
        // The words survive only as alt text on the six that became photographs.
        $this->assertStringNotContainsString('>Porträt Nummer', $out['html']);
        $this->assertStringContainsString('<i class="photo-thumb pt-7"></i>', $out['html']);
    }

    public function test_the_search_phrase_on_the_slot_reaches_the_stock_source(): void
    {
        // "Tischlerin steht abends allein in ihrer" found a woman in a dark alley. The model
        // now writes the nouns a stock library is filed under, and the sentence stays for the alt.
        $stock = $this->stock($this->png());
        $out = $this->photo($stock)->apply($this->page(
            '<div class="photo-wide" data-q="carpenter workshop phone">Tischlerin steht abends allein in ihrer Werkstatt</div>'
            .'<div class="photo-card">Adventkranz auf dem Tresen</div>'
        ));

        $this->assertCount(2, $out['photos']);
        $this->assertSame(['carpenter workshop phone', null], $stock->searches);
        $this->assertStringContainsString('alt="Tischlerin steht abends allein in ihrer Werkstatt"', $out['html']);
        $this->assertStringContainsString('data-q="carpenter workshop phone"', $out['html'], 'the attribute survives');
    }

    public function test_a_brief_in_one_wrapper_is_still_the_brief(): void
    {
        // An ad page styled every brief as <span class="brief"> inside the slot and, because a
        // slot with an element in it was read as a wrapped card, shipped with no picture at all.
        $out = $this->photo($this->stock($this->png()))->apply($this->page(
            '<div class="photo-wide" data-q="advent wreath"><span class="brief">Adventkranz am Tresen</span></div>'
            .'<i class="photo-thumb"><span class="brief">Porträt der Wirtin</span></i>'
        ));

        $this->assertCount(2, $out['photos']);
        $this->assertStringContainsString('alt="Adventkranz am Tresen"', $out['html']);
        $this->assertStringNotContainsString('class="brief"', $out['html']);
    }

    public function test_a_wrapped_thumbnail_nobody_filled_is_emptied_too(): void
    {
        $out = $this->photo()->apply($this->page('<i class="photo-thumb"><span>Porträt der Wirtin</span></i>'));

        $this->assertStringContainsString('<i class="photo-thumb"></i>', $out['html']);
    }

    public function test_a_page_without_a_band_asks_for_nothing(): void
    {
        $out = $this->photo($this->stock($this->png()))->apply('<html><body><p>kein Bild</p></body></html>');

        $this->assertNull($out['photo']);
        $this->assertNull($out['source']);
    }

    public function test_the_photo_is_filed_so_the_next_prototype_reuses_it(): void
    {
        $png = $this->png();
        $lib = new ImageLibrary($this->dir);

        (new PrototypePhoto($lib, $this->stock($png)))->apply($this->page());

        $rows = $lib->all();
        $this->assertCount(1, $rows);
        $this->assertSame('prototype', $rows[0]['project']);
        $this->assertSame('Lena am Waschbecken, warmes Licht', $rows[0]['caption']);
    }

    public function test_the_library_answers_before_the_network_does(): void
    {
        $png = $this->png();
        $lib = new ImageLibrary($this->dir);
        (new PrototypePhoto($lib, $this->stock($png)))->apply($this->page());

        // Second time round, with a source that would fail if it were asked at all.
        $out = (new PrototypePhoto($lib, $this->stock($png, 'Somebody Else')))->apply($this->page());

        $this->assertSame('library', $out['source']);
        $this->assertNull($out['credit'], 'a reused photo carries no fresh credit');
    }

    public function test_the_container_wires_the_free_source_in(): void
    {
        // It did not, for a while: a nullable parameter with a default of null is left at its
        // default by the container, so every prototype quietly paid for a picture Pexels had.
        $png = $this->png();
        config(['services.stock.pexels_key' => 'k']);
        Http::fake([
            'api.pexels.com/*' => Http::response(['photos' => [[
                'photographer' => 'Anna', 'url' => 'https://www.pexels.com/photo/1',
                'src' => ['large' => 'https://images.pexels.com/1.jpg'],
            ]]]),
            'images.pexels.com/*' => Http::response($png),
        ]);

        $out = app(PrototypePhoto::class)->apply($this->page());

        $this->assertSame('Anna', $out['credit'], 'the free source was used, so it was injected');
    }
}
