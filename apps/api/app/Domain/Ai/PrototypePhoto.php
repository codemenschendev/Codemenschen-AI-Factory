<?php

namespace App\Domain\Ai;

use App\Domain\Library\ImageLibrary;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Puts real photographs into a generated app prototype.
 *
 * The model marks a slot with one of the classes below and writes inside it what the picture would
 * show. That line is already a photo brief in the visitor's own language and trade, so it is what
 * gets searched for, and the slot it stood in becomes the picture.
 *
 * Free pictures only. A prototype is a lead magnet given away by the hundred, so it borrows what
 * the world already photographed or it goes without: the shared library first, then Pexels, then
 * the accent gradient the slot already had. Generation belongs to the paid ad pipeline, where a
 * picture must show the one scene its copy names and no stock index holds that.
 *
 * Nothing here can fail a build. No key, no imagemagick, a timeout, an empty search: the slot
 * keeps its gradient, which is a deliberate design and not a missing image.
 */
class PrototypePhoto
{
    /** Prototypes borrow from each other and from nobody else: they are throwaway lead magnets. */
    private const PROJECT = 'prototype';

    /**
     * The slots a picture can fill, and how wide to encode each.
     *
     * Sized to what is drawn rather than to the biggest one: a 58px thumbnail encoded at 720px
     * would be ten times the bytes for the same pixels on screen. The band is the widest thing on
     * the screen, a card cover is most of a card, a thumbnail is a stamp.
     */
    private const SLOTS = ['app-art' => 720, 'app-cover' => 420, 'app-thumb' => 180];

    /**
     * Six across the whole app.
     *
     * Four was one too few for the first menu that used it: a band, three dishes and a fourth
     * row left holding its own brief. A list people choose by looking has three or four things in
     * it, and each of these is bytes in a page served on every view, so the ceiling stays low.
     */
    private const MAX = 6;

    public function __construct(
        private readonly ImageLibrary $library,
        private readonly StockPhotos $stock,
    ) {}

    /**
     * @return array{html:string,photo:?string,photos:list<string>,source:?string,
     *               sources:list<string>,credit:?string,credit_url:?string,credits:list<string>}
     */
    public function apply(string $html): array
    {
        $photos = $sources = $credits = $urls = [];

        foreach (self::SLOTS as $class => $width) {
            preg_match_all('~<(\w+) class="'.$class.'"[^>]*>(.*?)</\1>~is', $html, $all, PREG_SET_ORDER);

            foreach ($all as $m) {
                if (count($photos) >= self::MAX) {
                    break 2;
                }

                $brief = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($brief === '' || mb_strlen($brief) > 300) {
                    continue;
                }

                $found = $this->dataUri($brief, $width);
                if ($found === null) {
                    continue;   // this slot keeps its gradient; the next one still gets a try
                }

                $alt = htmlspecialchars($brief, ENT_QUOTES, 'UTF-8');
                $html = str_replace($m[0],
                    '<'.$m[1].' class="'.$class.' has-photo"><img src="'.$found['data'].'" alt="'.$alt.'"></'.$m[1].'>',
                    $html);

                $photos[] = $brief;
                $sources[] = $found['source'];
                if ($found['credit'] !== null) {
                    $credits[] = $found['credit'];
                    $urls[] = $found['url'];
                }
            }
        }

        if ($photos === []) {
            return $this->nothing($html);
        }

        return [
            'html' => $html,
            'photo' => $photos[0],
            'photos' => $photos,
            // Which sources answered, so a report can tell reuse from a fresh fetch.
            'source' => $sources[0],
            'sources' => $sources,
            'credit' => $credits[0] ?? null,
            'credit_url' => $urls[0] ?? null,
            'credits' => array_values(array_unique($credits)),
        ];
    }

    /** @return array<string,mixed> */
    private function nothing(string $html): array
    {
        return ['html' => $html, 'photo' => null, 'photos' => [], 'source' => null, 'sources' => [],
            'credit' => null, 'credit_url' => null, 'credits' => []];
    }

    /**
     * The picture, from the two sources that cost nothing.
     *
     * The library holds what earlier prototypes already fetched; Pexels is free, instant, and for
     * a bakery or a salon a real photograph beats a generated one anyway. Neither answering means
     * no picture, which is a decision rather than a failure.
     *
     * @return array{data:string,source:string,credit:?string,url:?string}|null
     */
    private function dataUri(string $brief, int $width): ?array
    {
        try {
            $hit = $this->library->find($brief, self::PROJECT);
            if ($hit !== null) {
                $path = $this->library->path($hit['id']);
                $uri = $path === null ? null : $this->encode($path, $width);
                if ($uri !== null) {
                    return ['data' => $uri, 'source' => 'library', 'credit' => null, 'url' => null];
                }
            }

            $shot = $this->stock->find($brief);
            if ($shot !== null) {
                $found = $this->file($shot['bytes'], '.jpg', $brief, $width);
                if ($found !== null) {
                    return ['data' => $found, 'source' => 'stock', 'credit' => $shot['credit'], 'url' => $shot['url']];
                }
            }

            Log::info('prototype photo: nothing free matched, the slot keeps its gradient', ['brief' => $brief]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('prototype photo skipped', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return null;
        }
    }

    /**
     * Encode bytes for the page and file them for the next prototype.
     *
     * Filed under one shared project key, so a similar brief next week finds this instead of
     * fetching it again. The file is kept at what was downloaded, not at the encoded width: a
     * thumbnail today may be a band tomorrow.
     */
    private function file(string $bytes, string $ext, string $brief, int $width): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'proto-photo-').$ext;
        file_put_contents($tmp, $bytes);

        try {
            $uri = $this->encode($tmp, $width);
            if ($uri !== null) {
                $this->library->remember($tmp, $brief, self::PROJECT);
            }

            return $uri;
        } finally {
            @unlink($tmp);
        }
    }

    /** Resize and re-encode to webp, then inline it. @return ?string */
    private function encode(string $path, int $width): ?string
    {
        $bin = collect(['/usr/bin/magick', '/usr/bin/convert', '/opt/homebrew/bin/magick'])
            ->first(fn (string $p) => is_executable($p));
        if ($bin === null || ! is_file($path)) {
            return null;
        }

        $out = tempnam(sys_get_temp_dir(), 'proto-photo-').'.webp';

        try {
            $proc = new Process([$bin, $path, '-resize', $width.'x>', '-quality', '78', '-strip', $out],
                null, null, null, 60);
            $proc->run();

            // Under a kilobyte is what a failed conversion leaves behind, not a photograph.
            if (! $proc->isSuccessful() || ! is_file($out) || filesize($out) < 1000) {
                return null;
            }

            return 'data:image/webp;base64,'.base64_encode((string) file_get_contents($out));
        } finally {
            @unlink($out);
        }
    }
}
