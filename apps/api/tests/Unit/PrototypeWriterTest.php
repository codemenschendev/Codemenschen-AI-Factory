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

    public function test_the_app_brief_carries_the_conventions_from_the_library(): void
    {
        $prompt = $this->systemPrompt('app');

        $this->assertStringContainsString('What real app screens do', $prompt);
        $this->assertStringContainsString('.app-cta', $prompt);
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
