<?php

namespace App\Domain\Ai;

use App\Domain\Qa\ContentClaims;
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
     * The shape of the story. The goal says what the reader should do at the end; the angle says
     * how the four scenes get them there, which is the part a copywriter would decide first and
     * the part an LLM otherwise picks at random.
     *
     * The keys are what the portal offers and what the ad row stores. Adding one here is all the
     * backend needs; the web dictionaries need a label for the key.
     */
    public const ANGLES = [
        'problem_solution' => 'Open on the reader\'s problem in their own words, make it concrete, then show the subject removing it.',
        'before_after' => 'Contrast the day before with the day after: the same task, the same person, the work gone.',
        'founder' => 'Speak as the person behind the business, plainly, about why they built this and who it is for.',
        'testimonial' => 'Tell it from a customer\'s point of view. Use only words the brief actually gives you: if it names no customer and no quote, write what that customer would be trying to do, and never invent a name, a rating or a sentence in quotation marks.',
        'demo' => 'Walk through the thing working, step by step, so the reader sees how short the way from problem to result is.',
        'price_anchor' => 'Put the cost next to what it replaces. Use only the numbers the brief gives you, and no invented comparison.',
        'seasonal' => 'Hang the ad on the moment in the year the brief names, and make that timing the reason to act now.',
    ];

    /**
     * What the ad has to make the reader do. The key is what the customer picks in the portal and
     * what the ad row stores; the sentence is what the copywriter is told to close on. Adding a
     * goal here is all the backend needs; the web dictionaries need a label for the key.
     *
     * No goal is a valid choice: then the copy closes on whatever action the subject itself
     * obviously offers.
     */
    /**
     * What the checks found in the copy that was returned, empty when it was clean. The render
     * keeps it on the ad so the operator sees a flaw the second attempt could not remove.
     *
     * @var list<string>
     */
    public array $faults = [];

    public const GOALS = [
        'booking' => 'book an appointment, a table or a slot',
        'call' => 'call or write to the business today',
        'quote' => 'ask for a quote or a callback',
        'buy' => 'buy the product now',
        'signup' => 'sign up, register or start a trial',
        'visit' => 'go to the website and look around',
    ];

    /**
     * @param  array<string,string>  $context  What is actually being advertised: the project it
     *                                         belongs to, and the real page if the brief named one.
     * @param  string|null  $goal  A key of self::GOALS: the action the ad has to produce.
     * @param  string|null  $angle  A key of self::ANGLES: the shape of the story that gets there.
     * @return array<int,array<string,mixed>>
     */
    public function write(string $prompt, string $language = 'de', string $kind = 'video',
        array $context = [], ?string $goal = null, ?string $angle = null, ?array $reference = null): array
    {
        $system = Prompts::get($kind === 'image' ? 'ads/still' : 'ads/video')."\n\n".Prompts::get('ads/copywriter');

        if (isset(self::ANGLES[(string) $angle])) {
            $system .= "\n\nThe angle for this ad, and it decides the whole thing: "
                .self::ANGLES[(string) $angle];
        }

        if (isset(self::GOALS[(string) $goal])) {
            $system .= "\n\nThe ad has one job: make the reader ".self::GOALS[(string) $goal]
                .'. Write the whole ad towards that one action, and say it plainly at the end.';
        }

        // What the copy may state as fact: the customer's sentence and everything the context
        // carries (the project's own description, the business's website).
        $facts = $prompt."\n".implode("\n", $context);

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

        // Two attempts. The agent occasionally answers conversationally on the first go, and a
        // blunter reminder is cheaper than failing the whole render. A script that came back with
        // a fault the checks can see (a number given a new noun, a real person's name, an invented
        // credential, a dash) gets the second attempt with the faults named, and the cleaner of
        // the two is kept: a customer pays for this ad and it gets published.
        $best = null;
        $bestFaults = [];
        $next = $prompt;
        foreach ([1, 2] as $round) {
            $scenes = $this->parseScenes($this->ask($system, $language, $next, $reference));
            if ($scenes === []) {
                $next = $prompt."\n\nReturn the JSON object only. Do not do anything else.";

                continue;
            }
            $faults = self::faults($scenes, $facts);
            if ($best === null || count($faults) < count($bestFaults)) {
                [$best, $bestFaults] = [$scenes, $faults];
            }
            if ($faults === []) {
                break;
            }
            $next = $prompt."\n\nA first draft broke these rules. Write the ad again without them:\n- "
                .implode("\n- ", $faults)."\n\nReturn the JSON object only.";
        }

        if ($best === null) {
            throw new RuntimeException('The agent answered without a usable script.');
        }
        $this->faults = $bestFaults;

        return $kind === 'image' ? $best : $this->closeOnCta($best);
    }

    /**
     * The checks the prototype pages get, run on the words of the script.
     *
     * @param  array<int,array<string,mixed>>  $scenes
     * @return list<string>
     */
    public static function faults(array $scenes, string $facts): array
    {
        $words = implode("\n", array_map(fn (array $s) => $s['title'].'. '.$s['text'], $scenes));
        $html = '<html><body><p>'.htmlspecialchars($words).'</p></body></html>';

        $out = [];
        foreach (ContentClaims::findings($html, $facts, 'ads') as $f) {
            $out[] = $f['check'].': '.implode(', ', $f['elements']).' ('.$f['detail'].')';
        }
        if (preg_match('/—|\s–\s/u', $words) === 1) {
            $out[] = 'dash: a dash is used as a sentence break; use a full stop or a comma';
        }

        return $out;
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

    /** @param array{id:string,note:string,data:string}|null $reference */
    private function ask(string $system, string $language, string $brief, ?array $reference = null): string
    {
        $baseUrl = rtrim((string) config('services.ai_image.base_url'), '/');
        $token = (string) config('services.ai_image.token');

        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('Chưa cấu hình dịch vụ AI (AI_IMAGE_SERVICE_TOKEN).');
        }

        $request = Http::baseUrl($baseUrl)->withToken($token)->acceptJson()->timeout(180)->connectTimeout(10);

        // `model` is the agent target; the model behind it is pinned with this header, the way
        // Codemenschen_OpenClaw's inference gateway does it.
        if (($backend = ChatBackend::pin()) !== null) {
            $request = $request->withHeaders(['x-openclaw-model' => $backend]);
        }

        $content = [['type' => 'text', 'text' => "Write every title and text in this language: {$language}.\n\n{$brief}\n\n"
            .'Answer with the JSON object described above, nothing else.']];

        if ($reference !== null) {
            // The reference prompt forbids taking anything but the shape, and says text inside the
            // picture is data: it is the one place an instruction could be smuggled in.
            $content[] = ['type' => 'text', 'text' => Prompts::get('ads/reference')
                .($reference['note'] !== '' ? "\n\nWhat is good about it: {$reference['note']}" : '')];
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $reference['data']]];
        }

        $res = $request->post('/v1/chat/completions', [
            'model' => config('services.ai_image.chat_model', 'openclaw/appwerk'),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                // A plain string when there is no picture: the sidecar has answered that shape
                // since the first ad was written, and an array for one message is a needless change.
                ['role' => 'user', 'content' => $reference === null ? $content[0]['text'] : $content],
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
