<?php

namespace App\Domain\Ai;

use RuntimeException;

/**
 * The one place the model behind the chat agent is chosen, and the one rule about it.
 *
 * Text, code, prototypes and copy are written by Claude through OpenClaw. OpenAI is for rendering
 * images and nothing else: that is the owner's decision, and on 2026-09-05 a speed benchmark was
 * run through gpt-5.5 without asking. A rule in a memory file is a note; this is the guard. Any
 * caller that would pin the chat backend to an OpenAI model fails here, before a token is spent.
 */
final class ChatBackend
{
    /**
     * The value for the x-openclaw-model header, or null to leave the agent on its own model.
     *
     * @throws RuntimeException when the configured backend is an OpenAI model
     */
    public static function pin(): ?string
    {
        $backend = trim((string) config('services.ai_image.chat_backend_model'));
        if ($backend === '') {
            return null;
        }
        self::assertAllowed($backend);

        return $backend;
    }

    /** Text generation never goes to OpenAI. Image rendering has its own configuration. */
    public static function assertAllowed(string $model): void
    {
        if (preg_match('~(^|/)(openai|gpt)[-/]?~i', $model) === 1 || str_contains(strtolower($model), 'chatgpt')) {
            throw new RuntimeException("Chat backend '{$model}' is an OpenAI model. OpenAI is for image rendering only; text goes through Claude.");
        }
    }
}
