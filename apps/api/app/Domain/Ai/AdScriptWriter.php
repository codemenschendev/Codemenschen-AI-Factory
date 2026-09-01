<?php

namespace App\Domain\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Turns one sentence from the customer into a scene list for tools/make-video.py.
 *
 * Goes through the same host sidecar as ImageService, which also proxies /v1/chat/completions to
 * the OpenClaw gateway. Reusing that one door means no second token and no direct route from this
 * container to the gateway, which binds loopback on purpose.
 */
class AdScriptWriter
{
    private const COMMON = <<<'TXT'
        Answer with JSON only, no prose, no code fence.

        Shape:
        {"scenes":[{"title":"...","text":"...","seconds":3.5,"zoom":"in","image_prompt":"..."}]}

        - title: at most 6 words. text: one sentence, at most 18 words. Same language as the request.
        - image_prompt is English and describes a photo (subject, setting, light, mood). No text or
          words in the picture, no logos, no user interface, no letters. The words are drawn on top
          afterwards, so a picture with writing in it comes out unreadable.
        - Plain sentences. No em dashes.
        TXT;

    private const VIDEO = <<<'TXT'
        You write very short marketing videos.

        - 3 to 5 scenes. The last one closes the film: title only, no text, no image_prompt.
        - seconds between 2.5 and 4. zoom is "in" or "out", alternating.
        TXT;

    private const IMAGE = <<<'TXT'
        You write single-picture ads, the kind that has to work while someone scrolls past.

        - EXACTLY ONE scene, and it must carry an image_prompt.
        - title is the hook and text is the reason to care. Both are read at a glance, so keep
          them shorter than you would for a film.
        - seconds and zoom are ignored for this kind; send them anyway.
        TXT;

    /** @return array<int,array<string,mixed>> */
    public function write(string $prompt, string $language = 'de', string $kind = 'video'): array
    {
        $baseUrl = rtrim((string) config('services.ai_image.base_url'), '/');
        $token = (string) config('services.ai_image.token');

        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('Chưa cấu hình dịch vụ AI (AI_IMAGE_SERVICE_TOKEN).');
        }

        $res = Http::baseUrl($baseUrl)
            ->withToken($token)
            ->acceptJson()
            ->timeout(180)
            ->connectTimeout(10)
            ->post('/v1/chat/completions', [
                'model' => config('services.ai_image.chat_model', 'openclaw/main'),
                'messages' => [
                    ['role' => 'system', 'content' => ($kind === 'image' ? self::IMAGE : self::VIDEO)."\n\n".self::COMMON],
                    ['role' => 'user', 'content' => "Language: {$language}\n\n{$prompt}"],
                ],
            ]);

        if (! $res->successful()) {
            throw new RuntimeException('Viết kịch bản thất bại ('.$res->status().').');
        }

        $text = (string) $res->json('choices.0.message.content');
        $scenes = $this->parseScenes($text);

        if ($scenes === []) {
            throw new RuntimeException('AI không trả về kịch bản dùng được.');
        }

        return $scenes;
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
                'image_prompt' => mb_substr(trim((string) ($s['image_prompt'] ?? '')), 0, 500),
            ];
        }

        return $scenes;
    }
}
