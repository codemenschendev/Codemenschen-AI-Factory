<?php

namespace Tests\Unit;

use App\Domain\Ai\AdScriptWriter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The copy itself is the model's work and cannot be asserted. What can be asserted is the brief it
 * gets and what happens to the answer: the film has to end on a call to action, and that closing
 * frame must not drag a paid photo along with it.
 */
class AdScriptWriterTest extends TestCase
{
    private function fakeAnswer(array $scenes): void
    {
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't']);
        Http::fake(['*/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['scenes' => $scenes])]]],
        ])]);
    }

    public function test_the_closing_scene_of_a_film_carries_no_picture(): void
    {
        $this->fakeAnswer([
            ['title' => 'Hook', 'text' => 'a', 'picture' => 'a kitchen'],
            ['title' => 'Turn', 'text' => 'b', 'picture' => 'a hallway'],
            ['title' => 'Jetzt Termin buchen', 'text' => 'salon.at', 'picture' => 'a shop front'],
        ]);

        $scenes = app(AdScriptWriter::class)->write('Ad for a salon', 'de');

        $this->assertSame('a kitchen', $scenes[0]['picture']);
        $this->assertSame('', $scenes[2]['picture'], 'the call to action is painted on the background');
    }

    public function test_a_single_frame_ad_keeps_its_picture(): void
    {
        $this->fakeAnswer([['title' => 'Hook', 'text' => 'b', 'picture' => 'a shop front']]);

        $scenes = app(AdScriptWriter::class)->write('Ad for a salon', 'de', 'image');

        $this->assertSame('a shop front', $scenes[0]['picture']);
    }

    public function test_the_chosen_goal_becomes_the_action_the_ad_closes_on(): void
    {
        $this->fakeAnswer([['title' => 'Hook', 'text' => 'b', 'picture' => 'a shop front']]);

        app(AdScriptWriter::class)->write('Ad for a salon', 'de', 'image', [], 'booking');

        Http::assertSent(fn ($request) => str_contains(
            $request['messages'][0]['content'],
            'make the reader book an appointment, a table or a slot'
        ));
    }

    public function test_an_unknown_goal_is_ignored_rather_than_pasted_into_the_brief(): void
    {
        $this->fakeAnswer([['title' => 'Hook', 'text' => 'b', 'picture' => 'a shop front']]);

        app(AdScriptWriter::class)->write('Ad for a salon', 'de', 'image', [], 'nonsense');

        Http::assertSent(fn ($request) => ! str_contains($request['messages'][0]['content'], 'nonsense'));
    }

    public function test_the_chosen_angle_decides_the_shape_of_the_story(): void
    {
        $this->fakeAnswer([['title' => 'Hook', 'text' => 'b', 'picture' => 'a shop front']]);

        app(AdScriptWriter::class)->write('Ad for a salon', 'de', 'image', [], null, 'before_after');

        Http::assertSent(fn ($request) => str_contains(
            $request['messages'][0]['content'],
            'Contrast the day before with the day after'
        ));
    }

    public function test_the_testimonial_angle_is_told_not_to_invent_the_customer(): void
    {
        $this->fakeAnswer([['title' => 'Hook', 'text' => 'b', 'picture' => 'a shop front']]);

        app(AdScriptWriter::class)->write('Ad for a salon', 'de', 'image', [], null, 'testimonial');

        Http::assertSent(fn ($request) => str_contains(
            $request['messages'][0]['content'],
            'never invent a name, a rating or a sentence in quotation marks'
        ));
    }

    public function test_an_unknown_angle_is_ignored_rather_than_pasted_into_the_brief(): void
    {
        $this->fakeAnswer([['title' => 'Hook', 'text' => 'b', 'picture' => 'a shop front']]);

        app(AdScriptWriter::class)->write('Ad for a salon', 'de', 'image', [], null, 'jazzhands');

        Http::assertSent(fn ($request) => ! str_contains($request['messages'][0]['content'], 'jazzhands'));
    }

    public function test_the_brief_asks_for_a_hook_a_benefit_and_a_call_to_action(): void
    {
        $this->fakeAnswer([['title' => 'Hook', 'text' => 'b', 'picture' => 'a shop front']]);

        app(AdScriptWriter::class)->write('Ad for a salon', 'de');

        Http::assertSent(function ($request) {
            $system = $request['messages'][0]['content'];

            return str_contains($system, 'Hook:')
                && str_contains($system, 'call to action')
                && str_contains($system, 'direct response copywriter');
        });
    }

    public function test_a_script_that_breaks_a_rule_is_written_again_with_the_rule_named(): void
    {
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't',
            'services.ai_image.private_identities' => ['Fuchsbauer']]);
        $answer = fn (array $scenes) => ['choices' => [['message' => ['content' => json_encode(['scenes' => $scenes])]]]];
        Http::fake(['*/v1/chat/completions' => Http::sequence()
            ->push($answer([['title' => '20,000 stores sell with it', 'text' => 'Ask Fuchsbauer — today.', 'picture' => 'a shop']]))
            ->push($answer([['title' => '20,000 downloads', 'text' => 'Sell your own vouchers today.', 'picture' => 'a shop']])),
        ]);

        $writer = app(AdScriptWriter::class);
        $scenes = $writer->write('Ad for wp-giftcard.com', 'en', 'image', ['subject_website_text' => 'Buy now 20,000 Downloads']);

        $this->assertSame('20,000 downloads', $scenes[0]['title']);
        $this->assertSame([], $writer->faults);
        Http::assertSent(fn ($r) => str_contains((string) json_encode($r['messages'][1]['content']), 'A first draft broke these rules')
            && str_contains((string) json_encode($r['messages'][1]['content']), 'number-reworded'));
    }

    public function test_when_both_drafts_break_a_rule_the_cleaner_one_is_kept_and_its_faults_reported(): void
    {
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't']);
        $answer = fn (array $scenes) => ['choices' => [['message' => ['content' => json_encode(['scenes' => $scenes])]]]];
        Http::fake(['*/v1/chat/completions' => Http::sequence()
            ->push($answer([['title' => 'Seit 1998 zertifiziert', 'text' => 'Buchen — jetzt.', 'picture' => 'x']]))
            ->push($answer([['title' => 'Jetzt buchen', 'text' => 'Termin in Wien — heute.', 'picture' => 'x']])),
        ]);

        $writer = app(AdScriptWriter::class);
        $scenes = $writer->write('Friseur in Wien', 'de', 'image');

        $this->assertSame('Jetzt buchen', $scenes[0]['title']);
        $this->assertCount(1, $writer->faults);
        $this->assertStringStartsWith('dash:', $writer->faults[0]);
    }
}
