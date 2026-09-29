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

    /**
     * What the account list calls a prototype drawn as a picture, which has no <title> of its own:
     * the website's name, else its address, else the customer's first sentence. "Prototyp" nine
     * times over told the customer nothing (2026-09-26).
     */
    public static function title(string $prompt, ?array $site): string
    {
        $name = trim((string) ($site['name'] ?? ''));
        if ($name !== '') {
            // "Küstenpatent Kroatien | Boat Skipper B ..." keeps its first part.
            $name = trim((string) preg_split('~\s+[|\x{2013}\x{2014}-]\s+~u', $name, 2)[0]);
        }
        if ($name === '' && isset($site['url'])) {
            $name = preg_replace('~^www\.~', '', (string) parse_url((string) $site['url'], PHP_URL_HOST)) ?? '';
        }
        if ($name === '') {
            $name = trim((string) preg_split('~(?<=[.!?])\s|\n~u', trim($prompt), 2)[0]);
            $name = rtrim($name, '.!? ');
        }
        if ($name === '') {
            return 'Prototyp';
        }

        return mb_strlen($name) > 70 ? rtrim(mb_substr($name, 0, 67)).'…' : $name;
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

    /**
     * An app drawing is its screens side by side in one wide picture, and framed as one picture
     * each screen came out a quarter of the width, too small to read (2026-09-29). Claude looks at
     * the picture and says where each screen is; the boxes are then pulled tight to the pixels and
     * the page shows every screen as its own picture. The picture itself stays in the page as it
     * was drawn: a change request and the site built after a purchase both start from it.
     *
     * @return list<array{0:int,1:int,2:int,3:int}> x, y, width, height in pixels, left to right
     */
    public function screens(string $bytes): array
    {
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return [];
        }
        [$w, $h] = [imagesx($img), imagesy($img)];
        // A smaller copy for Claude: the boxes come back as fractions, so the size does not matter
        // to the answer, only to the upload.
        $small = imagescale($img, min(1200, $w));
        ob_start();
        imagejpeg($small, null, 82);
        $jpeg = (string) ob_get_clean();
        try {
            $answer = self::look(Prompts::get('prototype/mockup-screens'), 'data:image/jpeg;base64,'.base64_encode($jpeg));
        } catch (\Throwable) {
            return [];
        }
        $rows = json_decode(preg_match('~\[.*\]~s', $answer, $m) === 1 ? $m[0] : 'null', true);
        if (! is_array($rows) || count($rows) < 2 || count($rows) > 8) {
            return [];
        }
        $bg = self::background($img, $w, $h);
        $boxes = [];
        foreach ($rows as $r) {
            if (! is_array($r) || ! isset($r['x'], $r['y'], $r['w'], $r['h'])) {
                return [];
            }
            // A little room around Claude's box, then trimmed back to where the screen starts.
            $pad = 0.02;
            $x0 = max(0, (int) floor(($r['x'] - $pad) * $w));
            $y0 = max(0, (int) floor(($r['y'] - $pad) * $h));
            $x1 = min($w, (int) ceil(($r['x'] + $r['w'] + $pad) * $w));
            $y1 = min($h, (int) ceil(($r['y'] + $r['h'] + $pad) * $h));
            $box = self::tighten($img, $bg, $x0, $y0, $x1, $y1);
            if ($box !== null && $box[2] >= $w * 0.08 && $box[3] >= $h * 0.3) {
                $boxes[] = $box;
            }
        }
        usort($boxes, fn ($a, $b) => [$a[1] > $b[1] + $h / 3, $a[0]] <=> [$b[1] > $a[1] + $h / 3, $b[0]]);

        return count($boxes) >= 2 ? $boxes : [];
    }

    /** The colour the screens stand on: the corners of the picture, averaged. */
    private static function background(\GdImage $img, int $w, int $h): array
    {
        $sum = [0, 0, 0];
        foreach ([[2, 2], [$w - 3, 2], [2, $h - 3], [$w - 3, $h - 3]] as [$x, $y]) {
            $c = imagecolorat($img, $x, $y);
            $sum[0] += ($c >> 16) & 255;
            $sum[1] += ($c >> 8) & 255;
            $sum[2] += $c & 255;
        }

        return array_map(fn ($v) => (int) round($v / 4), $sum);
    }

    /** The box shrunk until each edge touches something that is not the background. */
    private static function tighten(\GdImage $img, array $bg, int $x0, int $y0, int $x1, int $y1): ?array
    {
        $plain = function (int $fixed, int $from, int $to, bool $row) use ($img, $bg): bool {
            for ($i = $from; $i < $to; $i += 2) {
                $c = $row ? imagecolorat($img, $i, $fixed) : imagecolorat($img, $fixed, $i);
                if (abs((($c >> 16) & 255) - $bg[0]) + abs((($c >> 8) & 255) - $bg[1]) + abs(($c & 255) - $bg[2]) > 36) {
                    return false;
                }
            }

            return true;
        };
        while ($y0 < $y1 - 1 && $plain($y0, $x0, $x1, true)) {
            $y0++;
        }
        while ($y1 - 1 > $y0 && $plain($y1 - 1, $x0, $x1, true)) {
            $y1--;
        }
        while ($x0 < $x1 - 1 && $plain($x0, $y0, $y1, false)) {
            $x0++;
        }
        while ($x1 - 1 > $x0 && $plain($x1 - 1, $y0, $y1, false)) {
            $x1--;
        }

        return $x1 - $x0 > 10 && $y1 - $y0 > 10 ? [$x0, $y0, $x1 - $x0, $y1 - $y0] : null;
    }

    /** One question about one picture, to Claude on the chat agent (it reads pictures). */
    private static function look(string $question, string $dataUrl): string
    {
        $request = Http::baseUrl(rtrim((string) config('services.ai_image.base_url'), '/'))
            ->withToken((string) config('services.ai_image.token'))->acceptJson()->timeout(180)->connectTimeout(10);
        if (($backend = ChatBackend::pin()) !== null) {
            $request = $request->withHeaders(['x-openclaw-model' => $backend]);
        }
        $res = $request->post('/v1/chat/completions', [
            'model' => config('services.ai_image.chat_model', 'openclaw/appwerk'),
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $question],
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
            ]]],
            'max_completion_tokens' => 600,
        ]);
        if (! $res->successful()) {
            throw new RuntimeException('no answer about the screens (gateway http '.$res->status().')');
        }

        return (string) $res->json('choices.0.message.content');
    }

    /** The page around a drawn prototype; an app's screens are shown one by one when known. */
    public function page(string $bytes, string $kind): string
    {
        $size = self::SIZES[$kind] ?? self::SIZES['site'];
        $mime = (@getimagesizefromstring($bytes)['mime'] ?? null) ?: 'image/png';
        $boxes = $kind === 'app' ? $this->screens($bytes) : [];
        $split = $boxes === [] ? '' : ' data-screens="'.htmlspecialchars((string) json_encode($boxes), ENT_QUOTES).'"';
        // The screens are cut from the one picture in the browser, so its bytes are in the page once.
        $script = $boxes === [] ? '' : '<div class="screens"></div><script>(function(){var m=document.querySelector(".mockup"),b=JSON.parse(m.getAttribute("data-screens")||"[]");'
            .'function go(){var box=document.querySelector(".screens");b.forEach(function(r){var c=document.createElement("canvas");c.width=r[2];c.height=r[3];'
            .'c.getContext("2d").drawImage(m,r[0],r[1],r[2],r[3],0,0,r[2],r[3]);var i=new Image();i.alt="";i.src=c.toDataURL("image/png");box.appendChild(i);});'
            .'document.body.className="split";}if(m.complete&&m.naturalWidth)go();else m.addEventListener("load",go);})();</script>';

        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Prototyp</title><style>'
            .'body{margin:0;background:#eef0f4}main{padding:24px 16px;display:flex;justify-content:center}'
            .'.mockup{display:block;width:100%;max-width:'.explode('x', $size)[0].'px;height:auto;border-radius:12px;box-shadow:0 18px 40px rgba(0,0,0,.16)}'
            .'body.split .mockup{display:none}.screens{display:flex;flex-wrap:wrap;gap:28px;justify-content:center;padding:28px 16px}'
            .'.screens img{display:block;width:min(360px,86vw);height:auto;border-radius:16px;box-shadow:0 18px 40px rgba(0,0,0,.14)}'
            .'</style></head><body><main><img class="mockup" src="data:'.$mime.';base64,'.base64_encode($bytes).'"'.$split.' alt=""></main>'.$script.'</body></html>';
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
        return $this->page($bytes, $kind);
    }
}
