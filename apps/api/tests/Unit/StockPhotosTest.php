<?php

namespace Tests\Unit;

use App\Domain\Ai\StockPhotos;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The free source of pictures.
 *
 * Two things matter beyond "it fetches": that an unconfigured key changes nothing at all, and that
 * the photographer travels back with the bytes, because Pexels asks for a visible credit and a
 * credit nobody carries is a credit nobody prints.
 */
class StockPhotosTest extends TestCase
{
    private function ok(): void
    {
        config(['services.stock.pexels_key' => 'k']);
        Http::fake([
            'api.pexels.com/*' => Http::response(['photos' => [[
                'photographer' => 'Anna Fotografin',
                'url' => 'https://www.pexels.com/photo/1',
                'src' => ['large' => 'https://images.pexels.com/1-large.jpg'],
            ]]]),
            'images.pexels.com/*' => Http::response(str_repeat('x', 20000)),
        ]);
    }

    public function test_without_a_key_nothing_is_fetched_and_nothing_breaks(): void
    {
        config(['services.stock.pexels_key' => '']);
        Http::fake();

        $this->assertFalse(app(StockPhotos::class)->configured());
        $this->assertNull(app(StockPhotos::class)->find('Frische Kipferl im Weidenkorb'));
        Http::assertNothingSent();
    }

    public function test_the_photographer_comes_back_with_the_bytes(): void
    {
        $this->ok();

        $found = app(StockPhotos::class)->find('Frische Kipferl im Weidenkorb, warmes Morgenlicht');

        $this->assertSame('Anna Fotografin', $found['credit']);
        $this->assertSame('https://www.pexels.com/photo/1', $found['url']);
        $this->assertSame(20000, strlen($found['bytes']));
    }

    public function test_the_search_keeps_the_subject_and_drops_the_direction(): void
    {
        $this->ok();

        // "warmes Morgenlicht, Schaufenster Linzer Gasse Salzburg" is direction for a photographer.
        // A stock index has nothing filed under a street in Salzburg, and searching for one finds
        // nothing at all.
        app(StockPhotos::class)->find('Frische Kipferl und Mohnzopf im Weidenkorb, warmes Morgenlicht, Linzer Gasse Salzburg');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.pexels.com')) {
                return false;
            }

            return str_contains($request->url(), 'Kipferl')
                && ! str_contains($request->url(), 'Morgenlicht')
                && ! str_contains($request->url(), 'Salzburg');
        });
    }

    public function test_a_truncated_download_is_treated_as_no_photo(): void
    {
        config(['services.stock.pexels_key' => 'k']);
        Http::fake([
            'api.pexels.com/*' => Http::response(['photos' => [[
                'photographer' => 'A', 'url' => 'u', 'src' => ['large' => 'https://images.pexels.com/x.jpg'],
            ]]]),
            'images.pexels.com/*' => Http::response('tiny'),
        ]);

        $this->assertNull(app(StockPhotos::class)->find('Kipferl im Korb'));
    }

    public function test_a_search_with_no_results_is_silence(): void
    {
        config(['services.stock.pexels_key' => 'k']);
        Http::fake(['api.pexels.com/*' => Http::response(['photos' => []])]);

        $this->assertNull(app(StockPhotos::class)->find('Imker mit Bienenstock in Tirol'));
    }

    public function test_a_dead_api_is_silence_not_an_exception(): void
    {
        config(['services.stock.pexels_key' => 'k']);
        Http::fake(['api.pexels.com/*' => Http::response('nope', 503)]);

        $this->assertNull(app(StockPhotos::class)->find('Kipferl im Korb'));
    }
}
