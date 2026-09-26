<?php

namespace App\Domain\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A prototype drawn by Codex as ONE picture (owner's switch, admin panel, 2026-09-25), the way
 * ChatGPT answers "redesign this site as a mockup": Claude turns the customer's sentence and their
 * website into a design brief (prompts/prototype/mockup.md), Codex draws the whole prototype from
 * it with the business's own logo and pictures attached, and the page only frames that picture.
 * No house rules, no audit. A change request sends the current picture back with the change.
 */
class CodexPage
{
    /** The image tool draws 3:2, 2:3 or 1:1: a homepage and an e-mail are tall, app screens side by side are wide. */
    private const SIZES = ['site' => '1024x1536', 'email' => '1024x1536', 'app' => '1536x1024'];

    /** One render is one to three minutes on the subscription. */
    private const TIMEOUT = 600;

    public function __construct(private readonly ImageService $images) {}

    public static function ready(): bool
    {
        return (string) config('services.ai_image.codex_url') !== '' && (string) config('services.ai_image.codex_token') !== '';
    }

    /**
     * @param  list<string>  $refs  the business's own logo and pictures, as bytes
     * @return array{html:string,brief:string}
     */
    public function draw(string $prompt, string $kind, ?array $site, ?string $product, array $refs): array
    {
        $brief = $this->brief($prompt, $kind, $site, $product);

        return ['html' => $this->render($brief, $kind, array_slice($refs, 0, 4)), 'brief' => $brief];
    }

    /** Claude's design brief for the drawing, from the sentence and what the website says. */
    private function brief(string $prompt, string $kind, ?array $site, ?string $product): string
    {
        [$what, $sections, $presentation] = match ($kind) {
            'app' => ['app design, its main screens', 'the three or four main screens of the app, each with its content',
                'the screens side by side as phone screens on a plain light background. No annotations, no labels around them.'],
            'email' => ['e-mail design', 'the blocks of the e-mail body and its footer',
                'the e-mail on a plain light background, no mail program around it. No annotations.'],
            default => ['website design, the homepage', 'three to five sections that suit this business, then the footer',
                'the whole homepage from top to bottom as on a laptop screen, crisp and legible, on a plain light background. No browser frame, no annotations.'],
        };
        $user = "The customer's request:\n".trim($prompt);
        if ($site !== null) {
            $user .= "\n\n".Prompts::get('prototype/product', [
                'url' => $site['url'],
                'brief' => $product ?? '(no brief could be written; read the website text below yourself)',
                'page' => mb_substr((string) $site['text'], 0, 2500),
            ]);
        }
        return self::ask(Prompts::get('prototype/mockup', [
            'what' => $what, 'sections' => $sections, 'presentation' => $presentation,
            'language' => $site !== null ? 'the language of the website' : 'the language of the request',
        ]), $user);
    }

    /**
     * Claude's brief for an ad creative (owner's decision 2026-09-26): the ads of the Codex mode are
     * drawn from it instead of from the customer's raw sentence. The LANGUAGE rule travels along,
     * so the brief puts the ad's words in the language the ad speaks.
     */
    public static function adBrief(string $prompt, ?string $product, ?array $site, string $size, string $languageRule): string
    {
        [$w, $h] = array_map('intval', explode('x', $size));
        $format = match (true) {
            $w === $h => "square, {$size} px, for the Instagram and Facebook feed",
            $w > $h => "wide, {$size} px, a link or display banner",
            default => "tall, {$size} px, an Instagram or Facebook story",
        };
        $user = "The customer's request:\n".trim($prompt);
        if ($site !== null) {
            $user .= "\n\n".Prompts::get('prototype/product', [
                'url' => $site['url'],
                'brief' => $product ?? '(no brief could be written; read the website text below yourself)',
                'page' => mb_substr((string) $site['text'], 0, 2500),
            ]);
        }

        return self::ask(Prompts::get('prototype/ad-brief', ['format' => $format, 'language' => 'the language the LANGUAGE line names']),
            $user."\n\n".$languageRule);
    }

    /** One answer from Claude on the tool-less chat agent. */
    private static function ask(string $system, string $user): string
    {
        $request = Http::baseUrl(rtrim((string) config('services.ai_image.base_url'), '/'))
            ->withToken((string) config('services.ai_image.token'))->acceptJson()->timeout(300)->connectTimeout(10);
        if (($backend = ChatBackend::pin()) !== null) {
            $request = $request->withHeaders(['x-openclaw-model' => $backend]);
        }
        $res = $request->post('/v1/chat/completions', [
            'model' => config('services.ai_image.chat_model', 'openclaw/appwerk'),
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            'max_completion_tokens' => 1500,
        ]);
        $brief = trim((string) $res->json('choices.0.message.content'));
        if (! $res->successful() || $brief === '') {
            throw new RuntimeException('no design brief (gateway http '.$res->status().')');
        }

        return $brief;
    }

    /** The same prototype with the customer's change drawn in. */
    public function revise(string $html, string $kind, string $change): string
    {
        if (preg_match('~<img class="mockup" src="data:[^;]+;base64,([^"]+)"~', $html, $m) !== 1) {
            throw new RuntimeException('The prototype holds no picture to change.');
        }

        return $this->render(trim($change), $kind, [(string) base64_decode($m[1])]);
    }

    /** @param  list<string>  $refs */
    private function render(string $prompt, string $kind, array $refs): string
    {
        $size = self::SIZES[$kind] ?? self::SIZES['site'];
        $job = ['prompt' => $prompt, 'size' => $size, 'refs' => $refs, 'mockup' => $kind];
        $res = Http::pool(fn ($pool) => [$this->images->codexOn($pool, $job, 'mockup', self::TIMEOUT)]);
        $bytes = $this->images->codexBytes($res['mockup'] ?? null);
        if ($bytes === null) {
            throw new RuntimeException('The image agent drew no prototype.');
        }
        // PNG as Codex drew it: the small words of a design stay sharp, and a few MB is fine here.
        $mime = (@getimagesizefromstring($bytes)['mime'] ?? null) ?: 'image/png';

        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Prototyp</title><style>'
            .'body{margin:0;background:#eef0f4}main{padding:24px 16px;display:flex;justify-content:center}'
            .'.mockup{display:block;width:100%;max-width:'.explode('x', $size)[0].'px;height:auto;border-radius:12px;box-shadow:0 18px 40px rgba(0,0,0,.16)}'
            .'</style></head><body><main><img class="mockup" src="data:'.$mime.';base64,'.base64_encode($bytes).'" alt=""></main></body></html>';
    }
}
