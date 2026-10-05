<?php

namespace App\Domain\Sofabuilt;

/**
 * What Sofabuilt builds (docs/specs/sofabuilt.md): WordPress plugins and Chrome extensions. Each
 * has its own parts, launch options and catalogue in config/sofabuilt.php; the desk, the prices and
 * the pipeline ask here which apply.
 */
class Platforms
{
    public const ALL = ['wordpress', 'chrome'];

    /** The pipeline stack each platform is built on (worker templates/<stack>-app). */
    public const STACKS = ['wordpress' => 'wp-plugin', 'chrome' => 'chrome-ext'];

    public static function of(?array $scope): string
    {
        $p = $scope['platform'] ?? 'wordpress';

        return in_array($p, self::ALL, true) ? $p : 'wordpress';
    }

    /** @return array<string, array{eur: int, en: string, de: string}> */
    public static function modules(string $platform): array
    {
        return $platform === 'chrome' ? config('sofabuilt.chrome.modules') : config('sofabuilt.modules');
    }

    /** @return array<string, int> */
    public static function repeatable(string $platform): array
    {
        return $platform === 'chrome' ? config('sofabuilt.chrome.repeatable', []) : config('sofabuilt.repeatable', []);
    }

    /** @return array<string, array{eur: int, en: string, de: string}> */
    public static function launch(string $platform): array
    {
        return $platform === 'chrome' ? config('sofabuilt.chrome.launch') : config('sofabuilt.launch');
    }

    /** @return list<array<string, mixed>> */
    public static function catalog(string $platform): array
    {
        return $platform === 'chrome' ? config('sofabuilt.chrome.catalog', []) : config('sofabuilt.catalog');
    }
}
