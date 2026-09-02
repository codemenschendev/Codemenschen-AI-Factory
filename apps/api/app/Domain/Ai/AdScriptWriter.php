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
    /**
     * What the ad has to make the reader do. The key is what the customer picks in the portal and
     * what the ad row stores; the sentence is what the copywriter is told to close on. Adding a
     * goal here is all the backend needs; the web dictionaries need a label for the key.
     *
     * No goal is a valid choice: then the copy closes on whatever action the subject itself
     * obviously offers.
     */
    public const GOALS = [
        'booking' => 'book an appointment, a table or a slot',
        'call' => 'call or write to the business today',
        'quote' => 'ask for a quote or a callback',
        'buy' => 'buy the product now',
        'signup' => 'sign up, register or start a trial',
        'visit' => 'go to the website and look around',
    ];

    private const COMMON = <<<'TXT'
        You are a direct response copywriter for paid social ads. You only write text. You never
        create, generate, draw or fetch anything. Your entire answer is one JSON object and nothing
        else: no greeting, no explanation, no code fence.

        {"scenes":[{"title":"...","text":"...","seconds":3.5,"zoom":"in","picture":"..."}]}

        The reader is scrolling and does not care yet. Write to that person, not about the company:
        what they get, not what the company is proud of.

        - Every scene sells one thing the reader gets: time saved, money saved, work gone, worry
          gone, customers won. A feature only ever appears as the reason a benefit is believable.
        - Be concrete. Use the numbers, prices, names, places and services the brief gives you.
          Invent nothing: no prices, no percentages, no guarantees, no customer quotes, no awards.
        - Never write a sentence that would fit a different company unchanged. Banned words:
          innovative, solutions, cutting edge, seamless, empower, digital transformation, next
          level, one-stop.
        - title: at most 6 words. text: one sentence, at most 18 words. Same language as the brief.
        - picture: an English sentence describing what an advertising photographer would shoot for
          this scene (subject, setting, light, mood). Show the customer or the product in real use,
          one clear subject, calm space in the lower third where the copy goes. No words, letters,
          logos or screens in that description: the copy is drawn on top afterwards, and a photo
          with writing in it comes out unreadable.
        - Plain sentences. No em dashes. At most one exclamation mark in the whole ad.
        TXT;

    private const VIDEO = <<<'TXT'
        You write very short marketing films that have to earn every second.

        - 4 scenes, in this order, and the order is the point:
          1. Hook: the reader's problem or the moment they want, in their own words. This scene
             decides whether the other three are watched. Never open with the company name.
          2. Turn: what changes for them. One benefit, not a list.
          3. Proof: the concrete reason to believe it. How it works, what it costs, how long it
             takes. Only what the brief actually says.
          4. Close: the call to action. title IS the action the reader should take now, text names
             the subject and where to do it. This scene has no picture.
        - seconds between 2.5 and 4. zoom is "in" or "out", alternating.
        TXT;

    private const STILL = <<<'TXT'
        You write single-frame ads, the kind that has to land while someone scrolls past.

        - EXACTLY ONE scene, and it must have a picture.
        - title is the hook: the reader's problem or the promise. Not the company name.
        - text gives the benefit and ends with the call to action in the same sentence.
        - send seconds and zoom anyway; they are ignored for this kind.
        TXT;

    /**
     * @param  array<string,string>  $context  What is actually being advertised: the project it
     *                                         belongs to, and the real page if the brief named one.
     * @param  string|null  $goal  A key of self::GOALS: the action the ad has to produce.
     * @return array<int,array<string,mixed>>
     */
    public function write(string $prompt, string $language = 'de', string $kind = 'video', array $context = [], ?string $goal = null): array
    {
        $system = ($kind === 'image' ? self::STILL : self::VIDEO)."\n\n".self::COMMON;

        if (isset(self::GOALS[(string) $goal])) {
            $system .= "\n\nThe ad has one job: make the reader ".self::GOALS[(string) $goal]
                .'. Write the whole ad towards that one action, and say it plainly at the end.';
        }

        if ($context !== []) {
            $lines = [];
            foreach ($context as $k => $v) {
                $lines[] = ucfirst(str_replace('_', ' ', $k)).': '.$v;
            }
            $prompt = "What this ad is for:\n".implode("\n", $lines)."\n\nBrief: ".$prompt
                ."\n\nWrite about the SUBJECT above and nothing else. `Filed under project` is only"
                .' where the ad is stored; never write about that instead. Sell what the subject'
                .' sells, in the subject\'s own words and its own field of work: pick the one'
                .' customer it is for, the one thing that customer wants, and the one action they'
                .' can take afterwards. No generic technology phrasing, and let the picture show'
                .' that customer getting what they came for.';
        }

        // Two attempts: the agent occasionally answers conversationally on the first go, and a
        // blunter reminder is cheaper than failing the whole render.
        foreach ([$prompt, $prompt."\n\nReturn the JSON object only. Do not do anything else."] as $attempt) {
            $scenes = $this->parseScenes($this->ask($system, $language, $attempt));
            if ($scenes !== []) {
                return $kind === 'image' ? $scenes : $this->closeOnCta($scenes);
            }
        }

        throw new RuntimeException('AI không trả về kịch bản dùng được.');
    }

    /**
     * The last scene of a film is the call to action, and make-ad.py paints it on the background
     * colour. The model still sends a picture for it now and then, and keeping that would pay for
     * one more generated photo and hide the action behind a stock image.
     *
     * @param  array<int,array<string,mixed>>  $scenes
     * @return array<int,array<string,mixed>>
     */
    private function closeOnCta(array $scenes): array
    {
        $last = count($scenes) - 1;
        if ($last >= 1) {
            $scenes[$last]['picture'] = '';
        }

        return $scenes;
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
                    'content' => "Write every title and text in this language: {$language}.\n\n{$brief}\n\n"
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
