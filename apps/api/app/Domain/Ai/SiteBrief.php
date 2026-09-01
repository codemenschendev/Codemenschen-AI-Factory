<?php

namespace App\Domain\Ai;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads a real page so an ad can be about the real thing.
 *
 * Without this the copywriter only sees the sentence the customer typed. Ask it for "an ad for
 * codemenschen.at" and it has no idea what that is, so it writes something that would fit any
 * software company and picks a stock photo to match. One fetch of the page turns that into copy
 * about the actual product.
 *
 * The URL comes from user input, so this is a request the customer controls: only http(s), only
 * public addresses, one redirect hop at most, and a hard cap on what is read. Otherwise it would
 * be a way to make the API container fetch things on the private network for them.
 */
class SiteBrief
{
    private const MAX_BYTES = 300_000;

    /** @return array<string,string>|null */
    public function forPrompt(string $prompt): ?array
    {
        $url = $this->findUrl($prompt);

        return $url === null ? null : $this->fetch($url);
    }

    private function findUrl(string $text): ?string
    {
        // Either a full URL, or a bare domain like codemenschen.at that a person would type.
        if (preg_match('~https?://[^\s<>"\']+~i', $text, $m) === 1) {
            return $m[0];
        }
        if (preg_match('~\b((?:[a-z0-9-]+\.)+[a-z]{2,})\b~i', $text, $m) === 1) {
            return 'https://'.$m[1];
        }

        return null;
    }

    private function isPublic(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! $host || ! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        foreach (array_merge(gethostbynamel($host) ?: [], []) as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return (bool) gethostbynamel($host);
    }

    /** @return array<string,string>|null */
    private function fetch(string $url): ?array
    {
        if (! $this->isPublic($url)) {
            return null;
        }

        try {
            $res = Http::withHeaders(['user-agent' => 'AppwerkAdBot/1.0'])
                ->timeout(8)->connectTimeout(4)->maxRedirects(1)->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $res->successful()) {
            return null;
        }

        $html = mb_substr((string) $res->body(), 0, self::MAX_BYTES);

        $brief = array_filter([
            'url' => $url,
            'title' => $this->tag($html, '~<title[^>]*>(.*?)</title>~is'),
            'description' => $this->meta($html, 'description') ?: $this->meta($html, 'og:description'),
            'headings' => implode(' · ', array_slice($this->all($html, '~<h[12][^>]*>(.*?)</h[12]>~is'), 0, 6)),
            'brand_color' => $this->meta($html, 'theme-color'),
        ], fn ($v) => is_string($v) && $v !== '');

        return count($brief) > 1 ? $brief : null;
    }

    private function tag(string $html, string $pattern): string
    {
        return preg_match($pattern, $html, $m) === 1 ? $this->clean($m[1]) : '';
    }

    private function meta(string $html, string $name): string
    {
        $p = '~<meta[^>]+(?:name|property)=["\']'.preg_quote($name, '~').'["\'][^>]*content=["\']([^"\']*)~i';
        if (preg_match($p, $html, $m) === 1) {
            return $this->clean($m[1]);
        }
        // Some templates put content before name; try the other order rather than missing it.
        $p2 = '~<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:name|property)=["\']'.preg_quote($name, '~').'["\']~i';

        return preg_match($p2, $html, $m) === 1 ? $this->clean($m[1]) : '';
    }

    /** @return array<int,string> */
    private function all(string $html, string $pattern): array
    {
        preg_match_all($pattern, $html, $m);

        return array_values(array_filter(array_map(fn ($s) => $this->clean($s), $m[1] ?? [])));
    }

    private function clean(string $s): string
    {
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $s) ?? ''), 0, 300);
    }
}
