<?php

namespace Tests\Unit;

use App\Domain\Ai\ProductPage;
use App\Domain\Ai\PrototypeWriter;
use App\Domain\Ai\SiteUnreadable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A sentence that names a website is built from what the website sells, not from its name.
 * The case behind it: "make the banner for wp-giftcard.com" became ads for cashing in gift cards.
 */
class ProductPageTest extends TestCase
{
    private const SITE = '<html><head><title>WP Gift Card: sell vouchers on WordPress</title>'
        .'<meta name="description" content="The WordPress plugin for restaurants, spas and hotels to sell gift vouchers online."></head>'
        .'<body><nav>Pricing</nav><h1>Sell gift vouchers from your own website</h1><script>var x = "secret";</script>'
        .'<p>No commission. Customers buy, print or e-mail the voucher, you redeem it at the counter.</p></body></html>';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't']);
        $this->app->instance(ProductPage::class, new ProductPage(fn (string $host) => $host === 'internal.test' ? ['10.0.0.5'] : ['93.184.216.34']));
    }

    public function test_it_finds_the_domain_and_ignores_files_and_mail_addresses(): void
    {
        $this->assertSame('wp-giftcard.com', ProductPage::domainIn('make the banner for wp-giftcard.com'));
        $this->assertSame('baeckerei-huber.at', ProductPage::domainIn('Anzeigen für https://www.baeckerei-huber.at/shop bitte'));
        $this->assertNull(ProductPage::domainIn('schreib an office@huber.at, siehe index.html'));
        $this->assertNull(ProductPage::domainIn('Ein Salon in Wien'));
    }

    public function test_the_page_s_own_pictures_are_listed_and_furniture_left_out(): void
    {
        $html = '<html><head><meta property="og:image" content="https://wp-giftcard.com/share.jpg"></head><body>'
            .'<img src="/wp-content/themes/gift-voucher/images/logo.svg" alt="WP Gift Card">'
            .'<img src="images/gift-card 4.png" alt="Christmas voucher">'
            .'<img data-src="https://cdn.wp-giftcard.com/editor.png" src="data:image/gif;base64,R0lG">'
            .'<img src="https://wp-giftcard.com/icons/paypal.png" alt="">'
            .'<img src="https://other.com/stock.jpg" alt="elsewhere">'
            .'<img src="https://wp-giftcard.com/tiny.png" width="32" height="32">'
            .'<img src="/images/gift-card 4.png"></body></html>';

        [$images, $logo] = ProductPage::images($html, 'https://wp-giftcard.com/', 'wp-giftcard.com');

        $this->assertSame('https://wp-giftcard.com/wp-content/themes/gift-voucher/images/logo.svg', $logo);
        $this->assertSame([
            'https://wp-giftcard.com/share.jpg',
            'https://wp-giftcard.com/images/gift-card%204.png',
            'https://cdn.wp-giftcard.com/editor.png',
        ], array_column($images, 'url'));
        $this->assertSame('Christmas voucher', $images[1]['alt']);
    }

    public function test_a_picture_is_only_downloaded_from_the_named_domain_on_a_public_host(): void
    {
        Http::fake(['*' => Http::response('PNGBYTES', 200, ['Content-Type' => 'image/png'])]);
        $pages = app(ProductPage::class);

        $this->assertSame('PNGBYTES', $pages->download('https://wp-giftcard.com/a.png', 'wp-giftcard.com'));
        $this->assertNull($pages->download('https://other.com/a.png', 'wp-giftcard.com'));
        $this->assertNull($pages->download('https://internal.test/a.png', 'internal.test'));
    }

    public function test_only_pictures_big_enough_for_a_slot_are_offered_with_their_size(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('no gd');
        }
        $png = function (int $w, int $h): string {
            ob_start();
            imagepng(imagecreatetruecolor($w, $h));

            return (string) ob_get_clean();
        };
        $site = str_replace('</body>', '<img src="/big.png" alt="Voucher"><img src="/icon-ish.png" alt="small"></body>', self::SITE);
        Http::fake([
            'https://shop.example/' => Http::response($site, 200, ['Content-Type' => 'text/html']),
            'https://shop.example/big.png' => Http::response($png(800, 500), 200, ['Content-Type' => 'image/png']),
            'https://shop.example/icon-ish.png' => Http::response($png(40, 40), 200, ['Content-Type' => 'image/png']),
        ]);

        $page = app(ProductPage::class)->read('shop.example');

        $this->assertSame([['url' => 'https://shop.example/big.png', 'alt' => 'Voucher', 'size' => '800x500']], $page['images']);
    }

    public function test_a_sentence_is_thin_when_it_is_little_more_than_the_domain(): void
    {
        $this->assertTrue(ProductPage::sentenceIsThin('make the banner for wp-giftcard.com', 'wp-giftcard.com'));
        $this->assertFalse(ProductPage::sentenceIsThin(
            'Anzeigen für wp-giftcard.com, ein WordPress Plugin mit dem Restaurants und Hotels Gutscheine online verkaufen',
            'wp-giftcard.com'));
    }

    public function test_the_page_text_leads_with_title_description_and_headings_and_drops_scripts(): void
    {
        $text = ProductPage::text(self::SITE);

        $this->assertStringStartsWith('Title: WP Gift Card: sell vouchers on WordPress', $text);
        $this->assertStringContainsString('Description: The WordPress plugin for restaurants', $text);
        $this->assertStringContainsString('Headings: Sell gift vouchers from your own website', $text);
        $this->assertStringNotContainsString('secret', $text);
    }

    public function test_the_builder_is_told_what_the_website_sells(): void
    {
        Http::fake([
            'https://wp-giftcard.com/' => Http::response('', 301, ['Location' => 'https://www.wp-giftcard.com/']),
            'https://www.wp-giftcard.com/' => Http::response(self::SITE, 200, ['Content-Type' => 'text/html; charset=utf-8']),
            '*/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => "Product: a WordPress plugin to sell gift vouchers.\nNot to confuse with: a service that buys gift cards for cash."]]]])
                ->push(['choices' => [['message' => ['content' => '<!doctype html><html><head><title>X</title></head><body><h1>X</h1></body></html>']]]]),
        ]);

        $out = app(PrototypeWriter::class)->build('make the banner for wp-giftcard.com', 'ads');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'chat/completions')
            && str_contains(json_encode($r['messages'][1]['content'] ?? ''), 'WHAT THIS BUSINESS SELLS')
            && str_contains(json_encode($r['messages'][1]['content']), 'a WordPress plugin to sell gift vouchers'));
        $this->assertTrue($out['qa']['product']['read']);
        $this->assertSame('https://www.wp-giftcard.com/', $out['qa']['product']['url']);
    }

    public function test_a_thin_sentence_about_a_site_that_cannot_be_read_stops_before_any_generation(): void
    {
        Http::fake(['https://gone.example/' => Http::response('', 404), '*' => Http::response(['choices' => []])]);

        try {
            app(PrototypeWriter::class)->build('make the banner for gone.example', 'ads');
            $this->fail('built from a name alone');
        } catch (SiteUnreadable $e) {
            $this->assertSame('site-unreadable: gone.example', $e->getMessage());
        }
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'chat/completions'));
    }

    public function test_a_private_address_is_never_fetched(): void
    {
        Http::fake();

        $this->assertNull(app(ProductPage::class)->read('internal.test'));
        Http::assertNothingSent();
    }

    public function test_a_sentence_that_explains_itself_builds_even_when_the_site_is_down(): void
    {
        Http::fake([
            'https://down.example/' => Http::response('', 500),
            '*/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => '<!doctype html><html><head><title>X</title></head><body><h1>X</h1></body></html>']]]]),
        ]);

        $out = app(PrototypeWriter::class)->build('Anzeigen für down.example, ein WordPress Plugin mit dem Restaurants und Hotels Gutscheine online verkaufen', 'ads');

        $this->assertFalse($out['qa']['product']['read']);
    }
}
