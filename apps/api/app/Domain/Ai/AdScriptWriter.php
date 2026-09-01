<?php

namespace App\Domain\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Turns one sentence from the customer into the copy for an ad.
 *
 * Goes through the same host sidecar as ImageService, which also proxies /v1/chat/completions to
 * the OpenClaw gateway. Reusing that one door means no second token and no direct route from this
 * container to the gateway, which binds loopback on purpose.
 *
 * The thing behind that endpoint is an AGENT with tools, not a plain completion. Ask it for an
 * "image_prompt" for a "Bild-Anzeige" and it goes off and generates a picture, answering with
 * "Bild wird generiert" instead of JSON. So the contract below never says image or generate: it
 * asks for a written brief, the field is called `picture`, and the shape is repeated in the user
 * message where the agent is least likely to talk past it.
 */
class AdScriptWriter
{
    private const COMMON = <<<'TXT'
        You are a copywriter. You only write text. You never create, generate, draw or fetch
        anything. Your entire answer is one JSON object and nothing else: no greeting, no
        explanation, no code fence.

        {"scenes":[{"title":"...","text":"...","seconds":3.5,"zoom":"in","picture":"..."}]}

        - title: at most 6 words. text: one sentence, at most 18 words. Same language as the brief.
        - picture: an English sentence describing what a photographer would shoot for this scene
          (subject, setting, light, mood). No words, letters, logos or screens in that description:
          the copy is drawn on top afterwards, and a photo with writing in it comes out unreadable.
        - Plain sentences. No em dashes.
        TXT;

    private const VIDEO = <<<'TXT'
        You write very short marketing films.

        - 3 to 5 scenes. The last one closes the film: title only, no text, no picture.
        - seconds between 2.5 and 4. zoom is "in" or "out", alternating.
        TXT;

    private const STILL = <<<'TXT'
        You write single-frame ads, the kind that has to land while someone scrolls past.

        - EXACTLY ONE scene, and it must have a picture.
        - title is the hook, text is the reason to care. Shorter than you would write for a film.
        - send seconds and zoom anyway; they are ignored for this kind.
        TXT;

    /** @return array<int,array<string,mixed>> */
    public function write(string $prompt, string $language = 'de', string $kind = 'video'): array
    {
        $system = ($kind === 'image' ? self::STILL : self::VIDEO)."\n\n".self::COMMON;

        // Two attempts: the agent occasionally answers conversationally on the first go, and a
        // blunter reminder is cheaper than failing the whole render.
        foreach ([$prompt, $prompt."\n\nReturn the JSON object only. Do not do anything else."] as $attempt) {
            $scenes = $this->parseScenes($this->ask($system, $language, $attempt));
            if ($scenes !== []) {
                return $scenes;
            }
        }

        throw new RuntimeException('AI không trả về kịch bản dùng được.');
    }

    private function ask(string $system, string $language, string $brief): string
    {
        $baseUrl = rtrim((string) config('services.ai_image.base_url'), '/');
        $token = (string) config('services.ai_image.token');

        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('Chưa cấu hình dịch vụ AI (AI_IMAGE_SERVICE_TOKEN).');
        }

        $request = Http::baseUrl($baseUrl)->withToken($token)->acceptJson()->timeout(180)->connectTimeout(10);

        // `model` is the agent target; the model behind it is pinned with this header, the way
        // Codemenschen_OpenClaw's inference gateway does it.
        $backend = (string) config('services.ai_image.chat_backend_model');
        if ($backend !== '') {
            $request = $request->withHeaders(['x-openclaw-model' => $backend]);
        }

        $res = $request->post('/v1/chat/completions', [
            'model' => config('services.ai_image.chat_model', 'openclaw/main'),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                [
                    'role' => 'user',
                    'content' => "Language: {$language}\n\nBrief: {$brief}\n\n"
                        .'Answer with the JSON object described above, nothing else.',
                ],
            ],
        ]);

        if (! $res->successful()) {
            throw new RuntimeException('Viết nội dung quảng cáo thất bại ('.$res->status().').');
        }

        return (string) $res->json('choices.0.message.content');
    }

    /** @return array<int,array<string,mixed>> */
    private function parseScenes(string $text): array
    {
        // Models still fence JSON now and then; take the outermost object rather than failing.
        if (preg_match('/\{.*\}/s', $text, $m) !== 1) {
            return [];
        }

        $data = json_decode($m[0], true);
        $raw = is_array($data) ? ($data['scenes'] ?? []) : [];
        $scenes = [];

        foreach (array_slice(is_array($raw) ? $raw : [], 0, 6) as $s) {
            if (! is_array($s)) {
                continue;
            }
            $title = trim((string) ($s['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $scenes[] = [
                'title' => mb_substr($title, 0, 80),
                'text' => mb_substr(trim((string) ($s['text'] ?? '')), 0, 220),
                'seconds' => min(4.0, max(2.5, (float) ($s['seconds'] ?? 3.5))),
                'zoom' => ($s['zoom'] ?? 'in') === 'out' ? 'out' : 'in',
                // `picture` is the current name; `image_prompt` is what the first version asked
                // for and what the rows written before this change still carry.
                'picture' => mb_substr(trim((string) ($s['picture'] ?? $s['image_prompt'] ?? '')), 0, 500),
            ];
        }

        return $scenes;
    }
}
