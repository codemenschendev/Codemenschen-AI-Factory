<?php

namespace Tests\Unit;

use App\Domain\Design\DesignLibrary;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The app prompt travels with a picture now.
 *
 * Until this existed the reference catalogue held three websites and nothing else, so an app brief
 * reached the model with no image at all while 297 legible app screens sat on disk unused. What is
 * tested here is the choosing: the right trade, only screens whose text can be read, and silence
 * rather than a confident wrong answer.
 */
class PrototypeAppReferenceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/ref-'.uniqid());
        File::makeDirectory($this->dir.'/images/app-screen/detail', 0755, true);
        File::makeDirectory($this->dir.'/images/app-screen/layout', 0755, true);

        foreach (['salon', 'salon2', 'bank', 'tiny'] as $id) {
            $grade = $id === 'tiny' ? 'layout' : 'detail';
            File::put($this->dir."/images/app-screen/$grade/$id.webp", 'bytes-'.$id);
        }

        File::put($this->dir.'/catalog.json', json_encode(['images' => [
            $this->rec('salon', 'detail', 'beauty_salon', 'calendar_booking', 'Booking with a sticky action'),
            $this->rec('salon2', 'detail', 'beauty_salon', 'list_feed', 'Rows with price and duration'),
            $this->rec('bank', 'detail', 'finance_banking', 'stats_report', 'Dark tiles'),
            // Legible only at detail grade; a 360px screen teaches nothing and must never be picked.
            $this->rec('tiny', 'layout', 'beauty_salon', 'home_dashboard', 'Too small to read'),
        ]]));

        config(['services.media.design_library_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function rec(string $id, string $grade, string $industry, string $screen, string $notes): array
    {
        return [
            'id' => $id, 'medium' => 'app', 'file' => "images/app-screen/$grade/$id.webp",
            'byte_size' => 10,
            'visual' => ['category' => 'app-screen', 'grade' => $grade, 'width' => $grade === 'detail' ? 1179 : 360,
                'height' => 2556, 'orientation' => 'portrait'],
            'sources' => [['website' => ['label' => 'popular · mobbin.com']]],
            'ai_index' => ['search_text' => $id],
            'labels' => ['screen_type' => $screen, 'industry' => $industry, 'layout_patterns' => [],
                'primary_action' => null, 'density' => 'medium',
                'palette' => ['scheme' => 'light', 'accent' => 'red'], 'notes' => $notes],
        ];
    }

    private function library(): DesignLibrary
    {
        return app(DesignLibrary::class);
    }

    public function test_a_salon_brief_gets_a_salon_screen(): void
    {
        $ref = $this->library()->reference('Eine App für ein Friseurstudio in Wien mit Terminbuchung');

        $this->assertNotNull($ref);
        $this->assertContains($ref['id'], ['salon', 'salon2']);
        $this->assertStringStartsWith('data:image/webp;base64,', $ref['data']);
        $this->assertNotSame('', $ref['note']);
    }

    public function test_an_illegible_screen_is_never_the_reference(): void
    {
        // Only one beauty_salon record is detail grade if the others are filtered out by screen type.
        $ref = $this->library()->reference('Friseur in Wien', 'home_dashboard');

        $this->assertNull($ref, 'the only home_dashboard salon screen is 360px wide');
    }

    public function test_a_brief_about_nothing_the_library_knows_gets_no_picture(): void
    {
        // No reference beats the wrong reference: a fintech dashboard for a hair salon is worse
        // than the blank page the model started from.
        $this->assertNull($this->library()->reference('Eine App für Imker und ihre Bienenstöcke'));
    }

    public function test_the_trade_decides_which_screen_is_shown(): void
    {
        $ref = $this->library()->reference('App für eine Buchhaltung: Rechnungen und Steuer');

        $this->assertNotNull($ref);
        $this->assertSame('bank', $ref['id']);
    }

    public function test_a_missing_library_is_silence_not_an_error(): void
    {
        config(['services.media.design_library_path' => $this->dir.'/nope']);

        $this->assertNull($this->library()->reference('Friseur in Wien'));
    }
}
