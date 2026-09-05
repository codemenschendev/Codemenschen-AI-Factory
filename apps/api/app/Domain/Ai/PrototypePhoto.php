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
    private const SLOTS = [
        // What a free prototype writes. Named for what they are rather than for where they started.
        'photo-wide' => 720, 'photo-card' => 420, 'photo-thumb' => 180,
        // What the house stylesheet calls the same three. Kept so pages built before the change,
        // and the ad prototype which still uses it, keep their pictures.
        'app-art' => 720, 'app-cover' => 420, 'app-thumb' => 180,
    ];

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
        $photos = $sources = $credits = $urls = $empty = [];

        foreach (self::SLOTS as $class => $width) {
            // The slot name among whatever else the model put in the class attribute. Requiring
            // the attribute to be exactly the slot name meant class="photo-thumb avatar" never
            // matched, and a free page styles every slot, so almost none of them did.
            $pattern = '~<(\w+)([^>]*\sclass="[^"]*\b'.preg_quote($class, '~').'\b[^"]*"[^>]*)>(.*?)</\1>~is';
            preg_match_all($pattern, $html, $all, PREG_SET_ORDER);

            foreach ($all as $m) {
                if (count($photos) >= self::MAX) {
                    break 2;
                }

                // A slot holds a sentence and nothing else. When it holds elements, the model has
                // wrapped a whole card in the class instead of putting a picture inside one, and
                // flattening that gives a "brief" like "Praterstern to Hauptbahnhof, yesterday,
                // 9,40 euro", which no stock library has and which must not be emptied either.
                if (str_contains($m[3], '<')) {
                    continue;
                }

                $brief = trim(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($brief === '' || mb_strlen($brief) > 300) {
                    continue;
                }

                $empty[] = [$m, $class];

                $found = $this->dataUri($brief, $width);
                if ($found === null) {
                    continue;   // this slot keeps its gradient; the next one still gets a try
                }

                // The model's own attributes are kept, so a slot it styled keeps its styling and
                // only gains the marker class and the picture.
                $alt = htmlspecialchars($brief, ENT_QUOTES, 'UTF-8');
                $open = preg_replace('~(\sclass=")~', '$1has-photo ', $m[2], 1);
                $html = str_replace($m[0],
                    '<'.$m[1].$open.'><img src="'.$found['data'].'" alt="'.$alt.'"></'.$m[1].'>',
                    $html);

                array_pop($empty);
                $photos[] = $brief;
                $sources[] = $found['source'];
                if ($found['credit'] !== null) {
                    $credits[] = $found['credit'];
                    $urls[] = $found['url'];
                }
            }
        }

        // A thumbnail nobody could fill keeps its shape and loses its words: six words of
        // direction for a photographer, crammed into a 58px square, read as a bug. A wide band
        // is big enough for a line of text and keeps its caption.
        foreach ($empty as [$m, $class]) {
            if (! str_ends_with($class, 'thumb')) {
                continue;
            }
            $html = str_replace($m[0], '<'.$m[1].$m[2].'></'.$m[1].'>', $html);
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
