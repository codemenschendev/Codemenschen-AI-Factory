<?php

namespace Tests\Unit;

use App\Domain\Ai\PrototypeWriter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The app prompt carries what the reference library taught; the other two do not, because a
 * landing page learns nothing from a phone screen and the tokens are not free.
 */
class PrototypeWriterTest extends TestCase
{
    private function fakeAnswer(): void
    {
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't']);
        Http::fake(['*/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => '<!doctype html><html><head><title>X</title>'
                .'<link rel="stylesheet" href="house.css"></head><body><h1>X</h1></body></html>']]],
        ])]);
    }

    private function systemPrompt(string $kind): string
    {
        $this->fakeAnswer();
        app(PrototypeWriter::class)->build('Ein Salon in Wien', $kind);
        $sent = '';
        Http::assertSent(function ($request) use (&$sent) {
            $sent = $request['messages'][0]['content'];

            return true;
        });

        return is_array($sent) ? json_encode($sent) : $sent;
    }

    public function test_both_free_prompts_carry_the_laws_and_the_photo_contract(): void
    {
        // Handing the look back to the model loses what the stylesheet guaranteed by construction,
        // so the guarantees are stated instead. Losing either of these silently is the failure.
        foreach (['app', 'site'] as $kind) {
            $sent = $this->systemPrompt($kind);
            $this->assertStringContainsString('NEVER emoji', $sent, $kind);
            $this->assertStringContainsString('Nothing may scroll sideways', $sent, $kind);
            $this->assertStringContainsString('photo-wide', $sent, $kind);
            $this->assertStringContainsString('YOU WRITE THE CSS', $sent, $kind);
        }
    }

    public function test_the_site_prompt_carries_what_the_web_library_counted(): void
    {
        $sent = $this->systemPrompt('site');

        // 202 of 545 landing pages open on a photograph, more than every drawn mock together, and
        // reaching for a mock instead is the thing the numbers exist to correct.
        $this->assertStringContainsString('photograph is the most common', $sent);
        $this->assertStringContainsString('Proof comes before explanation', $sent);
    }

    public function test_the_ad_prototype_still_draws_on_the_house_stylesheet(): void
    {
        // Its canvases are real platform sizes; that is the whole point of it and not a style.
        $sent = $this->systemPrompt('ads');

        $this->assertStringNotContainsString('YOU WRITE THE CSS', $sent);
    }

    public function test_the_app_prompt_builds_the_app_not_a_page_about_it(): void
    {
        $sent = $this->systemPrompt('app');

        // The old app prompt said "the app itself" and then dictated a nav, a hero, marketing
        // sections and a footer, which is what a customer looked at and called strange.
        $this->assertStringContainsString('app-page', $sent);
        $this->assertStringContainsString('tabbar', $sent);
        $this->assertStringNotContainsString('<footer class="footer">', $sent);
    }

    public function test_the_app_brief_carries_the_conventions_from_the_library(): void
    {
        $prompt = $this->systemPrompt('app');

        $this->assertStringContainsString('What real app screens do', $prompt);
        // A number, not a class name: the file used to end in a list of stylesheet classes the
        // model no longer writes against, and it was reading instructions for a page it was not
        // building. What survives the stylesheet is what the library counted.
        $this->assertStringContainsString('480 screens ended that way', $prompt);
    }

    public function test_a_landing_page_brief_does_not(): void
    {
        $this->assertStringNotContainsString('What real app screens do', $this->systemPrompt('site'));
    }

    public function test_the_conventions_file_stays_small_enough_to_send_every_time(): void
    {
        // The whole page has to be written inside the sidecar's timeout; a brief that grows
        // without anyone noticing is how the first version started timing out.
        $this->assertLessThan(6000, filesize(resource_path('design/app-conventions.md')));
    }
}
