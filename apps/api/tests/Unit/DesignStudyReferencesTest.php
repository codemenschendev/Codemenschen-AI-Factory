<?php

namespace Tests\Unit;

use App\Domain\Design\DesignLibrary;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The screens a build studies before it draws.
 *
 * One per wanted screen type from the trade's own apps, legible ones first, the trade's other
 * screens after, and the same screen types from any trade when the trade is thin. And the trade's
 * numbers, counted, because "a third of ride-hailing screens are maps" is what changes a design.
 */
class DesignStudyReferencesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/study-'.uniqid());
        File::makeDirectory($this->dir.'/images/app-screen/detail', 0755, true);
        File::makeDirectory($this->dir.'/images/app-screen/layout', 0755, true);

        $recs = [
            $this->rec('ride-map-d', 'detail', 'transport_mobility', 'map', ['bottom_sheet']),
            $this->rec('ride-map-l', 'layout', 'transport_mobility', 'map', ['bottom_sheet', 'floating_action_button']),
            $this->rec('ride-list', 'layout', 'transport_mobility', 'list_feed', ['list_rows']),
            $this->rec('ride-form', 'layout', 'transport_mobility', 'form_input', []),
            $this->rec('ride-home', 'layout', 'transport_mobility', 'home_dashboard', ['card_grid']),
            $this->rec('ride-onb', 'layout', 'transport_mobility', 'onboarding', []),
            $this->rec('food-ok', 'detail', 'food_delivery', 'success_confirmation', []),
            $this->rec('bank-ok', 'detail', 'finance_banking', 'success_confirmation', []),
        ];
        File::makeDirectory($this->dir.'/images/web-screen/detail', 0755, true);
        File::makeDirectory($this->dir.'/images/advertisement/layout', 0755, true);
        foreach ([['b1', 'beauty_salon', 'landing'], ['b2', 'beauty_salon', 'landing'], ['b3', 'beauty_salon', 'about'],
            ['s1', 'business_saas', 'landing'], ['s2', 'business_saas', 'landing'], ['s3', 'business_saas', 'pricing'],
            ['f1', 'finance_banking', 'landing']] as [$id, $ind, $type]) {
            $recs[] = $this->web($id, $ind, $type);
        }
        foreach ([['a1', 'events_culture', 'seasonal'], ['a2', 'events_culture', 'seasonal'], ['a3', 'events_culture', 'price_anchor'],
            ['a4', 'business_saas', 'demo'], ['a5', 'travel', 'problem_solution'], ['a6', 'business_saas', 'seasonal']] as [$id, $ind, $angle]) {
            $recs[] = $this->ad($id, $ind, $angle);
        }
        foreach ($recs as $r) {
            File::put($this->dir.'/'.$r['file'], 'bytes-'.$r['id']);
        }
        File::put($this->dir.'/catalog.json', json_encode(['images' => $recs]));
        config(['services.media.design_library_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function web(string $id, string $industry, string $type): array
    {
        return [
            'id' => $id, 'medium' => 'web', 'file' => "images/web-screen/detail/$id.webp", 'byte_size' => 10,
            'visual' => ['category' => 'web-screen', 'grade' => 'detail', 'width' => 1440, 'height' => 4000, 'orientation' => 'portrait'],
            'sources' => [], 'ai_index' => ['search_text' => $id],
            'labels' => ['page_type' => $type, 'industry' => $industry, 'hero_style' => 'split_text_visual',
                'hero_visual' => 'photograph', 'sections' => ['logo_row', 'feature_grid', 'cta_band'], 'density' => 'medium',
                'palette' => ['scheme' => 'light', 'accent' => 'green'], 'nav_cta' => true, 'notes' => "web $id"],
        ];
    }

    private function ad(string $id, string $industry, string $angle): array
    {
        return [
            'id' => $id, 'medium' => 'asset', 'file' => "images/advertisement/layout/$id.jpg", 'byte_size' => 10,
            'visual' => ['category' => 'advertisement', 'grade' => 'layout', 'width' => 600, 'height' => 600, 'orientation' => 'square'],
            'sources' => [], 'ai_index' => ['search_text' => $id],
            'labels' => ['angle' => $angle, 'industry' => $industry, 'format' => 'feed_square', 'hook_position' => 'top',
                'text_load' => 'light', 'has_people' => true, 'has_price' => false, 'notes' => "ad $id"],
        ];
    }

    private function rec(string $id, string $grade, string $industry, string $screen, array $patterns): array
    {
        return [
            'id' => $id, 'medium' => 'app', 'file' => "images/app-screen/$grade/$id.webp", 'byte_size' => 10,
            'visual' => ['category' => 'app-screen', 'grade' => $grade, 'width' => $grade === 'detail' ? 1179 : 360,
                'height' => 2556, 'orientation' => 'portrait'],
            'sources' => [], 'ai_index' => ['search_text' => $id],
            'labels' => ['screen_type' => $screen, 'industry' => $industry, 'layout_patterns' => $patterns,
                'density' => 'medium', 'palette' => ['scheme' => 'dark', 'accent' => 'green'], 'notes' => "note $id"],
        ];
    }

    public function test_one_screen_per_wanted_type_legible_first_then_the_rest_of_the_trade(): void
    {
        $refs = app(DesignLibrary::class)->references('transport_mobility', ['map', 'list_feed', 'form_input', 'success_confirmation'], 6);

        $ids = array_column($refs, 'id');
        $this->assertSame('ride-map-d', $ids[0], 'the legible map beats the small one');
        $this->assertSame(['ride-list', 'ride-form'], array_slice($ids, 1, 2));
        // No ride-hailing confirmation exists. The trade's own remaining screens come first,
        // whatever their type: a music app's list teaches less about rides than a ride app's
        // onboarding does. Only then is the missing type borrowed from another trade.
        $this->assertCount(6, $refs);
        // Random among equals, so the three are asserted as a set.
        $this->assertSame(6, count(array_unique($ids)));
        $this->assertEmpty(array_diff($ids, ['ride-map-d', 'ride-list', 'ride-form', 'ride-map-l', 'ride-home', 'ride-onb']),
            'six own screens fill the cap before anything is borrowed');
        $this->assertSame('map', $refs[0]['screen_type']);
        $this->assertStringStartsWith('data:image/webp;base64,', $refs[0]['data']);
    }

    public function test_the_trade_s_numbers_are_a_paragraph_the_model_can_read(): void
    {
        $stats = app(DesignLibrary::class)->industryStats('transport_mobility');

        $this->assertStringContainsString('6 screens of transport mobility apps', $stats);
        $this->assertStringContainsString('map (2)', $stats);
        $this->assertStringContainsString('bottom sheet (2)', $stats);
        $this->assertStringContainsString('dark (6)', $stats);
        // Too few to count is silence, not a confident paragraph about two screens.
        $this->assertSame('', app(DesignLibrary::class)->industryStats('food_delivery'));
    }

    public function test_a_site_study_gets_the_trade_s_landing_pages_first_then_anyone_s(): void
    {
        $lib = new DesignLibrary($this->dir);

        $refs = $lib->siteReferences('beauty_salon', 4);

        $ids = array_column($refs, 'id');
        // The salon's two landing pages, then its about page, then a landing page of another trade.
        $first = array_slice($ids, 0, 2);
        sort($first);
        $this->assertSame(['b1', 'b2'], $first);
        $this->assertSame('b3', $ids[2]);
        $this->assertContains($ids[3], ['s1', 's2', 'f1'], 'only landing pages are borrowed');
        $this->assertSame('landing page', $refs[0]['screen_type']);
        $this->assertSame('web b1', $refs[array_search('b1', $ids, true)]['note']);
    }

    public function test_an_ad_study_sees_every_angle_before_one_repeats(): void
    {
        $lib = new DesignLibrary($this->dir);

        $refs = $lib->adReferences('events_culture', 4);

        $ids = array_column($refs, 'id');
        // Two angles exist for the trade: one of each first, then the trade's third, then another trade.
        $angles = fn (array $slice) => array_map(fn ($id) => ['a1' => 'seasonal', 'a2' => 'seasonal', 'a3' => 'price_anchor',
            'a4' => 'demo', 'a5' => 'problem_solution', 'a6' => 'seasonal'][$id], $slice);
        $first = $angles(array_slice($ids, 0, 2));
        sort($first);
        $this->assertSame(['price_anchor', 'seasonal'], $first);
        $this->assertEmpty(array_diff(array_slice($ids, 0, 3), ['a1', 'a2', 'a3']), 'the trade\'s own three come first');
        $this->assertContains($ids[3], ['a4', 'a5', 'a6']);
        $this->assertStringContainsString('ad, feed square', $refs[0]['screen_type']);
    }

    public function test_the_web_and_ad_numbers_say_when_they_fall_back_to_the_whole_library(): void
    {
        $lib = new DesignLibrary($this->dir);

        // Two salon landing pages are below the five that make a count; the paragraph says so.
        $web = $lib->webStats('beauty_salon');
        $this->assertStringContainsString('too few beauty salon pages', $web);
        $this->assertStringContainsString('First screen: split text visual (5)', $web);
        $this->assertStringContainsString('A button in the navigation: 5 of 5', $web);

        $ads = $lib->adStats('events_culture');
        $this->assertStringContainsString('too few events culture ads', $ads);
        $this->assertStringContainsString('seasonal (3)', $ads);
        $this->assertStringContainsString('A person in the picture: 6 of 6', $ads);
    }
}
