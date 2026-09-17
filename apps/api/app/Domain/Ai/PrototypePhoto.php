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
     * @param  array{images?:list<array{url:string,alt:string}>,logo?:?string,fetch?:\Closure(string):?string}  $site
     *                                                                                                                  the business's own pictures from its website, and how to fetch one
     * @return array{html:string,photo:?string,photos:list<string>,source:?string,
     *               sources:list<string>,credit:?string,credit_url:?string,credits:list<string>}
     */
    public function apply(string $html, array $site = []): array
    {
        // First every slot worth filling, then every photograph at once, then the page. Reading
        // the slots and fetching for each in turn made six photographs cost six times the wait.
        $slots = [];
        // The three slot names, then any other "photo-something" the model made up. A Linz
        // bakery got <div class="photo-hero" data-q="fresh bread rolls basket steam">: the model
        // wrote the search phrase and the brief exactly as told and named the slot for where it
        // sat, and the hero shipped as a brown gradient with the brief printed across its top.
        // A slot the model invents is a wide one; it is where the biggest picture goes.
        $wanted = self::SLOTS + ['photo-(?!wide\b|card\b|thumb\b)[\w-]+' => 720];
        $seen = [];
        foreach ($wanted as $class => $width) {
            // The slot name among whatever else the model put in the class attribute. Requiring
            // the attribute to be exactly the slot name meant class="photo-thumb avatar" never
            // matched, and a free page styles every slot, so almost none of them did.
            $name = isset(self::SLOTS[$class]) ? preg_quote($class, '~') : $class;
            $pattern = '~<(\w+)([^>]*\sclass="[^"]*\b'.$name.'\b[^"]*"[^>]*)>(.*?)</\1>~is';
            preg_match_all($pattern, $html, $all, PREG_SET_ORDER);

            foreach ($all as $m) {
                if (count($slots) >= self::MAX) {
                    break 2;
                }
                // The catch-all must not take a slot a name already took, nor one without a
                // search phrase: "photo-grid" around four cards is a layout, not a picture.
                if (isset($seen[$m[0]]) || (! isset(self::SLOTS[$class]) && ! str_contains($m[2], 'data-q='))) {
                    continue;
                }
                $seen[$m[0]] = true;

                // A slot holds a sentence and nothing else. When it holds a card's worth of
                // elements, the model has wrapped a whole card in the class instead of putting a
                // picture inside one, and flattening that gives a "brief" like "Praterstern to
                // Hauptbahnhof, yesterday, 9,40 euro", which no stock library has and which must
                // not be emptied either. One wrapper around the sentence is still the sentence:
                // an ad page styled every brief as <span class="brief"> and got no pictures.
                if (! self::isBrief($m[3])) {
                    continue;
                }

                $brief = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($brief === '' || mb_strlen($brief) > 300) {
                    continue;
                }

                // The search phrase the model wrote for this slot, if it wrote one.
                $search = preg_match('~\sdata-q="([^"]*)"~i', $m[2], $q) === 1
                    ? trim(html_entity_decode($q[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                    : null;

                // The business's own picture the model chose for this slot, by its number in the list.
                $own = preg_match('~\sdata-site="(\d+)"~i', $m[2], $n) === 1 ? ($site['images'][(int) $n[1] - 1] ?? null) : null;

                $slots[] = ['m' => $m, 'width' => $width, 'brief' => $brief, 'search' => $search, 'own' => $own];
            }
        }

        // A slot the builder left unmarked takes one of the business's own pictures nobody used
        // yet, photographs first, before a stock search is tried.
        if (($site['fill'] ?? false) && ($site['images'] ?? []) !== []) {
            $used = array_column(array_filter(array_column($slots, 'own')), 'url');
            $spare = array_values(array_filter($site['images'], fn ($img) => ! in_array($img['url'], $used, true)));
            usort($spare, fn ($a, $b) => (['photo' => 0, 'graphic' => 1, 'screen' => 2][$a['kind'] ?? 'photo'] ?? 3)
                <=> (['photo' => 0, 'graphic' => 1, 'screen' => 2][$b['kind'] ?? 'photo'] ?? 3));
            foreach ($slots as $i => $slot) {
                if ($slot['own'] === null && $spare !== []) {
                    $slots[$i]['own'] = array_shift($spare);
                }
            }
        }

        $photos = $sources = $credits = $urls = [];
        $framed = false;
        $rendered = $this->rendered($html, $slots, $site);
        foreach (array_replace($this->resolve(array_map(
            fn ($slot, $i) => isset($rendered[$i]) ? ['own' => null] + $slot : $slot, $slots, array_keys($slots)),
            $site['fetch'] ?? null), $rendered) as $i => $found) {
            if ($found === null) {
                continue;   // this slot keeps its gradient
            }
            $m = $slots[$i]['m'];

            // The model's own attributes are kept, so a slot it styled keeps its styling and
            // only gains the marker class and the picture.
            $alt = htmlspecialchars($slots[$i]['brief'], ENT_QUOTES, 'UTF-8');
            // A screenshot or a graphic is shown whole in a frame; only a photograph is cropped.
            $fit = in_array($found['fit'] ?? null, ['screen', 'graphic'], true) ? ' is-'.$found['fit'] : '';
            $framed = $framed || $fit !== '';
            $open = preg_replace('~(\sclass=")~', '$1has-photo'.$fit.' ', $m[2], 1);
            $html = str_replace($m[0],
                '<'.$m[1].$open.'><img src="'.$found['data'].'" alt="'.$alt.'"></'.$m[1].'>',
                $html);

            $photos[] = $slots[$i]['brief'];
            $sources[] = $found['source'];
            if ($found['credit'] !== null) {
                $credits[] = $found['credit'];
                $urls[] = $found['url'];
            }
        }

        // A thumbnail nobody filled keeps its shape and loses its words: six words of direction
        // for a photographer, crammed into a 58px square, read as a bug. That covers the ones no
        // source could answer and the ones past the ceiling alike; a ride-hailing page wrote
        // eleven driver portraits and the five after the sixth photograph were left saying
        // "Porträt eines Fahrers mit Kappe". A wide band is big enough for a line of text and
        // keeps its caption.
        foreach (array_keys(self::SLOTS) as $class) {
            if (! str_ends_with($class, 'thumb')) {
                continue;
            }
            $pattern = '~<(\w+)([^>]*\sclass="[^"]*\b'.preg_quote($class, '~').'\b[^"]*"[^>]*)>(.*?)</\1>~is';
            $html = preg_replace_callback($pattern, fn (array $m) => self::isBrief($m[3]) && trim(strip_tags($m[3])) !== ''
                ? '<'.$m[1].$m[2].'></'.$m[1].'>'
                : $m[0], $html);
        }

        // The business's own logo where the model marked the wordmark. The name inside stays as
        // the alt text, and a logo that cannot be fetched leaves the name in type, as it was.
        $logoPlaced = false;
        $logo = null;
        if (($site['logo'] ?? null) !== null && isset($site['fetch'])
            && preg_match('~<(\w+)([^>]*\sclass="[^"]*\bsite-logo\b[^"]*"[^>]*)>(.*?)</\1>~is', $html) === 1
            && ($logo = $this->logo($site['logo'], $site['fetch'])) !== null) {
            // Only where the name stands on its own. Inside a sentence, "ads for [logo] (wp-giftcard.com)",
            // or next to more words, "[logo] Plugin", a logo reads as a sticker: the name stays text.
            $html = preg_replace_callback('~<(\w+)([^>]*\sclass="[^"]*\bsite-logo\b[^"]*"[^>]*)>(.*?)</\1>~is', function (array $m) use ($logo, $html): string {
                $name = trim(html_entity_decode(strip_tags($m[3][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $before = rtrim(substr($html, 0, $m[0][1]));
                $after = ltrim(substr($html, $m[0][1] + strlen($m[0][0])));
                if (($before !== '' && ! str_ends_with($before, '>')) || ($after !== '' && ! str_starts_with($after, '<'))) {
                    return htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                }

                return '<'.$m[1][0].$m[2][0].'><img src="'.$logo.'" alt="'.htmlspecialchars($name, ENT_QUOTES, 'UTF-8').'"></'.$m[1][0].'>';
            }, $html, -1, $count, PREG_OFFSET_CAPTURE) ?? $html;
            $logoPlaced = true;
        }
        // An ad's avatar is the business's logo, the way the platforms show a page, and the page
        // name beside it is text. The wp-giftcard.com ads drew the avatar as an empty gradient
        // circle next to the logo, which read as a crop of a photograph.
        if (($site['avatar'] ?? false) && ($site['logo'] ?? null) !== null && isset($site['fetch'])
            && preg_match('~\sclass="[^"]*\bavatar\b~i', $html) === 1
            && ($logo ??= $this->logo($site['logo'], $site['fetch'])) !== null
            // A wide wordmark shrinks to a dot in a circle: wp-giftcard.com's is 166 by 51. Then
            // the initials the builder wrote stay, which is what a page without a square mark shows.
            && self::aspect($logo) <= 1.5) {
            $html = preg_replace_callback('~<(\w+)([^>]*\sclass="[^"]*\bavatar\b[^"]*"[^>]*)>(.*?)</\1>~is',
                fn (array $m) => str_contains(strtolower($m[3]), '<img') ? $m[0]
                    : '<'.$m[1].(preg_replace('~(\sclass=")~', '$1has-logo ', $m[2], 1) ?? $m[2]).'><img src="'.$logo.'" alt=""></'.$m[1].'>',
                $html) ?? $html;
            // The name next to it is text again: the same logo twice in one row is a sticker.
            $html = preg_replace_callback('~<(\w+)[^>]*\sclass="[^"]*\bsite-logo\b[^"]*"[^>]*><img[^>]*\balt="([^"]*)"[^>]*></\1>~is',
                fn (array $m) => '<'.$m[1].' class="page-name">'.$m[2].'</'.$m[1].'>', $html) ?? $html;
            $css = '.avatar.has-logo{background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;padding:2px;color:transparent}'
                .'.avatar.has-logo>img{display:block;width:100%;height:100%;object-fit:contain}';
            $html = preg_replace('~</style>~i', $css.'</style>', $html, 1, $count) ?? $html;
            if ($count === 0) {
                $html = preg_replace('~</head>~i', '<style>'.$css.'</style></head>', $html, 1) ?? $html;
            }
        }
        if ($logoPlaced) {
            $css = '.site-logo{display:inline-flex;align-items:center}.site-logo>img{display:block;height:32px;width:auto;max-width:200px;background:#fff;border-radius:6px;padding:2px 6px}';
            $html = preg_replace('~</style>~i', $css.'</style>', $html, 1, $count) ?? $html;
            if ($count === 0) {
                $html = preg_replace('~</head>~i', '<style>'.$css.'</style></head>', $html, 1) ?? $html;
            }
        }

        if ($photos === []) {
            return $this->nothing($html);
        }

        // The photograph fills the shape the model drew, whatever the model wrote for <img>. The
        // prompt asks for width, height and object-fit and a page often forgets: a codemenschen.at
        // ad built its link creative at 1.91:1, then a 4:3 stock photo pushed it to 358x318 and
        // the audit failed a page that had been clean one step earlier. Last in the stylesheet so
        // it wins over the model's own rule of the same weight; padding is dropped because it framed the brief text, not the picture; min-height 0 keeps a box sized by
        // aspect-ratio from growing to the picture.
        $fit = '.has-photo{overflow:hidden;min-height:0;padding:0}.has-photo>img{display:block;width:100%;height:100%;object-fit:cover}';
        if ($framed) {
            $fit .= '.has-photo.is-screen,.has-photo.is-graphic{display:flex;align-items:center;justify-content:center;padding:7%;'
                .'background:radial-gradient(120% 90% at 30% 15%,#3d4454 0%,#171a21 72%)}'
                .'.has-photo.is-screen>img{width:100%;height:auto;max-height:100%;object-fit:contain;background:#fff;'
                .'border:5px solid #0e1015;border-top-width:12px;border-radius:9px;box-shadow:0 16px 36px rgba(0,0,0,.4)}'
                .'.has-photo.is-graphic>img{width:auto;height:auto;max-width:86%;max-height:86%;object-fit:contain;'
                .'transform:rotate(-3deg);border-radius:8px;box-shadow:0 16px 36px rgba(0,0,0,.4)}'
                // A story writes its words over the lower third. A voucher shown whole there sat
                // under the headline, so in a story it keeps to the top half.
                .'.ad-story .has-photo.is-screen,.ad-story .has-photo.is-graphic{align-items:flex-start;padding-top:22%}'
                .'.ad-story .has-photo.is-screen>img,.ad-story .has-photo.is-graphic>img{max-height:46%}';
        }
        $html = preg_replace('~</style>~i', $fit.'</style>', $html, 1, $count) ?? $html;
        if ($count === 0) {
            $html = preg_replace('~</head>~i', '<style>'.$fit.'</style></head>', $html, 1) ?? $html;
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
    /**
     * Is what is inside a slot a brief, or a card that happens to carry the class?
     *
     * A brief is text, or text in one wrapper. Two elements or more is a card, and a picture
     * already in there means the slot has been filled.
     */
    private static function isBrief(string $inner): bool
    {
        return ! str_contains(strtolower($inner), '<img') && preg_match_all('~<\w~', $inner) <= 1;
    }

    /**
     * A picture for each slot: the shared library first, which is local and instant, then one
     * pooled trip to Pexels for whatever the library did not have.
     *
     * @param  list<array{m:array,width:int,brief:string,search:?string}>  $slots
     * @return array<int,array{data:string,source:string,credit:?string,url:?string}|null>
     */
    private function resolve(array $slots, ?\Closure $fetch = null): array
    {
        $out = array_fill(0, count($slots), null);
        $wanted = [];

        foreach ($slots as $i => $slot) {
            // The business's own picture first: what the customer will recognise. Never filed in
            // the shared library, because it belongs to that business and to no other prototype.
            if ($slot['own'] !== null && $fetch !== null) {
                try {
                    $bytes = $fetch($slot['own']['url']);
                    $size = $bytes === null ? false : @getimagesizefromstring($bytes);
                    // A picture smaller than a card would be blown up into mush.
                    if ($size !== false && $size[0] >= 300 && $size[1] >= 160) {
                        $uri = $this->encodeBytes($bytes, $slot['width']);
                        if ($uri !== null) {
                            $out[$i] = ['data' => $uri, 'source' => 'site', 'credit' => null, 'url' => null, 'fit' => $slot['own']['kind'] ?? 'photo'];

                            continue;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::info('prototype photo: own picture skipped', ['error' => mb_substr($e->getMessage(), 0, 200)]);
                }
            }
            try {
                $hit = $this->library->find($slot['brief'], self::PROJECT);
                $path = $hit === null ? null : $this->library->path($hit['id']);
                $uri = $path === null ? null : $this->encode($path, $slot['width']);
                if ($uri !== null) {
                    $out[$i] = ['data' => $uri, 'source' => 'library', 'credit' => null, 'url' => null];

                    continue;
                }
            } catch (\Throwable $e) {
                Log::warning('prototype photo: library skipped', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            }
            $wanted[$i] = ['brief' => $slot['brief'], 'search' => $slot['search']];
        }

        if ($wanted === []) {
            return $out;
        }

        try {
            foreach ($this->stock->findMany(array_values($wanted)) as $k => $shot) {
                $i = array_keys($wanted)[$k];
                $found = $shot === null ? null : $this->file($shot['bytes'], '.jpg', $slots[$i]['brief'], $slots[$i]['width']);
                if ($found === null) {
                    Log::info('prototype photo: nothing free matched, the slot keeps its gradient', ['brief' => $slots[$i]['brief']]);

                    continue;
                }
                $out[$i] = ['data' => $found, 'source' => 'stock', 'credit' => $shot['credit'], 'url' => $shot['url']];
            }
        } catch (\Throwable $e) {
            Log::warning('prototype photo: stock skipped', ['error' => mb_substr($e->getMessage(), 0, 200)]);
        }

        return $out;
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

    private function encodeBytes(string $bytes, int $width): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'proto-own-');
        file_put_contents($tmp, $bytes);

        try {
            return $this->encode($tmp, $width);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * The first slots of an ad page rendered by the image agent, with the business's own picture
     * the builder chose for each as the product to show (owner's decision, 2026-09-17: the ads
     * are built around a rendered picture, and only the opening one is rendered).
     *
     * The brief inside the slot is the scene, written by Claude; the words of the ad are never
     * in the picture. A render that fails leaves the slot to the site, the library and stock.
     *
     * @return array<int,array{data:string,source:string,credit:null,url:null,fit:string}>
     */
    /**
     * The opening story picture of an ad prototype, briefed before the page is written so it can
     * render while Claude writes: the business as its own website describes it, the customer's
     * sentence, and the business's own product picture to put in the scene.
     *
     * @param  list<array{url:string,kind?:string}>  $images  the site's pictures, best first
     * @return array{prompt:string,size:string,refs:list<string>}
     */
    public static function openingJob(string $sentence, ?string $product, array $images, ?\Closure $fetch): array
    {
        // A product picture, not a screenshot of the site, when there is one.
        usort($images, fn ($a, $b) => (($a['kind'] ?? '') === 'screen') <=> (($b['kind'] ?? '') === 'screen'));
        $refs = [];
        foreach (array_slice($images, 0, 3) as $img) {
            if ($fetch !== null && ($bytes = $fetch($img['url'])) !== null) {
                $refs[] = $bytes;
                break;
            }
        }

        return [
            'prompt' => trim("A premium photographic picture for the opening story ad of this business's campaign, portrait 9:16.\n\n"
                ."The campaign, in the customer's words: ".mb_substr(trim($sentence), 0, 400)."\n\n"
                .($product !== null && trim($product) !== '' ? "What the business sells, read from its own website:\n".mb_substr(trim($product), 0, 1800)."\n\n" : '')
                .($refs !== []
                    ? "The attached picture is the business's own product. It is the hero: show THAT product, recognisable, large and sharp, in the world of the people who buy it and in the season of the campaign. Do not invent a different product.\n\n"
                    : "Show what the business sells, in the world of the people who buy it and in the season of the campaign.\n\n")
                .'Keep the top eighth calm for the page name, and the lower third calm and dark: the headline and button are set there. No words, letters, prices or logos in the picture.'),
            'size' => '1080x1920',
            'refs' => $refs,
        ];
    }

    private function rendered(string $html, array $slots, array $site): array
    {
        // Page order: the slots were collected class by class.
        $order = array_keys($slots);
        usort($order, fn ($a, $b) => (int) strpos($html, $slots[$a]['m'][0]) <=> (int) strpos($html, $slots[$b]['m'][0]));

        // Rendered ahead, beside the page: they go to the first slots in page order.
        if (isset($site['pictures'])) {
            $out = [];
            foreach (array_values($site['pictures']) as $k => $bytes) {
                $i = $order[$k] ?? null;
                if ($i !== null && $bytes !== null && ($uri = $this->encodeBytes($bytes, $slots[$i]['width'] >= 720 ? 900 : 720)) !== null) {
                    $out[$i] = ['data' => $uri, 'source' => 'codex', 'credit' => null, 'url' => null, 'fit' => 'photo'];
                }
            }

            return $out;
        }

        $count = (int) ($site['renders'] ?? 0);
        if ($count <= 0 || ! isset($site['render']) || $slots === []) {
            return [];
        }
        $jobs = $index = [];
        foreach (array_slice($order, 0, $count) as $i) {
            $at = (int) strpos($html, $slots[$i]['m'][0]);
            $frame = preg_match_all('~class="ad (ad-story|ad-square|ad-link)\b~', substr($html, 0, $at), $f) > 0 ? end($f[1]) : 'ad-square';
            [$size, $room] = match ($frame) {
                'ad-story' => ['1080x1920', 'Keep the top eighth calm for the page name, and the lower third calm and dark: the headline and button are set there.'],
                'ad-link' => ['1200x628', 'The product fills the middle of the frame.'],
                default => ['1080x1080', 'The product is the hero, whole and sharp.'],
            };
            $refs = [];
            if ($slots[$i]['own'] !== null && isset($site['fetch']) && ($bytes = ($site['fetch'])($slots[$i]['own']['url'])) !== null) {
                $refs[] = $bytes;
            }
            $jobs[] = [
                'prompt' => trim($slots[$i]['brief'].($slots[$i]['search'] ? "\nSubject: ".$slots[$i]['search'] : '')
                    .($refs !== [] ? "\nShow the attached picture of the business's own product in this scene." : '')
                    ."\nA premium photographic advertising picture. ".$room),
                'size' => $size,
                'refs' => $refs,
            ];
            $index[] = $i;
        }

        $out = [];
        try {
            foreach (($site['render'])($jobs) as $k => $bytes) {
                if ($bytes !== null && ($uri = $this->encodeBytes($bytes, $slots[$index[$k]]['width'] >= 720 ? 900 : 720)) !== null) {
                    $out[$index[$k]] = ['data' => $uri, 'source' => 'codex', 'credit' => null, 'url' => null, 'fit' => 'photo'];
                }
            }
        } catch (\Throwable $e) {
            Log::info('prototype photo: render skipped', ['error' => mb_substr($e->getMessage(), 0, 200)]);
        }

        return $out;
    }

    /** Width over height of an inlined picture, 1.0 when it cannot be told. */
    private static function aspect(string $dataUri): float
    {
        $bytes = base64_decode((string) substr($dataUri, (int) strpos($dataUri, ',') + 1), true) ?: '';
        if (str_starts_with($dataUri, 'data:image/svg')) {
            if (preg_match('~viewBox="\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)~i', $bytes, $m) === 1 && (float) $m[2] > 0) {
                return (float) $m[1] / (float) $m[2];
            }
            if (preg_match('~<svg[^>]*\swidth="([\d.]+)[^"]*"[^>]*\sheight="([\d.]+)~i', $bytes, $m) === 1 && (float) $m[2] > 0) {
                return (float) $m[1] / (float) $m[2];
            }

            return 1.0;
        }
        $size = @getimagesizefromstring($bytes);

        return $size === false || $size[1] === 0 ? 1.0 : $size[0] / $size[1];
    }

    /** The logo as a data URI: SVG as it is (an <img> runs no script), anything else re-encoded. */
    private function logo(string $url, \Closure $fetch): ?string
    {
        try {
            $bytes = $fetch($url);
            if ($bytes === null || strlen($bytes) > 300_000) {
                return null;
            }
            if (preg_match('~<svg[\s>]~i', substr($bytes, 0, 2000)) === 1) {
                return 'data:image/svg+xml;base64,'.base64_encode($bytes);
            }

            return @getimagesizefromstring($bytes) === false ? null : $this->encodeBytes($bytes, 320);
        } catch (\Throwable $e) {
            Log::info('prototype photo: logo skipped', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return null;
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
