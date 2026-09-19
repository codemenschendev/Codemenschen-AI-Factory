<?php

namespace App\Domain\Ai;

use App\Jobs\BuildPrototype;
use App\Models\Prototype;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The campaign prototype: the ad, the landing page it links to and the e-mails after sign-up,
 * built from one message (step 1 of the funnel, owner's go 2026-09-19).
 *
 * One short call writes the message; then the three parts are ordinary prototypes of their own
 * kind, built side by side by the pipelines that already exist, each told what it is in the
 * campaign. The campaign itself holds no page. It is ready when every part has finished.
 */
class Campaign
{
    /** The parts in the order the visitor meets them: the ad, then the page, then the inbox. */
    public const PARTS = ['ads', 'site', 'email'];

    /** What each part is told about its place in the campaign, before the shared message. */
    private const ROLES = [
        'ads' => 'This ad is the start of a campaign: it sends people to a landing page where they sign up. Its call to action is that sign-up.',
        'site' => 'This website is the landing page of an ad campaign: people arrive from the ad and the page has one goal, the sign-up. '
            .'Keep it to one page: the promise, the reasons, proof from the business, and a sign-up form (an e-mail field and one button) '
            .'near the top and again at the end. The form sends nothing: on submit it shows a short thank-you in place of the form.',
        'email' => 'These e-mails go to the people who signed up on the landing page of an ad campaign. '
            .'The welcome e-mail thanks them for signing up and keeps the promise the ad made.',
    ];

    public function __construct(private readonly ProductPage $pages) {}

    /** Writes the message and starts the three parts. Throws only when nothing could be started. */
    public function start(Prototype $proto, ?\Closure $progress = null): void
    {
        $progress?->__invoke('message');
        $message = $this->message((string) $proto->prompt);
        $block = self::brief($message);

        $parts = [];
        foreach (self::PARTS as $kind) {
            $part = Prototype::create([
                'parent_id' => $proto->id,
                'status' => 'queued',
                'kind' => $kind,
                'prompt' => trim((string) $proto->prompt)."\n\n".self::ROLES[$kind].($block === '' ? '' : "\n\n".$block),
                'ip' => $proto->ip,
                'customer_id' => $proto->customer_id,
                'uploads' => $proto->uploads,
                'expires_at' => $proto->expires_at,
            ]);
            $parts[] = $part->id;
        }
        $proto->update(['status' => 'building', 'stage' => 'parts', 'title' => $message['promise'] ?? null,
            'qa' => ['message' => $message, 'parts' => $parts]]);
        foreach ($parts as $n => $id) {
            BuildPrototype::dispatch($id, self::PARTS[$n]);
        }
    }

    /**
     * Called whenever a part finishes, well or badly. The last one to finish settles the
     * campaign: ready when at least one part is there to look at, failed when none is.
     */
    public static function settle(?string $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        DB::transaction(function () use ($parentId) {
            $parent = Prototype::whereKey($parentId)->lockForUpdate()->first();
            if ($parent === null || $parent->status !== 'building') {
                return;
            }
            $parts = Prototype::where('parent_id', $parentId)->get(['id', 'kind', 'status', 'title']);
            if ($parts->contains(fn (Prototype $p) => in_array($p->status, ['queued', 'building'], true))) {
                return;
            }
            $ready = $parts->where('status', 'ready');
            if ($ready->isEmpty()) {
                $parent->update(['status' => 'failed', 'stage' => null, 'error' => 'every part of the campaign failed']);

                return;
            }
            $site = $ready->firstWhere('kind', 'site');
            $parent->update(['status' => 'ready', 'stage' => null,
                'title' => $parent->title ?: ($site?->title ?? $ready->first()->title)]);
        });
    }

    /**
     * The shared message. Read with the business's own website when the sentence names one, so
     * the promise is what is really sold. On any failure the parts still build, from the sentence.
     *
     * @return array<string,mixed>
     */
    public function message(string $sentence): array
    {
        $baseUrl = rtrim((string) config('services.ai_image.base_url'), '/');
        $token = (string) config('services.ai_image.token');
        if ($baseUrl === '' || $token === '') {
            return [];
        }
        $site = '';
        if (($domain = ProductPage::domainIn($sentence)) !== null && ($page = $this->pages->read($domain)) !== null) {
            $site = "\nTheir website, $domain, says:\n\"".mb_substr(trim((string) $page['text']), 0, 3000)."\"\n";
        }
        $prompt = Prompts::get('prototype/campaign', ['sentence' => mb_substr(trim($sentence), 0, 3000), 'site' => $site]);

        try {
            $request = Http::baseUrl($baseUrl)->withToken($token)->acceptJson()->timeout(90)->connectTimeout(10);
            if (($backend = ChatBackend::pin()) !== null) {
                $request = $request->withHeaders(['x-openclaw-model' => $backend]);
            }
            $res = $request->post('/v1/chat/completions', [
                'model' => config('services.ai_image.chat_model', 'openclaw/appwerk'),
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'max_completion_tokens' => 600,
            ]);
            if (! $res->successful()) {
                Log::info('campaign message: call failed', ['status' => $res->status()]);

                return [];
            }

            return self::parse((string) $res->json('choices.0.message.content'));
        } catch (\Throwable $e) {
            Log::info('campaign message: skipped', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return [];
        }
    }

    /** @return array<string,mixed> only the known fields, trimmed, never longer than a line */
    public static function parse(string $text): array
    {
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $text, $m) === 1) {
            $text = $m[1];
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start === false || $end === false ? null : json_decode(substr($text, $start, $end - $start + 1), true);
        if (! is_array($data)) {
            return [];
        }
        $line = fn ($v) => is_string($v) ? mb_substr(trim($v), 0, 200) : '';
        $out = [];
        foreach (['audience', 'promise', 'action', 'offer', 'tone'] as $key) {
            if (($v = $line($data[$key] ?? null)) !== '') {
                $out[$key] = $v;
            }
        }
        $reasons = array_values(array_filter(array_map($line, is_array($data['reasons'] ?? null) ? $data['reasons'] : [])));
        if ($reasons !== []) {
            $out['reasons'] = array_slice($reasons, 0, 3);
        }

        return isset($out['promise']) ? $out : [];
    }

    /** The message as the lines every part reads. Empty when there is no message. */
    public static function brief(array $message): string
    {
        if (! isset($message['promise'])) {
            return '';
        }
        $lines = ['The campaign message. The ad, the landing page and the e-mails all say this, in the language of their own part:'];
        foreach (['audience' => 'Who it is for', 'promise' => 'The promise', 'action' => 'What people do', 'offer' => 'Why now', 'tone' => 'Tone'] as $key => $label) {
            if (isset($message[$key])) {
                $lines[] = "$label: {$message[$key]}";
            }
        }
        if (isset($message['reasons'])) {
            $lines[] = 'Reasons: '.implode('; ', $message['reasons']);
        }

        return implode("\n", $lines);
    }

    /** How many free changes the whole campaign has used: the parts share the allowance. */
    public static function revisionsUsed(Prototype $proto): int
    {
        return $proto->parent_id === null ? (int) $proto->revisions
            : (int) Prototype::where('parent_id', $proto->parent_id)->sum('revisions');
    }
}
