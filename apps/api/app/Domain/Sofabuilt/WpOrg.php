<?php

namespace App\Domain\Sofabuilt;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The desk's research: the public plugin directory API of wordpress.org. The API fetches, the
 * model only reads the numbers it is handed. Cached a day; a failure means no numbers, not an error.
 */
class WpOrg
{
    private const API = 'https://api.wordpress.org/plugins/info/1.2/';

    /** @return list<array{name: string, slug: string, installs: int, rating: int, ratings: int, updated: string, url: string}> */
    public function search(string $terms, int $limit = 5): array
    {
        $terms = mb_substr(trim(preg_replace('/\s+/', ' ', $terms) ?? ''), 0, 80);
        if ($terms === '') {
            return [];
        }

        return Cache::remember('wporg:search:'.sha1($terms), now()->addDay(), fn () => $this->query([
            'action' => 'query_plugins',
            'request' => ['search' => $terms, 'per_page' => $limit, 'fields' => ['description' => false, 'sections' => false, 'icons' => false, 'banners' => false]],
        ]));
    }

    /** @return array{name: string, slug: string, installs: int, rating: int, ratings: int, updated: string, url: string}|null */
    public function info(string $slug): ?array
    {
        if (! preg_match('/^[a-z0-9-]{2,80}$/', $slug)) {
            return null;
        }

        return Cache::remember('wporg:info:'.$slug, now()->addDay(), function () use ($slug) {
            try {
                $res = Http::timeout(8)->acceptJson()->withUserAgent('Sofabuilt/1.0 (+https://sofabuilt.com)')
                    ->get(self::API, ['action' => 'plugin_information', 'request' => ['slug' => $slug, 'fields' => ['sections' => false]]]);

                return $res->successful() && is_string($res->json('slug')) ? self::row($res->json()) : null;
            } catch (\Throwable $e) {
                Log::info('wporg info failed', ['slug' => $slug, 'error' => mb_substr($e->getMessage(), 0, 160)]);

                return null;
            }
        });
    }

    private function query(array $params): array
    {
        try {
            $res = Http::timeout(8)->acceptJson()->withUserAgent('Sofabuilt/1.0 (+https://sofabuilt.com)')->get(self::API, $params);
            if (! $res->successful()) {
                return [];
            }

            return array_values(array_map([self::class, 'row'], array_filter((array) $res->json('plugins'), 'is_array')));
        } catch (\Throwable $e) {
            Log::info('wporg search failed', ['error' => mb_substr($e->getMessage(), 0, 160)]);

            return [];
        }
    }

    private static function row(array $p): array
    {
        return [
            'name' => html_entity_decode(strip_tags((string) ($p['name'] ?? '')), ENT_QUOTES),
            'slug' => (string) ($p['slug'] ?? ''),
            'installs' => (int) ($p['active_installs'] ?? 0),
            'rating' => (int) ($p['rating'] ?? 0),
            'ratings' => (int) ($p['num_ratings'] ?? 0),
            'updated' => substr((string) ($p['last_updated'] ?? ''), 0, 10),
            'url' => 'https://wordpress.org/plugins/'.($p['slug'] ?? '').'/',
        ];
    }
}
