<?php

namespace App\Domain\Ai;

use Illuminate\Http\Client\Response;
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
    /**
     * `$search`, when the model wrote one, is a few English nouns naming the subject: "laptop
     * advent wreath". It beats anything cut down from the German sentence by machine, because a
     * stock index is filed by nouns and the sentence is written for a photographer. "Tischlerin
     * steht abends allein in ihrer" found a woman in a dark alley; "carpenter workshop phone"
     * finds a carpenter.
     *
     * @return array{bytes:string,credit:string,url:string}|null
     */
    public function find(string $brief, string $locale = 'de-DE', ?string $search = null): ?array
    {
        return $this->findMany([['brief' => $brief, 'search' => $search, 'locale' => $locale]])[0] ?? null;
    }

    /**
     * Several at once: every search in one round trip, then every download in one.
     *
     * A page carries up to six photographs and each one used to cost a search and a download in
     * turn, which is a visitor watching a spinner for the sum of twelve round trips to Pexels.
     * Pooled, it is the slowest one.
     *
     * @param  list<array{brief:string,search?:?string,locale?:string}>  $wants
     * @return array<int,array{bytes:string,credit:string,url:string}|null> same keys as $wants
     */
    public function findMany(array $wants): array
    {
        $out = array_fill_keys(array_keys($wants), null);
        if ($wants === [] || ! $this->configured()) {
            return $out;
        }

        try {
            $key = (string) config('services.stock.pexels_key');
            $searches = Http::pool(function ($pool) use ($wants, $key) {
                foreach ($wants as $i => $w) {
                    $search = trim((string) ($w['search'] ?? ''));
                    $pool->as((string) $i)->withHeaders(['Authorization' => $key])
                        ->timeout(20)->connectTimeout(8)
                        ->get('https://api.pexels.com/v1/search', [
                            'query' => $search !== '' ? mb_substr($search, 0, 80) : $this->query($w['brief']),
                            'per_page' => self::POOL,
                            'orientation' => 'landscape',
                            'locale' => $search !== '' ? 'en-US' : ($w['locale'] ?? 'de-DE'),
                        ]);
                }
            });

            $picked = [];
            foreach ($wants as $i => $_) {
                $res = $searches[(string) $i] ?? null;
                $photos = $res instanceof Response && $res->successful()
                    ? ($res->json('photos') ?? []) : [];
                if ($photos === []) {
                    continue;
                }
                $photo = $photos[array_rand($photos)];
                // `large` is 940px wide, which is already more than the band needs; the caller
                // downscales again before inlining.
                $src = $photo['src']['large'] ?? ($photo['src']['medium'] ?? null);
                if (is_string($src)) {
                    $picked[$i] = ['src' => $src, 'photo' => $photo];
                }
            }
            if ($picked === []) {
                return $out;
            }

            $downloads = Http::pool(function ($pool) use ($picked) {
                foreach ($picked as $i => $p) {
                    $pool->as((string) $i)->timeout(25)->get($p['src']);
                }
            });

            foreach ($picked as $i => $p) {
                $bytes = $downloads[(string) $i] ?? null;
                if (! $bytes instanceof Response || ! $bytes->successful() || strlen($bytes->body()) <= 5000) {
                    continue;
                }
                $out[$i] = [
                    'bytes' => $bytes->body(),
                    'credit' => trim((string) ($p['photo']['photographer'] ?? '')) ?: 'Pexels',
                    'url' => (string) ($p['photo']['url'] ?? 'https://www.pexels.com'),
                ];
            }
        } catch (\Throwable) {
            // A pool that failed as a whole is a page without photographs, not a failed build.
        }

        return $out;
    }

    /**
     * Words that put a recognisable person in the picture, in the two languages the copywriter
     * writes. Not exhaustive and not meant to be: it errs towards generating, which is the safe
     * direction.
     */
    private const PEOPLE = [
        // No "Hand": a pair of hands kneading dough identifies nobody, and the release this
        // guards against is about recognisable people. It also sits at the front of
        // "handgehobelt", which is a plank.
        'frau', 'mann', 'männ', 'kund', 'kind', 'team', 'mitarbeiter', 'person', 'leute',
        'gesicht', 'läch', 'familie', 'paar', 'besitzer', 'inhaber', 'chef',
        'meister', 'friseur', 'bäcker', 'ärzt', 'arzt', 'trainer', 'gast', 'gäste', 'porträt',
        'portrait', 'woman', 'women', 'customer', 'people', 'staff', 'smil', 'family', 'owner',
        'face', 'guest',
    ];

    /**
     * A photograph for a PAID advertisement, or null to generate one instead.
     *
     * Same free source, one rule on top. The Pexels licence allows commercial use, but it does not
     * guarantee a model release, and it asks that the people in a photo are not made to look like
     * they endorse the product. A stranger's face in a paid Meta ad for a salon is exactly that
     * use, so any scene that names a person is generated rather than borrowed.
     *
     * Places, rooms, food, tools and products carry no such claim, and those are most scenes.
     *
     * @return array{bytes:string,credit:string,url:string}|null
     */
    public function findForAd(string $brief): ?array
    {
        // Matched at the start of a word, never mid-word: German compounds put "hand" inside
        // "Behandlungsstuhl", and a treatment chair is a chair. The boundary is written as "not
        // preceded by a letter" because \b is ASCII-minded about ä and ö.
        $pattern = '/(?<!\p{L})('.implode('|', self::PEOPLE).')/ui';
        if (preg_match($pattern, $brief) === 1) {
            return null;
        }

        return $this->find($brief);
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
