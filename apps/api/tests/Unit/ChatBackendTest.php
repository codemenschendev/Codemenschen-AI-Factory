<?php

namespace Tests\Unit;

use App\Domain\Ai\ChatBackend;
use App\Domain\Ai\PrototypeWriter;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * OpenAI renders images and writes no text here. The owner's rule, enforced where the model is
 * chosen rather than remembered by whoever is at the keyboard.
 */
class ChatBackendTest extends TestCase
{
    public function test_no_pin_means_the_agent_keeps_its_own_model(): void
    {
        config(['services.ai_image.chat_backend_model' => '']);

        $this->assertNull(ChatBackend::pin());
    }

    public function test_a_claude_backend_passes_through(): void
    {
        config(['services.ai_image.chat_backend_model' => 'claude-cli-chat/claude-sonnet-5']);

        $this->assertSame('claude-cli-chat/claude-sonnet-5', ChatBackend::pin());
    }

    public function test_an_openai_backend_is_refused_before_a_token_is_spent(): void
    {
        foreach (['openai/gpt-5.5', 'gpt-5.5', 'openai/o4-mini', 'ChatGPT'] as $model) {
            config(['services.ai_image.chat_backend_model' => $model]);
            try {
                ChatBackend::pin();
                $this->fail("{$model} was allowed");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('image rendering only', $e->getMessage(), $model);
            }
        }
    }

    public function test_the_prototype_writer_never_sends_a_request_to_an_openai_backend(): void
    {
        config([
            'services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't',
            'services.ai_image.chat_backend_model' => 'openai/gpt-5.5',
        ]);
        Http::fake();

        $this->expectException(RuntimeException::class);
        try {
            app(PrototypeWriter::class)->build('Ein Salon in Wien', 'site');
        } finally {
            Http::assertNothingSent();
        }
    }
}
