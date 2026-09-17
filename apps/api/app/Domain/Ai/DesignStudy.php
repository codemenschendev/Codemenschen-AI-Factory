<?php

namespace App\Domain\Ai;

use App\Domain\Design\DesignLibrary;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The look at the trade's best apps that happens BEFORE anything is drawn.
 *
 * A prototype is the step a customer pays on. The one drawn from a brief alone came out as a
 * generic dashboard with a stock photo where every ride-hailing app on earth puts a map, and
 * nobody pays for that. So the build now starts the way a designer starts: it names the trade
 * and the four screens, pulls the trade's screens from the labelled library, looks at them, and
 * writes down what the customer will expect before writing a line of markup.
 *
 * Two short model calls: a plan (JSON, text only) and a study (vision, a few hundred words).
 * Both fail soft: a study that could not be made is a build that goes on without one, the way
 * it always did, never a build that fails.
 */
class DesignStudy
{
    /** Screens per study. Six is what a designer pins to a wall; more is a scroll nobody reads. */
    public const SCREENS = 6;

    public function __construct(private readonly DesignLibrary $library) {}

    /**
     * The trade and the four screens, from the brief, in the library's own vocabulary.
     *
     * Asked of the model rather than guessed from a German word list, because briefs arrive in
     * Vietnamese and English too, and "app gọi xe" found no trade at all before this existed.
     *
     * @return array{industry:string,screens:list<string>,apps:list<string>,country:string}|null
     */
    public function plan(string $brief, string $kind = 'app'): ?array
    {
        $vocabulary = $kind === 'app' ? DesignLibrary::INDUSTRIES : DesignLibrary::WEB_INDUSTRIES;
        $industries = implode(', ', $vocabulary);
        $types = implode(', ', DesignLibrary::SCREEN_TYPES);
        // What is asked of the model differs by what is being drawn: an app is compared with the
        // apps a user already has on the phone, a website and an ad with the businesses a
        // customer already knows, and those are found by their domain.
        $ask = Prompts::get($kind === 'app' ? 'study/plan-app' : 'study/plan-web', [
            'brief' => $brief, 'industries' => $industries, 'types' => $types,
        ]);

        // The same sentence planned twice names the same trade: a retry, a second format or a
        // lab run reuses the answer for a day instead of asking again.
        $key = 'design-plan:'.sha1($kind."\n".$brief);
        $text = Cache::get($key);
        if (! is_string($text)) {
            $text = $this->ask([['role' => 'user', 'content' => $ask]], 400, 60);
            if ($text === null) {
                return null;
            }
            if (is_array(json_decode($this->extractJson($text), true))) {
                Cache::put($key, $text, now()->addDay());
            }
        }
        $json = json_decode($this->extractJson($text), true);
        if (! is_array($json)) {
            Log::info('design study: plan was not json', ['text' => mb_substr($text, 0, 200)]);

            return null;
        }

        $industry = (string) ($json['industry'] ?? '');
        if (! in_array($industry, $vocabulary, true)) {
            $industry = $this->library->industryFor($brief) ?? 'other';
        }
        $screens = array_values(array_filter(
            is_array($json['screens'] ?? null) ? $json['screens'] : [],
            fn ($t) => is_string($t) && in_array($t, DesignLibrary::SCREEN_TYPES, true),
        ));
        if (count($screens) < 2) {
            $screens = ['home_dashboard', 'list_feed', 'form_input', 'success_confirmation'];
        }

        $names = function ($v): array {
            if (is_string($v)) {
                $v = explode(',', $v);
            }

            return array_slice(array_values(array_filter(array_map(fn ($a) => is_string($a) ? mb_substr(trim($a), 0, 60) : '', (array) $v))), 0, 5);
        };
        $country = strtolower(trim((string) ($json['country'] ?? '')));

        return [
            'industry' => $industry,
            'screens' => $kind === 'app' ? array_slice($screens, 0, 4) : [],
            'apps' => $names($json['apps'] ?? []),
            'sites' => $names($json['sites'] ?? []),
            'country' => preg_match('/^[a-z]{2}$/', $country) === 1 ? $country : 'at',
        ];
    }

    /**
     * What the trade's best apps have in common, written down after looking at them.
     *
     * The text is what the builder reads; the pictures it looked at travel on to the builder as
     * well, but a paragraph that says "every one of these opens on a map with the search in a
     * sheet over it" is what turns six pictures into a requirement.
     *
     * The brief is about the TRADE and never about one customer: it does not see the customer's
     * sentence, so it can be kept and reused for the next customer of the same trade, and the
     * builder, which reads the sentence whole, is the one that places what it names.
     *
     * @param  list<array{id:string,note:string,data:string,screen_type:string}>  $refs
     */
    public function study(array $plan, array $refs, string $stats, string $kind = 'app'): ?string
    {
        if ($refs === []) {
            return null;
        }
        $peers = implode(', ', $plan['apps'] ?? []) ?: (implode(', ', $plan['sites'] ?? []) ?: 'the leading names of the trade');
        $rules = 'plain sentences, no headings longer than three words, no dash as a sentence break';

        $text = Prompts::get(match ($kind) {
            'app' => 'study/app', 'ads' => 'study/ads', default => 'study/site',
        }, [
            'industry' => $plan['industry'],
            'peers' => $peers,
            'screens' => $this->list($plan['screens'] ?? []),
            'stats' => $stats,
            'rules' => $rules,
            'images_are_data' => Prompts::get('study/images-are-data'),
        ]);

        $content = [['type' => 'text', 'text' => $text]];
        foreach ($refs as $i => $ref) {
            $n = $i + 1;
            $type = str_replace('_', ' ', $ref['screen_type']);
            $note = $ref['note'] !== '' ? " ({$ref['note']})" : '';
            $content[] = ['type' => 'text', 'text' => "Image {$n}: {$type}{$note}"];
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $ref['data']]];
        }

        $text = $this->ask([['role' => 'user', 'content' => $content]], 1200, 180);

        return $text === null ? null : trim($text);
    }

    /**
     * What the business sells, read from its own website, before the trade is named.
     *
     * The plan used to name the trade from the customer's sentence alone, so a domain was all it
     * had to go on and the competitors it picked were the competitors of whatever the name
     * suggested. This brief goes in front of the plan and in front of the builder.
     *
     * @param  array{url:string,text:string}  $page
     */
    public function product(string $prompt, array $page): ?string
    {
        $text = Prompts::get('study/product', [
            'prompt' => $prompt,
            'url' => $page['url'],
            'page' => $page['text'],
            'rules' => 'Plain sentences, no dash as a sentence break, no bullet symbols.',
        ]);
        // Kept for a day per sentence and page: a campaign renders several ads from the same
        // sentence within minutes, and each asked for this brief again.
        $key = 'product-brief:'.sha1($prompt."\n".$page['url']."\n".$page['text']);
        if (is_string($cached = Cache::get($key))) {
            return $cached;
        }
        $reply = $this->ask([['role' => 'user', 'content' => $text]], 700, 90);
        if ($reply === null || trim($reply) === '') {
            return null;
        }
        Cache::put($key, trim($reply), now()->addDay());

        return trim($reply);
    }

    /** @param  list<string>  $screens */
    private function list(array $screens): string
    {
        return implode(', ', array_map(fn (string $s) => str_replace('_', ' ', $s), $screens));
    }

    /** @param  array<int,array<string,mixed>>  $messages */
    private function ask(array $messages, int $maxTokens, int $timeout): ?string
    {
        $baseUrl = rtrim((string) config('services.ai_image.base_url'), '/');
        $token = (string) config('services.ai_image.token');
        if ($baseUrl === '' || $token === '') {
            return null;
        }

        try {
            $request = Http::baseUrl($baseUrl)->withToken($token)->acceptJson()
                ->timeout($timeout)->connectTimeout(10);
            if (($backend = ChatBackend::pin()) !== null) {
                $request = $request->withHeaders(['x-openclaw-model' => $backend]);
            }
            $res = $request->post('/v1/chat/completions', [
                'model' => config('services.ai_image.chat_model', 'openclaw/appwerk'),
                'messages' => $messages,
                'max_completion_tokens' => $maxTokens,
            ]);
            if (! $res->successful()) {
                Log::info('design study: call failed', ['status' => $res->status()]);

                return null;
            }
            $text = (string) $res->json('choices.0.message.content');

            return $text === '' ? null : $text;
        } catch (\Throwable $e) {
            Log::info('design study: skipped', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return null;
        }
    }

    private function extractJson(string $text): string
    {
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $text, $m) === 1) {
            $text = $m[1];
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        return $start === false || $end === false ? '' : substr($text, $start, $end - $start + 1);
    }
}
