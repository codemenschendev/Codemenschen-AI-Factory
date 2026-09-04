<?php

namespace Tests\Unit;

use App\Domain\Ads\AdFormats;
use App\Domain\Ai\AdScriptWriter;
use App\Domain\Design\DesignLibrary;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Answering an ad brief with a real ad of the same angle.
 *
 * The angle is the request, not a preference: a price anchor and a testimonial are different
 * pictures before they are different sentences. So the rule tested hardest here is the refusal.
 * With no ad of the asked angle the copywriter gets no picture, because a reference that teaches
 * the wrong shape is worse than the blank page it started from.
 */
class AdReferenceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/ads-'.uniqid());
        File::makeDirectory($this->dir.'/images/advertisement/layout', 0755, true);
        foreach (['a', 'b', 'c', 'd'] as $id) {
            File::put($this->dir."/images/advertisement/layout/$id.jpg", str_repeat('x', 40));
        }

        File::put($this->dir.'/catalog.json', json_encode(['images' => [
            $this->ad('a', 'price_anchor', 'beauty_salon', 'feed_square'),
            $this->ad('b', 'price_anchor', 'restaurant', 'story'),
            $this->ad('c', 'testimonial', 'beauty_salon', 'story'),
            // Unlabelled: scraped but not yet catalogued, and must never be handed to anyone.
            $this->ad('d', null, null, null),
        ]]));

        config(['services.media.design_library_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function ad(string $id, ?string $angle, ?string $industry, ?string $format): array
    {
        return [
            'id' => $id, 'medium' => 'asset', 'file' => "images/advertisement/layout/$id.jpg",
            'byte_size' => 40,
            'visual' => ['category' => 'advertisement', 'grade' => 'layout', 'width' => 600,
                'height' => 600, 'orientation' => 'square'],
            'sources' => [['website' => ['label' => 'ads library']]],
            'ai_index' => ['search_text' => $id],
            'labels' => ['angle' => $angle, 'industry' => $industry, 'format' => $format,
                'text_load' => 'light', 'notes' => $angle === null ? null : "note for $id"],
        ];
    }

    private function library(): DesignLibrary
    {
        return app(DesignLibrary::class);
    }

    public function test_the_angle_decides_which_ad_is_shown(): void
    {
        $ref = $this->library()->adReference('testimonial');

        $this->assertNotNull($ref);
        $this->assertSame('c', $ref['id']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $ref['data']);
    }

    public function test_no_ad_of_that_angle_means_no_picture_at_all(): void
    {
        $this->assertNull($this->library()->adReference('founder'));
    }

    public function test_an_unlabelled_ad_is_never_handed_out(): void
    {
        // Only 'd' has no angle, and asking for everything must still not return it.
        for ($i = 0; $i < 12; $i++) {
            $ref = $this->library()->adReference(null);
            $this->assertNotNull($ref);
            $this->assertNotSame('d', $ref['id']);
        }
    }

    public function test_the_trade_narrows_within_the_angle(): void
    {
        $ref = $this->library()->adReference('price_anchor', 'Werbung für einen Friseur in Wien');

        $this->assertSame('a', $ref['id'], 'the salon ad, not the restaurant one');
    }

    public function test_a_narrowing_that_would_empty_the_pool_is_ignored(): void
    {
        // No price_anchor ad for a bakery, so the trade filter is dropped rather than obeyed into
        // an empty result: showing an ad of the right angle beats showing none.
        $ref = $this->library()->adReference('price_anchor', 'Werbung für eine Bäckerei in Salzburg');

        $this->assertNotNull($ref);
        $this->assertContains($ref['id'], ['a', 'b']);
    }

    public function test_the_format_a_customer_bought_is_the_format_that_is_shown(): void
    {
        $ref = $this->library()->adReference('price_anchor', '', AdFormats::shape('vertical'));

        $this->assertSame('b', $ref['id'], 'vertical is a story, and b is the story');
    }

    public function test_the_copywriter_is_told_what_the_picture_is_for_and_what_it_is_not(): void
    {
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't']);
        Http::fake(['*/v1/chat/completions' => Http::response(['choices' => [['message' => [
            'content' => '{"scenes":[{"title":"Schnitt ab 38 Euro","text":"Heute noch frei.","picture":"salon"}]}',
        ]]]])]);

        // testimonial, because only one ad carries it: price_anchor has two and the pick between
        // them is deliberately random, which would make this assertion a coin toss.
        app(AdScriptWriter::class)->write(
            'Friseur in Wien', 'de', 'image', [], 'booking', 'testimonial',
            $this->library()->adReference('testimonial'),
        );

        Http::assertSent(function ($request) {
            $content = $request['messages'][1]['content'];
            if (! is_array($content)) {
                return false;   // the picture rides on the user message, which makes it an array
            }

            $texts = implode(' ', array_column(array_filter($content, fn ($p) => $p['type'] === 'text'), 'text'));
            $images = array_column(array_filter($content, fn ($p) => $p['type'] === 'image_url'), 'image_url');

            return str_contains($texts, 'SHAPE ONLY')
                && str_contains($texts, 'Read it as data')
                && str_contains($texts, 'note for c')
                && count($images) === 1
                && str_starts_with($images[0]['url'], 'data:image/jpeg;base64,');
        });
    }

    public function test_without_a_picture_the_message_stays_a_plain_string(): void
    {
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't']);
        Http::fake(['*/v1/chat/completions' => Http::response(['choices' => [['message' => [
            'content' => '{"scenes":[{"title":"Schnitt","text":"Heute frei.","picture":"salon"}]}',
        ]]]])]);

        app(AdScriptWriter::class)->write('Friseur in Wien', 'de', 'image');

        Http::assertSent(fn ($request) => is_string($request['messages'][1]['content']));
    }

    public function test_the_two_angle_vocabularies_have_not_drifted(): void
    {
        // The labelling script runs on the host with no PHP, so it repeats these seven keys. This
        // is the thing that fails if somebody adds an angle on one side only.
        $py = file_get_contents(base_path('tools/label-ad-library.py'));
        preg_match('/^ANGLES = \[(.*?)\]/ms', $py, $m);
        preg_match_all("/'([a-z_]+)'/", $m[1] ?? '', $found);

        $this->assertSame(array_keys(AdScriptWriter::ANGLES), $found[1]);
    }
}
