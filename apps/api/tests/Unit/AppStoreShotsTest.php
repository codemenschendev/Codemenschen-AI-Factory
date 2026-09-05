<?php

namespace Tests\Unit;

use App\Domain\Design\AppStoreShots;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Which listing "Bolt" means, and that the search never breaks a build. */
class AppStoreShotsTest extends TestCase
{
    private function listing(string $name, int $ratings, int $shots = 3, int $id = 1): array
    {
        return ['trackId' => $id, 'trackName' => $name, 'userRatingCount' => $ratings,
            'screenshotUrls' => array_map(fn ($i) => "https://shots.test/$id/$i.png", range(1, $shots))];
    }

    public function test_the_most_rated_listing_whose_name_starts_with_the_term_wins(): void
    {
        Http::fake(['itunes.apple.com/*' => Http::response(['results' => [
            $this->listing('Bolt Browser and Documents', 4653, 3, 1),
            $this->listing('Bolt: Fahrten anfordern', 19159, 5, 2),
            $this->listing('Uber: Ride-Hailing & Taxis', 90482, 7, 3),
        ]])]);

        $app = app(AppStoreShots::class)->lookup('Bolt', 'at');

        $this->assertSame('Bolt: Fahrten anfordern', $app['name']);
        $this->assertSame(19159, $app['ratings']);
        $this->assertCount(5, $app['screenshots']);
    }

    public function test_a_listing_without_screenshots_is_no_listing(): void
    {
        Http::fake(['itunes.apple.com/*' => Http::response(['results' => [
            ['trackId' => 9, 'trackName' => 'Grab', 'userRatingCount' => 1, 'screenshotUrls' => []],
        ]])]);

        $this->assertNull(app(AppStoreShots::class)->lookup('Grab', 'vn'));
    }

    public function test_a_store_that_does_not_answer_is_an_empty_list(): void
    {
        Http::fake(['itunes.apple.com/*' => Http::response(null, 503)]);

        $this->assertSame([], app(AppStoreShots::class)->forApps(['Grab', 'Be'], 'vn'));
    }
}
