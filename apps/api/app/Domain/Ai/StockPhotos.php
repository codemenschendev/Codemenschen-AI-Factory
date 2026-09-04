<?php

namespace App\Domain\Ai;

use Illuminate\Support\Facades\Http;

/**
 * Free photographs for a prototype, from Pexels.
 *
 * A prototype is a lead magnet: it is thrown away in a week and it costs us money to make. A real
 * photograph of a real bakery is free here, arrives in a second rather than a minute, and has no
 * six-fingered hands or invented shop signs in it. Generation stays for the paid ad pipeline,
 * where the picture has to show one particular scene the copy names.
 *
 * Attribution is not optional. Pexels asks for a visible credit when their API is used, so the
 * photographer and the source travel back with the bytes and the share page prints them under the
 * phone, outside the mockup, where a credit belongs.
 *
 * Unconfigured is the normal state, not an error: with no key this returns null and the caller
 * falls through to whatever it did before.
 */
class StockPhotos
{
    /** Take one of the first few rather than the best match: two salons must not get one photo. */
    private const POOL = 12;

    public function configured(): bool
    {
        return (string) config('services.stock.pexels_key') !== '';
    }

    /**
     * @return array{bytes:string,credit:string,url:string}|null
     */
    public function find(string $brief, string $locale = 'de-DE'): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $res = Http::withHeaders(['Authorization' => (string) config('services.stock.pexels_key')])
                ->timeout(20)->connectTimeout(8)
                ->get('https://api.pexels.com/v1/search', [
                    'query' => $this->query($brief),
                    'per_page' => self::POOL,
                    'orientation' => 'landscape',
                    'locale' => $locale,
                ]);

            $photos = $res->successful() ? ($res->json('photos') ?? []) : [];
            if ($photos === []) {
                return null;
            }

            $photo = $photos[array_rand($photos)];
            // `large` is 940px wide, which is already more than the band needs; the caller
            // downscales again before inlining.
            $src = $photo['src']['large'] ?? ($photo['src']['medium'] ?? null);
            if (! is_string($src)) {
                return null;
            }

            $bytes = Http::timeout(25)->get($src);

            return $bytes->successful() && strlen($bytes->body()) > 5000
                ? [
                    'bytes' => $bytes->body(),
                    'credit' => trim((string) ($photo['photographer'] ?? '')) ?: 'Pexels',
                    'url' => (string) ($photo['url'] ?? 'https://www.pexels.com'),
                ]
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The photo brief, cut down to what a stock search can actually match.
     *
     * The model writes a sentence for a photographer: "Frische Kipferl und Mohnzopf im Weidenkorb,
     * warmes Morgenlicht, Schaufenster Linzer Gasse Salzburg". A stock index has nothing filed
     * under a street in Salzburg, so the search keeps the first clause and drops the lighting and
     * the address, which are direction rather than subject.
     */
    private function query(string $brief): string
    {
        $first = trim(explode(',', $brief)[0]);
        $words = preg_split('/\s+/', $first) ?: [];

        return mb_substr(implode(' ', array_slice($words, 0, 6)), 0, 80);
    }
}
