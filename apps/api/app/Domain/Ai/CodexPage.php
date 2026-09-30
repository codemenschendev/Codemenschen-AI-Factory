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

        return ['html' => $this->render($brief, $kind, array_slice($refs, 0, 4), $kind === 'app'), 'brief' => $brief];
    }

    /** Claude's design brief for the drawing, from the sentence and what the website says. */
    private function brief(string $prompt, string $kind, ?array $site, ?string $product): string
    {
        [$what, $sections, $presentation] = match ($kind) {
            'app' => ['app design, its main screens', 'the three or four main screens of the app, each with its content',
                'the screens side by side as flat screen images, each shaped like a phone screen (tall, about 9 by 19, softly rounded corners), '
                .'on a plain flat mid grey background (#8e939b) with wide empty gaps between them. Only what the screen shows: no phone body, no bezel, no notch, '
                .'no hands, no shadows, no reflections, no annotations, no labels around them.'],
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

        // A picture drawn with phones around its screens (before 2026-09-30) keeps them when changed.
        return $this->render(trim($change), $kind, [(string) base64_decode($m[1])], $kind === 'app' && self::flat($html));
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
    public function screens(string $bytes, bool $flat = false): array
    {
        // Without GD the picture is shown whole, as before; a missing extension must not fail a build.
        $img = function_exists('imagecreatefromstring') ? @imagecreatefromstring($bytes) : false;
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
            $boxes[] = [(int) round($r['x'] * $w), (int) round($r['y'] * $h), (int) round(($r['x'] + $r['w']) * $w), (int) round(($r['y'] + $r['h']) * $h)];
        }
        // Rows first (by the middle of each box), then left to right within a row.
        usort($boxes, fn ($a, $b) => [(int) (($a[1] + $a[3]) / 2 / ($h / 3)), $a[0]] <=> [(int) (($b[1] + $b[3]) / 2 / ($h / 3)), $b[0]]);
        // Phones drawn side by side stand a few pixels apart, with shadows. Claude's edges are
        // rough, so a padded box reached into the next phone (2026-09-29). Between two neighbours
        // the cut goes through the emptiest column near where they meet, and each box is only
        // tightened inside that.
        $padX = (int) round($w * 0.03);
        $padY = (int) round($h * 0.04);
        $limits = [];
        foreach ($boxes as $i => [$x0, $y0, $x1, $y1]) {
            $limits[$i] = [max(0, $x0 - $padX), max(0, $y0 - $padY), min($w, $x1 + $padX), min($h, $y1 + $padY)];
        }
        for ($i = 0; $i < count($boxes) - 1; $i++) {
            [$a, $b] = [$boxes[$i], $boxes[$i + 1]];
            if (abs(($a[1] + $a[3]) - ($b[1] + $b[3])) / 2 > $h / 4) {
                continue;
            }
            // Anywhere between the two middles: Claude's edges can be 40 px off, and a column
            // through a phone always has more detail than the gap.
            $from = max(0, intdiv($a[0] + $a[2], 2));
            $to = min($w - 1, intdiv($b[0] + $b[2], 2));
            // Measured from above the phones to below them: a column through a phone then crosses
            // its top and bottom edge, even when the phone itself is one flat colour.
            $cut = self::emptiest($img, $bg, $from, $to, max(0, min($a[1], $b[1]) - $padY), min($h, max($a[3], $b[3]) + $padY));
            $limits[$i][2] = min($limits[$i][2], $cut);
            $limits[$i + 1][0] = max($limits[$i + 1][0], $cut + 1);
        }
        $out = [];
        foreach ($limits as [$x0, $y0, $x1, $y1]) {
            $box = self::tighten($img, $bg, $x0, $y0, $x1, $y1);
            if ($box !== null && $box[2] >= $w * 0.08 && $box[3] >= $h * 0.3) {
                // A silver phone edge is as smooth as the background and gets trimmed with it:
                // a few pixels back, never past the cut between two phones. A flat screen has no
                // edge to lose, and a margin would show as a grey ring inside its frame.
                $m = $flat ? 0 : 8;
                $bx0 = max($x0, $box[0] - $m);
                $by0 = max($y0, $box[1] - $m);
                $out[] = [$bx0, $by0, min($x1, $box[0] + $box[2] + $m) - $bx0, min($y1, $box[1] + $box[3] + $m) - $by0];
            }
        }
        $boxes = $out;

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

    /** Brightness of one pixel, 0 to 255. */
    private static function lum(\GdImage $img, int $x, int $y): float
    {
        $c = imagecolorat($img, $x, $y);

        return 0.3 * (($c >> 16) & 255) + 0.59 * (($c >> 8) & 255) + 0.11 * ($c & 255);
    }

    /**
     * The column between two screens with the least detail. A drawing's background is often a
     * soft gradient with shadows under the phones (2026-09-29: a car rental app), so "looks like
     * the corners" cut in the wrong place; a gap is where nothing changes from pixel to pixel.
     */
    private static function emptiest(\GdImage $img, array $bg, int $from, int $to, int $y0, int $y1): int
    {
        $energy = [];
        for ($x = $from; $x <= $to; $x++) {
            [$n, $prev] = [0.0, null];
            for ($y = $y0; $y < $y1; $y += 3) {
                $l = self::lum($img, $x, $y);
                $n += $prev === null ? 0 : abs($l - $prev);
                $prev = $l;
            }
            $energy[$x] = $n;
        }
        if ($energy === []) {
            return intdiv($from + $to, 2);
        }
        // The middle of the quietest run, not its first column: that one touches a phone's edge.
        $least = min($energy);
        [$run, $best] = [[], []];
        foreach ($energy as $x => $n) {
            if ($n <= $least + 2) {
                $run[] = $x;
                $best = count($run) > count($best) ? $run : $best;
            } else {
                $run = [];
            }
        }

        return $best[intdiv(count($best), 2)];
    }

    /**
     * The box shrunk until each edge touches something: a line is background while it is smooth
     * and about as bright as the box's outer edge on that side, which is background by
     * construction. Smooth alone would also eat a flat dark phone; "like the corners" alone
     * failed on a gradient.
     */
    private static function tighten(\GdImage $img, array $bg, int $x0, int $y0, int $x1, int $y1): ?array
    {
        $line = function (int $fixed, int $from, int $to, bool $row) use ($img): array {
            [$lo, $hi, $prev, $sum, $n, $jump] = [255.0, 0.0, null, 0.0, 0, 0.0];
            for ($i = $from; $i < $to; $i += 2) {
                $l = $row ? self::lum($img, $i, $fixed) : self::lum($img, $fixed, $i);
                $jump = $prev === null ? $jump : max($jump, abs($l - $prev));
                [$lo, $hi, $prev, $sum, $n] = [min($lo, $l), max($hi, $l), $l, $sum + $l, $n + 1];
            }

            return [$n > 0 ? $sum / $n : 0.0, $jump <= 10 && $hi - $lo < 36];
        };
        // The corners' brightness: an outer edge that is far from it lies on a phone already (Claude
        // drew the box too small), and that side is left as it is.
        $back = 0.3 * $bg[0] + 0.59 * $bg[1] + 0.11 * $bg[2];
        $plain = function (int $fixed, int $from, int $to, bool $row, float $ref) use ($line, $back): bool {
            [$mean, $smooth] = $line($fixed, $from, $to, $row);

            return abs($ref - $back) < 40 && $smooth && abs($mean - $ref) < 30;
        };
        $ref = $line($y0, $x0, $x1, true)[0];
        while ($y0 < $y1 - 1 && $plain($y0, $x0, $x1, true, $ref)) {
            $y0++;
        }
        $ref = $line($y1 - 1, $x0, $x1, true)[0];
        while ($y1 - 1 > $y0 && $plain($y1 - 1, $x0, $x1, true, $ref)) {
            $y1--;
        }
        $ref = $line($x0, $y0, $y1, false)[0];
        while ($x0 < $x1 - 1 && $plain($x0, $y0, $y1, false, $ref)) {
            $x0++;
        }
        $ref = $line($x1 - 1, $y0, $y1, false)[0];
        while ($x1 - 1 > $x0 && $plain($x1 - 1, $y0, $y1, false, $ref)) {
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

    /** Whether a drawn page holds flat app screens, which the page puts into its own phone frame. */
    public static function flat(string $html): bool
    {
        return str_contains($html, ' data-flat="1"');
    }

    /**
     * The page around a drawn prototype; an app's screens are shown one by one when known.
     *
     * `$flat`: the drawing has only the screens, no phones (2026-09-30). Every phone Codex drew
     * looked different, with shadows and gradients the cut tripped over, so the page now puts each
     * screen into one phone frame of its own, the same for every app.
     */
    public function page(string $bytes, string $kind, bool $flat = false): string
    {
        $size = self::SIZES[$kind] ?? self::SIZES['site'];
        $mime = (@getimagesizefromstring($bytes)['mime'] ?? null) ?: 'image/png';
        $boxes = $kind === 'app' ? $this->screens($bytes, $flat) : [];
        $split = $boxes === [] ? '' : ' data-screens="'.htmlspecialchars((string) json_encode($boxes), ENT_QUOTES).'"';
        $framed = $flat && $boxes !== [];
        // The cut screens stand on the drawing's own background, so no lighter tile shows around them.
        // Framed screens stand in their phones on the light page: their grey only told them apart.
        $page = '#eef0f4';
        if ($boxes !== [] && ! $framed && ($img = @imagecreatefromstring($bytes)) !== false) {
            $page = vsprintf('#%02x%02x%02x', self::background($img, imagesx($img), imagesy($img)));
        }
        // The screens are cut from the one picture in the browser, so its bytes are in the page once.
        $script = $boxes === [] ? '' : '<div class="screens"></div><script>(function(){var m=document.querySelector(".mockup"),b=JSON.parse(m.getAttribute("data-screens")||"[]");'
            .'function go(){var box=document.querySelector(".screens");b.forEach(function(r){var c=document.createElement("canvas");c.width=r[2];c.height=r[3];'
            .'c.getContext("2d").drawImage(m,r[0],r[1],r[2],r[3],0,0,r[2],r[3]);var i=new Image();i.alt="";i.src=c.toDataURL("image/png");'
            .'if(m.hasAttribute("data-flat")){var p=document.createElement("div");p.className="phone";p.appendChild(i);box.appendChild(p);}else box.appendChild(i);});'
            .'document.body.className="split";}if(m.complete&&m.naturalWidth)go();else m.addEventListener("load",go);})();</script>';

        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Prototyp</title><style>'
            .'body{margin:0;background:'.$page.'}main{padding:24px 16px;display:flex;justify-content:center}'
            .'.mockup{display:block;width:100%;max-width:'.explode('x', $size)[0].'px;height:auto;border-radius:12px;box-shadow:0 18px 40px rgba(0,0,0,.16)}'
            .'body.split .mockup{display:none}.screens{display:flex;flex-wrap:wrap;gap:28px;justify-content:center;padding:28px 16px}'
            .'.screens img{display:block;width:min(360px,86vw);height:auto}'
            // The phone: a dark body with an even bezel and a pill at the top, over the screen's own corners.
            .'.phone{position:relative;padding:12px;border-radius:52px;background:#111317;box-shadow:0 0 0 2px #2c2f36,0 24px 50px rgba(15,23,42,.22)}'
            .'.phone:before{content:"";position:absolute;top:22px;left:50%;width:96px;height:26px;margin-left:-48px;border-radius:14px;background:#111317;z-index:1}'
            .'.phone img{width:min(336px,78vw);border-radius:40px}'
            .'</style></head><body><main><img class="mockup" src="data:'.$mime.';base64,'.base64_encode($bytes).'"'.$split.($framed ? ' data-flat="1"' : '').' alt=""></main>'.$script.'</body></html>';
    }

    /** @param  list<string>  $refs */
    private function render(string $prompt, string $kind, array $refs, bool $flat = false): string
    {
        $size = self::SIZES[$kind] ?? self::SIZES['site'];
        $job = ['prompt' => $prompt, 'size' => $size, 'refs' => $refs, 'mockup' => $kind] + ($flat ? ['flat' => true] : []);
        $res = Http::pool(fn ($pool) => [$this->images->codexOn($pool, $job, 'mockup', self::TIMEOUT)]);
        $bytes = $this->images->codexBytes($res['mockup'] ?? null);
        if ($bytes === null) {
            throw new RuntimeException('The image agent drew no prototype.');
        }
        // PNG as Codex drew it: the small words of a design stay sharp, and a few MB is fine here.
        return $this->page($bytes, $kind, $flat);
    }
}
