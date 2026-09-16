<?php

namespace App\Domain\Design;

use App\Domain\Qa\LayoutFit;
use App\Models\Setting;

/**
 * Layout packs for the free prototypes, switched on and off in the admin panel.
 *
 * A pack is one skeleton: the bones of a page for one kind and one trade, written to the same
 * laws the model writes to (one style block, no external URL, nothing scrolling sideways at
 * 320px). The model still chooses the colour, the type scale and the rhythm; the pack only says
 * how the page is built. Bones from a designer, skin from the model.
 *
 * Why this is a setting and not an env var: the whole point is to find out whether packs make the
 * pages better, and that question is answered by turning them on, looking, and turning them off
 * again. A deploy in between would make the comparison useless. `share` keeps a control group:
 * at 70, seven builds in ten get a pack and three keep writing from nothing, so the two can be
 * compared on the same week's traffic.
 *
 * Packs are ours. The first set was drawn in Lovable, whose terms give the output to the account
 * that made it, then ported by hand to our own laws; `source` records where each one came from so
 * a later comparison can tell a bought skeleton from a hand-written one.
 */
class Layouts
{
    /** Kinds a pack can exist for. Same three the prototype pipeline knows. */
    public const KINDS = ['site', 'app', 'ads'];

    /** Where a pack came from. Recorded per pack so the two can be told apart later. */
    public const SOURCES = ['lovable', 'hand', 'house'];

    public function __construct(private readonly string $dir) {}

    public function enabled(): bool
    {
        return (bool) Setting::read('layouts.enabled', false);
    }

    /** @return list<string> the kinds packs are used for; a kind not in here writes from nothing */
    public function kinds(): array
    {
        $kinds = Setting::read('layouts.kinds', ['site']);

        return array_values(array_intersect(is_array($kinds) ? $kinds : [], self::KINDS));
    }

    /** How many builds in a hundred get a pack. The rest are the control group. */
    public function share(): int
    {
        return max(0, min(100, (int) Setting::read('layouts.share', 100)));
    }

    /** @return list<string> slugs switched off one by one, without touching the rest */
    public function off(): array
    {
        $off = Setting::read('layouts.off', []);

        return is_array($off) ? array_values(array_filter($off, 'is_string')) : [];
    }

    /**
     * Every pack on disk, whether or not it is switched on.
     *
     * @return list<array{slug:string,kind:string,industries:list<string>,source:string,note:string,keeps:array{sections:int,devices:list<string>},bytes:int}>
     */
    public function packs(): array
    {
        $manifest = $this->dir.'/packs.json';
        if (! is_file($manifest)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($manifest), true);
        $out = [];
        foreach ($data['packs'] ?? [] as $p) {
            $slug = (string) ($p['slug'] ?? '');
            $kind = (string) ($p['kind'] ?? '');
            // A manifest line without a file behind it is a half-finished pack, not a pack.
            if ($slug === '' || ! in_array($kind, self::KINDS, true) || ! is_file($this->file($slug))) {
                continue;
            }
            $out[] = [
                'slug' => $slug,
                'kind' => $kind,
                'industries' => array_values(array_filter((array) ($p['industries'] ?? []), 'is_string')),
                'source' => in_array($p['source'] ?? '', self::SOURCES, true) ? $p['source'] : 'hand',
                'note' => (string) ($p['note'] ?? ''),
                // What a page built on this pack must keep; LayoutFit counts it after the build.
                'keeps' => [
                    'sections' => (int) ($p['keeps']['sections'] ?? 0),
                    'devices' => array_values(array_intersect((array) ($p['keeps']['devices'] ?? []), array_keys(LayoutFit::DEVICES))),
                ],
                'bytes' => (int) filesize($this->file($slug)),
            ];
        }

        return $out;
    }

    /**
     * The pack this build should follow, or null to write from nothing.
     *
     * Null is a normal answer, not a failure: the feature is off, the kind is not covered, the
     * coin fell into the control group, or no pack exists for this kind yet.
     *
     * @return ?array{slug:string,source:string,keeps:array{sections:int,devices:list<string>},skeleton:string}
     */
    public function pick(string $kind, string $prompt): ?array
    {
        if (! $this->enabled() || ! in_array($kind, $this->kinds(), true)) {
            return null;
        }
        $share = $this->share();
        if ($share === 0 || ($share < 100 && random_int(1, 100) > $share)) {
            return null;
        }
        $off = $this->off();
        $candidates = array_values(array_filter(
            $this->packs(),
            fn ($p) => $p['kind'] === $kind && ! in_array($p['slug'], $off, true),
        ));
        if ($candidates === []) {
            return null;
        }

        // A pack names the trades it was drawn for. A bakery skeleton on a dental practice is
        // worse than no skeleton, so a trade match wins; with no match any pack of the kind is
        // still a fair set of bones, because the laws and the proportions are the same.
        $named = array_values(array_filter(
            $candidates,
            fn ($p) => $this->mentions($p['industries'], $prompt),
        ));
        $pool = $named !== [] ? $named : $candidates;
        $pack = $pool[random_int(0, count($pool) - 1)];

        return [
            'slug' => $pack['slug'],
            'source' => $pack['source'],
            'keeps' => $pack['keeps'],
            'skeleton' => trim((string) file_get_contents($this->file($pack['slug']))),
        ];
    }

    /**
     * What the admin tile shows: the switches, and what is on disk to switch.
     *
     * @return array{enabled:bool,kinds:list<string>,share:int,off:list<string>,packs:list<array<string,mixed>>,by_kind:array<string,int>}
     */
    public function status(): array
    {
        $packs = $this->packs();
        $byKind = array_fill_keys(self::KINDS, 0);
        foreach ($packs as $p) {
            $byKind[$p['kind']]++;
        }

        return [
            'enabled' => $this->enabled(),
            'kinds' => $this->kinds(),
            'share' => $this->share(),
            'off' => $this->off(),
            'packs' => $packs,
            'by_kind' => $byKind,
        ];
    }

    /** @param  list<string>  $industries */
    private function mentions(array $industries, string $prompt): bool
    {
        $haystack = mb_strtolower($prompt);
        foreach ($industries as $word) {
            if ($word !== '' && str_contains($haystack, mb_strtolower($word))) {
                return true;
            }
        }

        return false;
    }

    private function file(string $slug): string
    {
        // Slugs come from our own manifest, but they end up in a path, so nothing but a slug.
        return $this->dir.'/'.preg_replace('/[^a-z0-9-]/', '', mb_strtolower($slug)).'.html';
    }
}
