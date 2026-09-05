<?php

namespace Tests\Unit;

use App\Domain\Design\DesignLibrary;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Answering a landing page brief with a real landing page.
 *
 * Where the ad reference refuses on a wrong angle, this one never refuses on a wrong trade. An ad
 * angle IS the request; a landing page of the wrong trade still teaches section order and how much
 * air to leave, which is the whole reason to show one. So every filter here is a preference, and
 * a preference that would empty the pool is dropped.
 */
class SiteReferenceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/site-'.uniqid());
        File::makeDirectory($this->dir.'/images/web-screen/detail', 0755, true);
        File::makeDirectory($this->dir.'/images/web-screen/layout', 0755, true);

        foreach (['salon', 'bank_dark', 'shop', 'blurry'] as $id) {
            $grade = $id === 'blurry' ? 'layout' : 'detail';
            File::put($this->dir."/images/web-screen/$grade/$id.webp", str_repeat('x', 40));
        }

        File::put($this->dir.'/catalog.json', json_encode(['images' => [
            $this->page('salon', 'detail', 'landing', 'beauty_salon', 'light'),
            $this->page('bank_dark', 'detail', 'landing', 'finance_banking', 'dark'),
            $this->page('shop', 'detail', 'shop', 'retail_ecommerce', 'light'),
            // 360px: a landing page at that size is a blur and its section order unreadable.
            $this->page('blurry', 'layout', 'landing', 'beauty_salon', 'light'),
            // Scraped but never catalogued: must never be handed to anyone.
            $this->page('raw', 'detail', null, null, null, file: false),
        ]]));

        config(['services.media.design_library_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function page(string $id, string $grade, ?string $type, ?string $industry,
        ?string $scheme, bool $file = true): array
    {
        return [
            'id' => $id, 'medium' => 'web', 'file' => "images/web-screen/$grade/$id.webp",
            'byte_size' => 40,
            'visual' => ['category' => 'web-screen', 'grade' => $grade, 'width' => 1440,
                'height' => 2400, 'orientation' => 'portrait'],
            'sources' => [['website' => ['label' => 'mobbin']]],
            'ai_index' => ['search_text' => $id],
            'labels' => ['page_type' => $type, 'industry' => $industry,
                'hero_style' => 'split_text_visual', 'hero_visual' => 'photograph',
                'sections' => ['feature_grid', 'testimonial'],
                'palette' => ['scheme' => $scheme, 'accent' => 'blue'],
                'notes' => $type === null ? null : "note for $id"],
        ];
    }

    private function library(): DesignLibrary
    {
        return app(DesignLibrary::class);
    }

    public function test_a_salon_brief_gets_the_salon_page(): void
    {
        $ref = $this->library()->siteReference('Website für einen Friseur in Wien');

        $this->assertSame('salon', $ref['id']);
        $this->assertStringStartsWith('data:image/webp;base64,', $ref['data']);
        $this->assertSame('note for salon', $ref['note']);
    }

    public function test_a_trade_the_library_does_not_know_still_gets_a_page(): void
    {
        // Unlike an ad angle, the trade is a preference. A landing page for a bank still teaches
        // where the proof row goes and how much air the headline gets.
        $ref = $this->library()->siteReference('Website für einen Imker in Tirol');

        $this->assertNotNull($ref);
        $this->assertContains($ref['id'], ['salon', 'bank_dark']);
    }

    public function test_a_dark_brief_prefers_a_dark_page(): void
    {
        $ref = $this->library()->siteReference('Website für eine Bank', 'dark');

        $this->assertSame('bank_dark', $ref['id']);
    }

    public function test_a_shop_is_not_shown_when_a_landing_page_exists(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->assertNotSame('shop', $this->library()->siteReference('Website')['id']);
        }
    }

    public function test_an_unreadable_or_unlabelled_page_is_never_shown(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $ref = $this->library()->siteReference('Website für einen Friseur in Wien');
            $this->assertNotSame('blurry', $ref['id'], '360px teaches nothing');
            $this->assertNotSame('raw', $ref['id'], 'no labels, no reference');
        }
    }

    public function test_an_empty_library_is_silence(): void
    {
        config(['services.media.design_library_path' => $this->dir.'/nope']);

        $this->assertNull($this->library()->siteReference('Website für einen Friseur'));
    }
}
