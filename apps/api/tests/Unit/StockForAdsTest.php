<?php

namespace Tests\Unit;

use App\Domain\Ai\StockPhotos;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The extra rule a paid advertisement puts on a free photograph.
 *
 * The Pexels licence allows commercial use, and it guarantees no model release. It also asks that
 * the people in a photo are not made to look as though they endorse the product. A stranger's face
 * in a Meta ad for somebody's salon is exactly that, so a scene naming a person is generated
 * instead of borrowed. Places, rooms, food and tools carry no such claim, and those are most
 * scenes and most of the saving.
 */
class StockForAdsTest extends TestCase
{
    private function ok(): void
    {
        config(['services.stock.pexels_key' => 'k']);
        Http::fake([
            'api.pexels.com/*' => Http::response(['photos' => [[
                'photographer' => 'Anna', 'url' => 'https://www.pexels.com/photo/1',
                'src' => ['large' => 'https://images.pexels.com/1.jpg'],
            ]]]),
            'images.pexels.com/*' => Http::response(str_repeat('x', 20000)),
        ]);
    }

    /** @return list<string> */
    public static function scenesWithPeople(): array
    {
        return [
            ['Eine Friseurin föhnt einer Kundin die Haare, warmes Licht'],
            ['Der Inhaber steht vor seiner Werkstatt und lächelt'],
            ['Zwei Gäste stoßen an einem Tisch an'],
            ['A smiling customer collects a parcel'],
            ['Portrait of the owner in front of the shop'],
        ];
    }

    #[DataProvider('scenesWithPeople')]
    public function test_a_scene_with_a_person_is_never_borrowed(string $brief): void
    {
        $this->ok();

        $this->assertNull(app(StockPhotos::class)->findForAd($brief));
        Http::assertNothingSent();
    }

    /** @return list<string> */
    public static function scenesWithoutPeople(): array
    {
        return [
            ['Frische Semmeln im Weidenkorb auf dem Verkaufstresen'],
            ['Eine aufgeräumte Tischlerwerkstatt mit Eichenbrettern'],
            ['Ein Behandlungsstuhl in einer hellen Ordination'],
            ['Nächtliche Straße in Wien, nasses Kopfsteinpflaster'],
            ['Ein leerer Behandlungsraum mit Fenster zum Innenhof'],
            ['Handgehobelte Eichenbretter gestapelt in der Werkstatt'],
            // "sur-face" carries a person word inside it and is a worktop. Matching substrings
            // rather than word starts sent scenes like this to the paid path for nothing.
            ['A clean wooden work surface with tools laid out'],
            // Hands identify nobody, so a pair of them is a thing, not a person.
            ['Hände kneten Teig auf einer Arbeitsplatte'],
        ];
    }

    #[DataProvider('scenesWithoutPeople')]
    public function test_a_place_or_a_thing_is_borrowed(string $brief): void
    {
        $this->ok();

        $found = app(StockPhotos::class)->findForAd($brief);

        $this->assertNotNull($found);
        $this->assertSame('Anna', $found['credit']);
    }

    public function test_the_rule_only_applies_to_ads(): void
    {
        // A prototype is a private mockup that expires in a week and claims nothing, so the same
        // brief is perfectly fine there.
        $this->ok();

        $this->assertNull(app(StockPhotos::class)->findForAd('Eine Friseurin föhnt einer Kundin die Haare'));
        $this->assertNotNull(app(StockPhotos::class)->find('Eine Friseurin föhnt einer Kundin die Haare'));
    }

    public function test_without_a_key_an_ad_scene_falls_through_to_generation(): void
    {
        config(['services.stock.pexels_key' => '']);
        Http::fake();

        $this->assertNull(app(StockPhotos::class)->findForAd('Frische Semmeln im Weidenkorb'));
        Http::assertNothingSent();
    }
}
