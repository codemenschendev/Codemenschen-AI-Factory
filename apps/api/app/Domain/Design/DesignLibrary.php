<?php

namespace App\Domain\Design;

use Illuminate\Support\Facades\Cache;

/**
 * The reference library: app screens we collected to study, read by the operator's panel.
 *
 * Nothing here is ever served to a customer or built into a product. The screens exist so we can
 * count what real apps actually do, and the counting has already happened: what the prototype
 * writer reads is resources/design/app-conventions.md, distilled from these labels. This class is
 * for the person who wants to check that distillation against the pictures behind it.
 *
 * The catalog is a 2 MB JSON file written by tools/label-design-library.py on the host. Decoding
 * it on every keystroke of a filter would be silly, so it is cached against its own mtime: the
 * script rewrites the file, the cache key changes, the next request sees the new labels.
 */
class DesignLibrary
{
    /** A page of thumbnails; more than this and the browser, not the server, becomes the problem. */
    public const PER_PAGE = 60;

    public function __construct(private readonly string $dir) {}

    /** MUST match INDUSTRIES in tools/label-design-library.py; a test fails if they drift. */
    public const INDUSTRIES = [
        'food_delivery', 'restaurant', 'retail_ecommerce', 'fashion', 'beauty_salon', 'health_fitness',
        'medical', 'finance_banking', 'crypto', 'travel', 'transport_mobility', 'real_estate',
        'education', 'productivity', 'social', 'media_entertainment', 'music', 'dating', 'gaming',
        'utilities', 'business_saas', 'other',
    ];

    /** MUST match SCREEN_TYPES in tools/label-design-library.py; a test fails if they drift. */
    public const SCREEN_TYPES = [
        'onboarding', 'signup_login', 'home_dashboard', 'list_feed', 'detail', 'search', 'filter',
        'map', 'calendar_booking', 'checkout_payment', 'cart', 'form_input', 'profile_account',
        'settings', 'messaging_chat', 'notifications', 'empty_state', 'paywall_pricing',
        'success_confirmation', 'media_player', 'camera_scanner', 'stats_report', 'other',
    ];

    public function available(): bool
    {
        return is_file($this->dir.'/catalog.json');
    }

    /**
     * Every record, newest label first so the freshly labelled work is what you land on.
     *
     * @return array<int,array<string,mixed>>
     */
    public function records(): array
    {
        $path = $this->dir.'/catalog.json';
        if (! is_file($path)) {
            return [];
        }

        return Cache::remember('design-library:'.filemtime($path), 300, function () use ($path) {
            $data = json_decode((string) file_get_contents($path), true);

            return is_array($data['images'] ?? null) ? $data['images'] : [];
        });
    }

    /**
     * What can be filtered on, and how many sit behind each choice.
     *
     * Built from the records themselves rather than from the script's vocabulary lists: a value
     * nobody applied is a filter that returns nothing, and an empty filter is worse than a missing
     * one.
     *
     * @return array<string,mixed>
     */
    public function facets(): array
    {
        $count = ['medium' => [], 'screen_type' => [], 'industry' => [], 'pattern' => [], 'category' => [], 'grade' => [], 'scheme' => []];
        $labelled = 0;

        foreach ($this->records() as $r) {
            $l = is_array($r['labels'] ?? null) ? $r['labels'] : [];
            if (($l['screen_type'] ?? null) !== null) {
                $labelled++;
            }
            foreach (['screen_type', 'industry'] as $k) {
                if ($l[$k] ?? null) {
                    $count[$k][$l[$k]] = ($count[$k][$l[$k]] ?? 0) + 1;
                }
            }
            foreach (($l['layout_patterns'] ?? []) as $p) {
                $count['pattern'][$p] = ($count['pattern'][$p] ?? 0) + 1;
            }
            if ($s = ($l['palette']['scheme'] ?? null)) {
                $count['scheme'][$s] = ($count['scheme'][$s] ?? 0) + 1;
            }
            $count['medium'][$r['medium'] ?? 'unknown'] = ($count['medium'][$r['medium'] ?? 'unknown'] ?? 0) + 1;
            $count['category'][$r['visual']['category'] ?? 'unknown'] = ($count['category'][$r['visual']['category'] ?? 'unknown'] ?? 0) + 1;
            $count['grade'][$r['visual']['grade'] ?? 'unknown'] = ($count['grade'][$r['visual']['grade'] ?? 'unknown'] ?? 0) + 1;
        }

        foreach ($count as $k => $v) {
            arsort($v);
            $count[$k] = $v;
        }

        return ['total' => count($this->records()), 'labelled' => $labelled, 'facets' => $count];
    }

    /**
     * One page of the library.
     *
     * @param  array<string,string|null>  $filters  screen_type, industry, pattern, category, grade, scheme, q
     * @return array{total:int,page:int,pages:int,items:array<int,array<string,mixed>>}
     */
    public function search(array $filters, int $page = 1): array
    {
        $hits = [];
        foreach ($this->records() as $r) {
            if ($this->matches($r, $filters)) {
                $hits[] = $this->row($r);
            }
        }

        $pages = max(1, (int) ceil(count($hits) / self::PER_PAGE));
        $page = max(1, min($page, $pages));

        return [
            'total' => count($hits),
            'page' => $page,
            'pages' => $pages,
            'items' => array_slice($hits, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
        ];
    }

    /** @param array<string,mixed> $r */
    private function matches(array $r, array $filters): bool
    {
        $l = is_array($r['labels'] ?? null) ? $r['labels'] : [];

        foreach (['screen_type', 'industry'] as $k) {
            if (($filters[$k] ?? null) && ($l[$k] ?? null) !== $filters[$k]) {
                return false;
            }
        }
        if (($filters['pattern'] ?? null) && ! in_array($filters['pattern'], $l['layout_patterns'] ?? [], true)) {
            return false;
        }
        if (($filters['scheme'] ?? null) && ($l['palette']['scheme'] ?? null) !== $filters['scheme']) {
            return false;
        }
        foreach (['category', 'grade'] as $k) {
            if (($filters[$k] ?? null) && ($r['visual'][$k] ?? null) !== $filters[$k]) {
                return false;
            }
        }
        if (($filters['medium'] ?? null) && ($r['medium'] ?? null) !== $filters['medium']) {
            return false;
        }
        if ($filters['labelled'] ?? null) {
            $has = ($l['screen_type'] ?? null) !== null;
            if (($filters['labelled'] === 'yes') !== $has) {
                return false;
            }
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            // One haystack per record: the words the labeller wrote plus where the screen came
            // from, which is how an operator actually remembers a screen.
            $hay = mb_strtolower(implode(' ', array_filter([
                $l['notes'] ?? '', $l['primary_action'] ?? '', $l['screen_type'] ?? '', $l['industry'] ?? '',
                implode(' ', $l['layout_patterns'] ?? []),
                $r['ai_index']['search_text'] ?? '',
            ])));
            foreach (preg_split('/\s+/', mb_strtolower($q)) ?: [] as $term) {
                if ($term !== '' && ! str_contains($hay, $term)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The record as the panel needs it: no source URLs, no hashes, no base64. The signed image
     * URL is added by the controller, which is the only place that knows how to sign.
     *
     * @param  array<string,mixed>  $r
     * @return array<string,mixed>
     */
    private function row(array $r): array
    {
        $l = is_array($r['labels'] ?? null) ? $r['labels'] : [];
        $src = $r['sources'][0]['website'] ?? [];

        return [
            'id' => $r['id'],
            'medium' => $r['medium'] ?? null,
            'width' => $r['visual']['width'] ?? 0,
            'height' => $r['visual']['height'] ?? 0,
            'bytes' => $r['byte_size'] ?? 0,
            'orientation' => $r['visual']['orientation'] ?? null,
            'category' => $r['visual']['category'] ?? null,
            'grade' => $r['visual']['grade'] ?? null,
            'source' => $src['label'] ?? ($src['domain'] ?? null),
            'screen_type' => $l['screen_type'] ?? null,
            'industry' => $l['industry'] ?? null,
            'patterns' => $l['layout_patterns'] ?? [],
            'primary_action' => $l['primary_action'] ?? null,
            'density' => $l['density'] ?? null,
            'scheme' => $l['palette']['scheme'] ?? null,
            'accent' => $l['palette']['accent'] ?? null,
            'notes' => $l['notes'] ?? null,
        ];
    }

    /**
     * Words in a brief that point at an industry the library is labelled with.
     *
     * German first, because that is what the visitors write. Deliberately small: a wrong guess
     * hands the model a fintech dashboard for a hair salon, and a miss just means no reference,
     * which is the state everything was in before this existed.
     *
     * @var array<string,list<string>>
     */
    private const INDUSTRY_WORDS = [
        'beauty_salon' => ['friseur', 'frisör', 'salon', 'kosmetik', 'nagel', 'haar', 'barbier', 'beauty', 'barber'],
        'health_fitness' => ['fitness', 'yoga', 'training', 'gym', 'physio', 'therapie', 'massage', 'studio'],
        'medical' => ['arzt', 'ärztin', 'praxis', 'zahnarzt', 'ordination', 'klinik', 'patient', 'apotheke'],
        'restaurant' => ['restaurant', 'gasthaus', 'wirt', 'lokal', 'café', 'cafe', 'bistro', 'küche', 'bäckerei', 'pizzeria'],
        'food_delivery' => ['lieferung', 'liefern', 'bestellen', 'delivery', 'abholung', 'take away'],
        'retail_ecommerce' => ['shop', 'laden', 'geschäft', 'verkauf', 'produkte', 'sortiment', 'store'],
        'fashion' => ['mode', 'kleidung', 'boutique', 'schneider', 'textil'],
        'travel' => ['hotel', 'pension', 'reise', 'zimmer', 'gäste', 'ferienwohnung', 'tourismus'],
        'transport_mobility' => ['taxi', 'fahrt', 'fahrer', 'transport', 'lieferdienst', 'uber', 'mitfahr'],
        'real_estate' => ['immobilien', 'makler', 'wohnung', 'haus kaufen', 'miete'],
        'education' => ['schule', 'kurs', 'unterricht', 'lernen', 'nachhilfe', 'trainer', 'seminar'],
        'finance_banking' => ['bank', 'versicherung', 'buchhaltung', 'steuer', 'finanz', 'rechnung'],
        'business_saas' => ['software', 'saas', 'agentur', 'kanzlei', 'beratung', 'verwaltung'],
        'social' => ['verein', 'community', 'mitglieder', 'club', 'treffen'],
        'utilities' => ['handwerk', 'installateur', 'elektriker', 'maler', 'reparatur', 'service'],
        'trades_crafts' => ['tischler', 'schreiner', 'werkstatt', 'zimmerei', 'schlosser', 'dachdecker'],
        'events_culture' => ['veranstaltung', 'konzert', 'festival', 'messe', 'ausstellung', 'theater'],
    ];

    /**
     * One labelled app screen to show the model while it writes, as a data URI.
     *
     * Only detail-grade screens: at 360px the text is a few pixels tall and a reference nobody can
     * read teaches nothing. The industry is guessed from the brief, and a miss returns null rather
     * than a confident wrong answer, because no reference beats the wrong one.
     *
     * @return array{id:string,note:string,data:string}|null
     */
    public function reference(string $brief, ?string $screenType = null): ?array
    {
        $industry = $this->industryFor($brief);
        if ($industry === null) {
            return null;
        }

        $hits = [];
        foreach ($this->records() as $r) {
            $l = is_array($r['labels'] ?? null) ? $r['labels'] : [];
            if (($r['medium'] ?? null) !== 'app' || ($r['visual']['grade'] ?? null) !== 'detail') {
                continue;
            }
            if (($l['industry'] ?? null) !== $industry) {
                continue;
            }
            if ($screenType !== null && ($l['screen_type'] ?? null) !== $screenType) {
                continue;
            }
            $hits[] = $r;
        }
        if ($hits === []) {
            return null;
        }

        // Random among the matches: two salons in the same town must not be handed the same screen.
        $r = $hits[array_rand($hits)];
        $path = $this->dir.'/'.($r['file'] ?? '');
        $bytes = is_file($path) ? @file_get_contents($path) : false;
        if ($bytes === false) {
            return null;
        }

        return [
            'id' => (string) $r['id'],
            'note' => (string) (($r['labels']['notes'] ?? '') ?: ''),
            'data' => $this->mimeFor($path).base64_encode($bytes),
        ];
    }

    /**
     * The screens to study before drawing: one per wanted screen type from the trade's own apps,
     * then the trade's other screens up to the cap.
     *
     * Detail grade first, because text that can be read teaches the most; layout grade is allowed
     * behind it, because a trade with two legible screens still has thirty whose structure reads
     * fine at 360px, and structure is what a study is for. A trade the library barely knows falls
     * back to the same screen types from every trade: the shape of a map screen is the shape of a
     * map screen. Random among equals, so two briefs in one trade do not study the same six.
     *
     * @param  list<string>  $screenTypes  what the four screens will be, in order
     * @return list<array{id:string,note:string,data:string,screen_type:string,grade:string,industry:string}>
     */
    public function references(string $industry, array $screenTypes, int $max = 6): array
    {
        $apps = array_values(array_filter($this->records(), fn (array $r) => ($r['medium'] ?? null) === 'app'
            && is_array($r['labels'] ?? null) && ($r['labels']['screen_type'] ?? null) !== null));
        $own = array_values(array_filter($apps, fn (array $r) => ($r['labels']['industry'] ?? null) === $industry));

        $rank = fn (array $r) => ($r['visual']['grade'] ?? '') === 'detail' ? 0 : 1;
        $pick = function (array $pool, callable $keep, array &$taken) use ($rank): ?array {
            $hits = array_values(array_filter($pool, fn (array $r) => $keep($r) && ! isset($taken[$r['id']])));
            if ($hits === []) {
                return null;
            }
            shuffle($hits);
            usort($hits, fn (array $a, array $b) => $rank($a) <=> $rank($b));
            $taken[$hits[0]['id']] = true;

            return $hits[0];
        };

        $taken = [];
        $chosen = [];
        foreach ($screenTypes as $type) {
            $r = $pick($own, fn (array $r) => $r['labels']['screen_type'] === $type, $taken)
                ?? $pick($apps, fn (array $r) => $r['labels']['screen_type'] === $type, $taken);
            if ($r !== null) {
                $chosen[] = $r;
            }
        }
        while (count($chosen) < $max) {
            $r = $pick($own, fn () => true, $taken);
            if ($r === null) {
                break;
            }
            $chosen[] = $r;
        }

        $out = [];
        foreach (array_slice($chosen, 0, $max) as $r) {
            $image = $this->image($r);
            if ($image === null) {
                continue;
            }
            $out[] = $image + [
                'screen_type' => (string) $r['labels']['screen_type'],
                'grade' => (string) ($r['visual']['grade'] ?? ''),
                'industry' => (string) ($r['labels']['industry'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * What the trade's apps do, counted, as one paragraph for the prompt.
     *
     * The average over the whole library says an app has a tab bar; the count for ride-hailing
     * says a third of its screens are maps. The second is the one that changes a design.
     */
    public function industryStats(string $industry): string
    {
        $own = array_values(array_filter($this->records(), fn (array $r) => ($r['medium'] ?? null) === 'app'
            && is_array($r['labels'] ?? null) && ($r['labels']['industry'] ?? null) === $industry
            && ($r['labels']['screen_type'] ?? null) !== null));
        $n = count($own);
        if ($n < 5) {
            return '';
        }

        $count = function (callable $key) use ($own): array {
            $c = [];
            foreach ($own as $r) {
                foreach ((array) $key($r) as $v) {
                    if (is_string($v) && $v !== '') {
                        $c[$v] = ($c[$v] ?? 0) + 1;
                    }
                }
            }
            arsort($c);

            return $c;
        };
        $top = fn (array $c, int $k) => implode(', ', array_map(
            fn ($v, $cnt) => str_replace('_', ' ', $v)." ($cnt)", array_keys(array_slice($c, 0, $k, true)), array_slice($c, 0, $k, true)));

        $screens = $count(fn ($r) => $r['labels']['screen_type']);
        $patterns = $count(fn ($r) => $r['labels']['layout_patterns'] ?? []);
        $schemes = $count(fn ($r) => $r['labels']['palette']['scheme'] ?? null);
        $accents = $count(fn ($r) => $r['labels']['palette']['accent'] ?? null);
        $density = $count(fn ($r) => $r['labels']['density'] ?? null);

        $label = str_replace('_', ' ', $industry);
        $lines = ["{$n} screens of {$label} apps in the reference library, counted:"];
        $lines[] = '- Screen types: '.$top($screens, 5).'.';
        if ($patterns !== []) {
            $lines[] = '- Layout patterns: '.$top($patterns, 6).'.';
        }
        if ($schemes !== []) {
            $lines[] = '- Colour scheme: '.$top($schemes, 2).'; accent colours: '.$top($accents, 4).'.';
        }
        if ($density !== []) {
            $lines[] = '- Density: '.$top($density, 3).'.';
        }

        return implode("\n", $lines);
    }

    /** @return array{id:string,note:string,data:string}|null */
    private function image(array $r): ?array
    {
        $path = $this->dir.'/'.($r['file'] ?? '');
        $bytes = is_file($path) ? @file_get_contents($path) : false;
        if ($bytes === false) {
            return null;
        }

        return [
            'id' => (string) $r['id'],
            'note' => (string) (($r['labels']['notes'] ?? '') ?: ''),
            'data' => $this->mimeFor($path).base64_encode($bytes),
        ];
    }

    /**
     * One labelled advertisement to show the copywriter, as a data URI.
     *
     * Chosen by ANGLE first, because the angle is the whole ad: a price anchor and a testimonial
     * are different pictures before they are different sentences. The trade narrows it further
     * when the library has one, and format last, because a story and a square of the same idea
     * teach the same lesson.
     *
     * Grade is not filtered here the way it is for app screens. An ad is a poster: at 360px the
     * composition, the weight of the text and where the hook sits all still read, and those are
     * the three things worth copying.
     *
     * @return array{id:string,note:string,data:string}|null
     */
    public function adReference(?string $angle, string $brief = '', ?string $format = null): ?array
    {
        $industry = $brief === '' ? null : $this->industryFor($brief);

        $ads = [];
        foreach ($this->records() as $r) {
            if (! in_array($r['visual']['category'] ?? '', ['advertisement', 'banner'], true)) {
                continue;
            }
            $l = is_array($r['labels'] ?? null) ? $r['labels'] : [];
            if (($l['angle'] ?? null) === null) {
                continue;
            }
            $ads[] = [$r, $l];
        }
        if ($ads === []) {
            return null;
        }

        // Narrow while narrowing still leaves something. An empty result from the last filter is
        // worse than a looser match: the point is to show an ad, not to show the perfect ad.
        $narrow = function (array $pool, callable $keep) {
            $kept = array_values(array_filter($pool, $keep));

            return $kept === [] ? $pool : $kept;
        };

        if ($angle !== null) {
            $exact = array_values(array_filter($ads, fn (array $p) => $p[1]['angle'] === $angle));
            if ($exact === []) {
                return null;   // the angle is the request; a different one answers a different question
            }
            $ads = $exact;
        }
        if ($industry !== null) {
            $ads = $narrow($ads, fn (array $p) => ($p[1]['industry'] ?? null) === $industry);
        }
        if ($format !== null) {
            $ads = $narrow($ads, fn (array $p) => ($p[1]['format'] ?? null) === $format);
        }

        [$r, $l] = $ads[array_rand($ads)];
        $path = $this->dir.'/'.($r['file'] ?? '');
        $bytes = is_file($path) ? @file_get_contents($path) : false;
        if ($bytes === false) {
            return null;
        }

        return [
            'id' => (string) $r['id'],
            'note' => (string) (($l['notes'] ?? '') ?: ''),
            'data' => $this->mimeFor($path).base64_encode($bytes),
        ];
    }

    /**
     * One labelled web page to show the model while it writes a landing page.
     *
     * Detail grade only, and no grade fallback: at 360px a page is a blur, and the thing worth
     * learning from a landing page is the order of its sections.
     *
     * Chosen by the trade the brief is about, then by whether the visitor asked for something
     * dark. A miss on the trade is not fatal the way a wrong ad angle is: every landing page in
     * the library teaches section order and how much air to leave, whatever it sells.
     *
     * @return array{id:string,note:string,data:string}|null
     */
    public function siteReference(string $brief, ?string $scheme = null): ?array
    {
        $pages = [];
        foreach ($this->records() as $r) {
            $l = is_array($r['labels'] ?? null) ? $r['labels'] : [];
            if (($r['medium'] ?? null) !== 'web' || ($r['visual']['grade'] ?? null) !== 'detail') {
                continue;
            }
            if (($l['page_type'] ?? null) === null) {
                continue;
            }
            $pages[] = [$r, $l];
        }
        if ($pages === []) {
            return null;
        }

        // Narrow only while narrowing leaves something: a page of the right shape beats none.
        $narrow = function (array $pool, callable $keep) {
            $kept = array_values(array_filter($pool, $keep));

            return $kept === [] ? $pool : $kept;
        };

        // A landing page is what this generator writes, so prefer one, then the trade, then dark
        // or light. Each filter is a preference, none of them is a requirement.
        $pages = $narrow($pages, fn (array $p) => in_array($p[1]['page_type'], ['landing', 'product'], true));

        $industry = $this->industryFor($brief);
        if ($industry !== null) {
            $pages = $narrow($pages, fn (array $p) => ($p[1]['industry'] ?? null) === $industry);
        }
        if ($scheme !== null) {
            $pages = $narrow($pages, fn (array $p) => ($p[1]['palette']['scheme'] ?? null) === $scheme);
        }

        [$r, $l] = $pages[array_rand($pages)];
        $path = $this->dir.'/'.($r['file'] ?? '');
        $bytes = is_file($path) ? @file_get_contents($path) : false;
        if ($bytes === false) {
            return null;
        }

        return [
            'id' => (string) $r['id'],
            'note' => (string) (($l['notes'] ?? '') ?: ''),
            'data' => $this->mimeFor($path).base64_encode($bytes),
        ];
    }

    private function mimeFor(string $path): string
    {
        $mime = str_ends_with($path, '.png') ? 'image/png'
            : (str_ends_with($path, '.webp') ? 'image/webp' : 'image/jpeg');

        return "data:{$mime};base64,";
    }

    /** @return string|null the labelled industry this brief is about, if one is obvious */
    public function industryFor(string $brief): ?string
    {
        $hay = mb_strtolower($brief);
        $best = null;
        $bestHits = 0;
        foreach (self::INDUSTRY_WORDS as $industry => $words) {
            $hits = 0;
            foreach ($words as $w) {
                if (str_contains($hay, $w)) {
                    $hits++;
                }
            }
            if ($hits > $bestHits) {
                $bestHits = $hits;
                $best = $industry;
            }
        }

        return $best;
    }

    /**
     * Absolute path of one image, or null.
     *
     * The id is looked up in the catalog rather than pasted into a path: that way a crafted id
     * cannot walk out of the library, because only ids the catalog already knows resolve at all.
     */
    public function path(string $id): ?string
    {
        foreach ($this->records() as $r) {
            if (($r['id'] ?? null) === $id) {
                $p = $this->dir.'/'.($r['file'] ?? '');

                return is_file($p) ? $p : null;
            }
        }

        return null;
    }
}
