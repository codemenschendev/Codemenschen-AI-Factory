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
