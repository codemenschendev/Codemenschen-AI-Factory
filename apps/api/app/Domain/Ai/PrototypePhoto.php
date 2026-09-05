<?php

namespace App\Domain\Ai;

use App\Domain\Library\ImageLibrary;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Puts one real photograph into a generated app prototype.
 *
 * The model writes `<div class="app-art">what the picture would show</div>`, which is already a
 * photo brief in the visitor's own language and trade. That line is what gets searched for, and
 * the band it was standing in becomes the picture.
 *
 * Free pictures only. A prototype is a lead magnet given away by the hundred, so it borrows what
 * the world already photographed and never buys anything: the shared library first, then Pexels.
 * Generation belongs to the paid ad pipeline, where a picture must show the one scene its copy
 * names and no stock index holds that.
 *
 * Nothing here can fail a build. No key, no imagemagick, a timeout, an empty search: the band
 * keeps the flat accent gradient it already had, which is a deliberate design, not a missing
 * image.
 */
class PrototypePhoto
{
    /** Prototypes borrow from each other and from nobody else: they are throwaway lead magnets. */
    private const PROJECT = 'prototype';

    /** What the phone actually needs. A 1536px PNG inline would be two megabytes of base64. */
    private const WIDTH = 720;

    public function __construct(
        private readonly ImageLibrary $library,
        // Required, not nullable with a default: the container skips a nullable parameter that
        // already has one, so this silently arrived as null and every prototype paid for a photo
        // the free source would have given away.
        private readonly StockPhotos $stock,
    ) {}

    /**
     * @return array{html:string,photo:?string,source:?string,credit:?string,credit_url:?string}
     */
    public function apply(string $html): array
    {
        // The first band only. Later ones keep their gradient rather than costing another call.
        if (! preg_match('~<div class="app-art"[^>]*>(.*?)</div>~is', $html, $m)) {
            return $this->nothing($html);
        }

        $brief = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($brief === '' || mb_strlen($brief) > 300) {
            return $this->nothing($html);
        }

        $found = $this->dataUri($brief);
        if ($found === null) {
            return $this->nothing($html);
        }

        $alt = htmlspecialchars($brief, ENT_QUOTES, 'UTF-8');
        $img = '<div class="app-art has-photo"><img src="'.$found['data'].'" alt="'.$alt.'"></div>';

        return [
            'html' => str_replace($m[0], $img, $html),
            'photo' => $brief,
            // Which of the three answered. An empty credit used to mean "library or generated",
            // two things that differ by the price of a generation, and nobody could tell them
            // apart afterwards.
            'source' => $found['source'],
            'credit' => $found['credit'],
            'credit_url' => $found['url'],
        ];
    }

    /** @return array{html:string,photo:null,source:null,credit:null,credit_url:null} */
    private function nothing(string $html): array
    {
        return ['html' => $html, 'photo' => null, 'source' => null, 'credit' => null, 'credit_url' => null];
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
    private function dataUri(string $brief): ?array
    {
        try {
            $hit = $this->library->find($brief, self::PROJECT);
            if ($hit !== null) {
                $path = $this->library->path($hit['id']);
                $uri = $path === null ? null : $this->encode($path);
                if ($uri !== null) {
                    return ['data' => $uri, 'source' => 'library', 'credit' => null, 'url' => null];
                }
            }

            $shot = $this->stock->find($brief);
            if ($shot !== null) {
                $found = $this->file($shot['bytes'], '.jpg', $brief);
                if ($found !== null) {
                    return ['data' => $found, 'source' => 'stock', 'credit' => $shot['credit'], 'url' => $shot['url']];
                }
            }

            Log::info('prototype photo: nothing free matched, the band keeps its gradient', ['brief' => $brief]);

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
     * fetching or buying it again.
     */
    private function file(string $bytes, string $ext, string $brief): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'proto-photo-').$ext;
        file_put_contents($tmp, $bytes);

        try {
            $uri = $this->encode($tmp);
            if ($uri !== null) {
                $this->library->remember($tmp, $brief, self::PROJECT);
            }

            return $uri;
        } finally {
            @unlink($tmp);
        }
    }

    /** Resize and re-encode to webp, then inline it. @return ?string */
    private function encode(string $path): ?string
    {
        $bin = collect(['/usr/bin/magick', '/usr/bin/convert', '/opt/homebrew/bin/magick'])
            ->first(fn (string $p) => is_executable($p));
        if ($bin === null || ! is_file($path)) {
            return null;
        }

        $out = tempnam(sys_get_temp_dir(), 'proto-photo-').'.webp';

        try {
            $proc = new Process([
                $bin, $path,
                '-resize', self::WIDTH.'x>',
                '-quality', '78',
                '-strip',
                $out,
            ], null, null, null, 60);
            $proc->run();

            if (! $proc->isSuccessful() || ! is_file($out) || filesize($out) < 1000) {
                return null;
            }

            return 'data:image/webp;base64,'.base64_encode((string) file_get_contents($out));
        } finally {
            @unlink($out);
        }
    }
}
