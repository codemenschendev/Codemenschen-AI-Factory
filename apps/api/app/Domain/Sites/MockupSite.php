<?php

namespace App\Domain\Sites;

use App\Domain\Ai\ChatBackend;
use App\Domain\Ai\ImageService;
use App\Domain\Ai\Prompts;
use App\Domain\Qa\PageAudit;
use App\Models\Prototype;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A bought prototype that is still a picture becomes the website (owner's decision, 2026-09-25).
 * The free prototype of the Codex switch is one design picture (CodexPage); once the customer has
 * paid, Claude builds the page from that picture, Codex renders each photograph of it on its own,
 * without words, and the photographs are laid into the page. The money is in, so this is where the
 * tokens are spent.
 */
class MockupSite
{
    private const SIZES = ['wide' => '1536x1024', 'tall' => '1024x1536', 'square' => '1024x1024'];

    private const SHOTS = 6;

    public function __construct(private readonly ImageService $images, private readonly ?PageAudit $audit = null) {}

    public static function isMockup(Prototype $prototype): bool
    {
        return ($prototype->qa['mockup'] ?? false) === true;
    }

    /** @return array{html:string,qa:array<string,mixed>} */
    public function build(Prototype $prototype): array
    {
        if (preg_match('~<img class="mockup" src="(data:image/[^;]+;base64,[^"]+)"~', (string) $prototype->html, $m) !== 1) {
            throw new RuntimeException('The bought prototype holds no design picture.');
        }
        $t0 = microtime(true);
        $html = $this->page($m[1]);
        $written = round(microtime(true) - $t0, 1);
        [$html, $shots, $failed] = $this->photos($html);

        $qa = ($this->audit?->run($html)) ?? ['ok' => null, 'findings' => []];
        $qa['writer'] = 'claude';
        $qa['mockup'] = false;
        $qa['from_mockup'] = [
            'brief' => $prototype->qa['brief'] ?? null,
            'shots' => $shots, 'shots_failed' => $failed,
            'seconds' => ['page' => $written, 'total' => round(microtime(true) - $t0, 1)],
            'at' => now()->toIso8601String(),
        ];

        return ['html' => $html, 'qa' => $qa];
    }

    /** Claude builds the page from the design picture. */
    private function page(string $picture): string
    {
        $request = Http::baseUrl(rtrim((string) config('services.ai_image.base_url'), '/'))
            ->withToken((string) config('services.ai_image.token'))->acceptJson()->timeout(630)->connectTimeout(10);
        if (($backend = ChatBackend::pin()) !== null) {
            $request = $request->withHeaders(['x-openclaw-model' => $backend]);
        }
        $res = $request->post('/v1/chat/completions', [
            'model' => config('services.ai_image.chat_model', 'openclaw/appwerk'),
            'messages' => [
                ['role' => 'system', 'content' => Prompts::get('prototype/from-mockup')."\n\n".Prompts::get('prototype/laws')],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => 'The approved design:'],
                    ['type' => 'image_url', 'image_url' => ['url' => $picture]],
                ]],
            ],
            'max_completion_tokens' => 16000,
        ]);
        if (! $res->successful()) {
            throw new RuntimeException('The gateway answered '.$res->status().' to the page.');
        }
        $reply = (string) $res->json('choices.0.message.content');
        if (preg_match('~(<!doctype html.*</html>|<html\b.*</html>)~is', $reply, $page) !== 1) {
            throw new RuntimeException('The page came back without HTML: "'.mb_substr(trim($reply), 0, 120).'"');
        }

        return trim($page[1]);
    }

    /**
     * Each slot's photograph, rendered by Codex from its description, all at once (the agent draws
     * two at a time), laid into the slot as a JPEG. A slot whose render failed keeps its own
     * background.
     *
     * @return array{0:string,1:int,2:int}
     */
    private function photos(string $html): array
    {
        preg_match_all('~<div\b[^>]*\bclass="[^"]*\bshot\b[^"]*"[^>]*>~i', $html, $tags);
        $jobs = [];
        foreach (array_slice($tags[0], 0, self::SHOTS) as $k => $tag) {
            preg_match('~\bdata-render="([^"]*)"~i', $tag, $what);
            preg_match('~\bdata-shape="(\w+)"~i', $tag, $shape);
            $jobs[$k] = ['prompt' => 'A premium, realistic photograph for a website: '.html_entity_decode($what[1] ?? 'a fitting photo', ENT_QUOTES),
                'size' => self::SIZES[strtolower($shape[1] ?? 'wide')] ?? self::SIZES['wide'], 'refs' => []];
        }
        if ($jobs === []) {
            return [$html, 0, 0];
        }
        $res = Http::pool(fn ($pool) => array_map(fn ($k) => $this->images->codexOn($pool, $jobs[$k], 'shot'.$k, 1200), array_keys($jobs)));
        $done = 0;
        foreach (array_keys($jobs) as $k) {
            $bytes = $this->images->codexBytes($res['shot'.$k] ?? null);
            if ($bytes === null) {
                continue;
            }
            $jpeg = self::jpeg($bytes);
            $mime = (@getimagesizefromstring($jpeg)['mime'] ?? null) ?: 'image/png';
            $tag = $tags[0][$k];
            $html = preg_replace('~'.preg_quote($tag, '~').'~', $tag.'<img src="data:'.$mime.';base64,'.base64_encode($jpeg).'" alt="">', $html, 1);
            $done++;
        }

        return [$html, $done, count($jobs) - $done];
    }

    /** A photograph as a JPEG at most 1600px wide: a live page with six PNGs would be 15 MB. */
    public static function jpeg(string $bytes): string
    {
        $bin = collect(['/usr/bin/magick', '/opt/homebrew/bin/magick'])->first(fn (string $p) => is_executable($p));
        if ($bin === null) {
            return $bytes;
        }
        $proc = new \Symfony\Component\Process\Process([$bin, '-', '-resize', '1600x1600>', '-quality', '82', 'jpeg:-'], null, null, $bytes, 60);
        $proc->run();
        $out = $proc->getOutput();

        return $proc->isSuccessful() && strlen($out) > 200 ? $out : $bytes;
    }
}
