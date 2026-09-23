<?php

namespace App\Domain\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One short question to Appwerk AI through the host sidecar, one answer back as text.
 *
 * The writers that need a single cheap call (keywords, search ad copy) share this door, so there
 * is one place where the gateway is reached and one place where the model is pinned.
 */
class AgentChat
{
    public function ask(string $system, string $user, string $failure = 'The AI call failed'): string
    {
        $baseUrl = rtrim((string) config('services.ai_image.base_url'), '/');
        $token = (string) config('services.ai_image.token');
        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('AI service is not configured (AI_IMAGE_SERVICE_TOKEN).');
        }

        $request = Http::baseUrl($baseUrl)->withToken($token)->acceptJson()->timeout(120)->connectTimeout(10);
        if (($backend = ChatBackend::pin()) !== null) {
            $request = $request->withHeaders(['x-openclaw-model' => $backend]);
        }

        $res = $request->post('/v1/chat/completions', [
            'model' => config('services.ai_image.chat_model', 'openclaw/appwerk'),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ]);

        if (! $res->successful()) {
            throw new RuntimeException($failure.' ('.$res->status().').');
        }

        return (string) $res->json('choices.0.message.content');
    }
}
