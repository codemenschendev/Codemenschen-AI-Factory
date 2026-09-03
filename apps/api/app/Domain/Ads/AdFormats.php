<?php

namespace App\Domain\Ads;

/**
 * The canvases an ad can be rendered on.
 *
 * These are published platform specs, not design work: Meta's feed and story sizes, Google's
 * responsive display assets, and the IAB display units Google Display sells. They belong in one
 * table because three places used to need them and only one of them had them, hardcoded.
 *
 * `kinds` is the part that carries judgement. A 320x50 mobile banner is a real ad size, but no
 * film runs there and make-ad.py's title-plus-line layout has nowhere to go in fifty pixels of
 * height. Formats say what they can actually carry, and the small display units say image only.
 */
final class AdFormats
{
    /**
     * w/h are pixels. `group` is what the operator sees them filed under. `kinds` lists the ad
     * kinds the format is offered for. `ready` false means the size is correct but make-ad.py
     * still lays text out for a large canvas, so the copy will not sit well until that is done.
     *
     * @var array<string,array{w:int,h:int,group:string,label:string,kinds:array<int,string>,ready:bool}>
     */
    public const FORMATS = [
        // --- Social. The three original keys keep their names: existing clients send them. ---
        'vertical' => [
            'w' => 1080, 'h' => 1920, 'group' => 'social', 'label' => 'Story / Reels / Shorts',
            'kinds' => ['video', 'image'], 'ready' => true,
        ],
        'square' => [
            'w' => 1080, 'h' => 1080, 'group' => 'social', 'label' => 'Feed square',
            'kinds' => ['video', 'image'], 'ready' => true,
        ],
        'landscape' => [
            'w' => 1920, 'h' => 1080, 'group' => 'social', 'label' => 'YouTube / feed wide',
            'kinds' => ['video', 'image'], 'ready' => true,
        ],
        // 4:5 is what Meta recommends for the feed: it takes the most height a feed post may use.
        'feed_portrait' => [
            'w' => 1080, 'h' => 1350, 'group' => 'social', 'label' => 'Feed portrait (4:5)',
            'kinds' => ['video', 'image'], 'ready' => true,
        ],
        // 1.91:1, the one canvas Meta link ads and Google responsive display both take.
        'link' => [
            'w' => 1200, 'h' => 628, 'group' => 'social', 'label' => 'Link ad (1.91:1)',
            'kinds' => ['video', 'image'], 'ready' => true,
        ],

        // --- Google responsive display asset sizes. Still image only. ---
        'gads_square' => [
            'w' => 1200, 'h' => 1200, 'group' => 'google', 'label' => 'Responsive square',
            'kinds' => ['image'], 'ready' => true,
        ],
        'gads_portrait' => [
            'w' => 960, 'h' => 1200, 'group' => 'google', 'label' => 'Responsive portrait (4:5)',
            'kinds' => ['image'], 'ready' => true,
        ],

        // --- IAB display units. Correct sizes, but the text layer needs its own layout first. ---
        'gdn_rectangle' => [
            'w' => 300, 'h' => 250, 'group' => 'display', 'label' => 'Medium rectangle',
            'kinds' => ['image'], 'ready' => false,
        ],
        'gdn_rectangle_lg' => [
            'w' => 336, 'h' => 280, 'group' => 'display', 'label' => 'Large rectangle',
            'kinds' => ['image'], 'ready' => false,
        ],
        'gdn_leaderboard' => [
            'w' => 728, 'h' => 90, 'group' => 'display', 'label' => 'Leaderboard',
            'kinds' => ['image'], 'ready' => false,
        ],
        'gdn_halfpage' => [
            'w' => 300, 'h' => 600, 'group' => 'display', 'label' => 'Half page',
            'kinds' => ['image'], 'ready' => false,
        ],
        'gdn_skyscraper' => [
            'w' => 160, 'h' => 600, 'group' => 'display', 'label' => 'Wide skyscraper',
            'kinds' => ['image'], 'ready' => false,
        ],
        'gdn_billboard' => [
            'w' => 970, 'h' => 250, 'group' => 'display', 'label' => 'Billboard',
            'kinds' => ['image'], 'ready' => false,
        ],
        'gdn_mobile' => [
            'w' => 320, 'h' => 50, 'group' => 'display', 'label' => 'Mobile banner',
            'kinds' => ['image'], 'ready' => false,
        ],
        'gdn_mobile_lg' => [
            'w' => 320, 'h' => 100, 'group' => 'display', 'label' => 'Large mobile banner',
            'kinds' => ['image'], 'ready' => false,
        ],
    ];

    /** The default when a caller names no format. Story size: the one most ads here are for. */
    public const DEFAULT = 'vertical';

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::FORMATS);
    }

    /** @return array{w:int,h:int,group:string,label:string,kinds:array<int,string>,ready:bool}|null */
    public static function get(string $key): ?array
    {
        return self::FORMATS[$key] ?? null;
    }

    /** "1080x1920", the shape the render spec and make-ad.py already speak. */
    public static function size(?string $key): string
    {
        $f = self::FORMATS[$key ?? self::DEFAULT] ?? self::FORMATS[self::DEFAULT];

        return $f['w'].'x'.$f['h'];
    }

    /**
     * The formats a given ad kind can be rendered in, with only the ones that render properly
     * today unless $includeUnready says otherwise.
     *
     * @return array<string,array{w:int,h:int,group:string,label:string,kinds:array<int,string>,ready:bool}>
     */
    public static function forKind(string $kind, bool $includeUnready = false): array
    {
        return array_filter(
            self::FORMATS,
            fn (array $f) => in_array($kind, $f['kinds'], true) && ($includeUnready || $f['ready']),
        );
    }

    /** Validation rule for a request field, kept in step with the table by construction. */
    public static function rule(string $kind = 'image'): string
    {
        return 'in:'.implode(',', array_keys(self::forKind($kind, true)));
    }
}
