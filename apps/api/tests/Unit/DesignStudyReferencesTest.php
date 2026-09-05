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
}
