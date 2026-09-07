<?php

namespace App\Domain\Design;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * The live homepages of the trade's best-known businesses, for a site or ad study.
 *
 * The App Store answers "what do the best apps of this trade look like" with the apps' own
 * screenshots. For a website there is no store: the page itself is the reference, and the sidecar
 * already drives a Chromium that opens a URL, dismisses the cookie banner and photographs the
 * whole page (its /v1/pages/check, built for the maintenance reports). A study asks it for the
 * homepages the plan named and looks at the top of each one, the part a visitor sees first.
 *
 * Looked at, never shown: the pictures inform the study, the study informs the prototype.
 */
class WebShots
{
    /** The first screens of a homepage. A full page can be 8000px; the study wants the fold and a scroll. */
    private const HEIGHT = 2200;

    private const WIDTH = 600;

    /** A homepage changes slowly; a week-old picture is the same page. */
    private const FRESH_DAYS = 7;

    /**
     * @param  list<string>  $sites  domains or URLs the plan named, e.g. ["stroeck.at", "https://joseph.co.at"]
     * @return list<array{id:string,note:string,data:string,screen_type:string,app:string}>
     */
    public function forSites(array $sites, int $max = 2): array
    {
        $urls = [];
        foreach (array_slice(array_values(array_filter(array_map([$this, 'url'], $sites))), 0, $max) as $u) {
            $urls[$u] = true;
        }
        if ($urls === []) {
            return [];
        }

        $out = [];
        $missing = [];
        foreach (array_keys($urls) as $u) {
            $cached = $this->cached($u);
            if ($cached !== null) {
                $out[$u] = $cached;
            } else {
                $missing[] = $u;
            }
        }

        foreach ($this->capture($missing) as $u => $data) {
            $out[$u] = $data;
        }

        $list = [];
        foreach (array_keys($urls) as $u) {
            if (! isset($out[$u])) {
                continue;
            }
            $host = (string) parse_url($u, PHP_URL_HOST);
            $list[] = [
                'id' => 'web:'.$host,
                'note' => "{$host}, live homepage, first screens",
                'data' => $out[$u],
                'screen_type' => 'live homepage',
                'app' => $host,
            ];
        }

        return $list;
    }

    /** A domain or a URL as one https URL, or null for anything that is not a hostname. */
    private function url(string $site): ?string
    {
        $site = trim($site);
        if ($site === '') {
            return null;
        }
        if (! preg_match('~^https?://~i', $site)) {
            $site = 'https://'.$site;
        }
        $host = parse_url($site, PHP_URL_HOST);
        if (! is_string($host) || ! str_contains($host, '.') || preg_match('/^[a-z0-9.-]+$/i', $host) !== 1) {
            return null;
        }

        return 'https://'.strtolower($host).'/';
    }

    private function file(string $url): string
    {
        $dir = storage_path('app/web-shots');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir.'/'.sha1($url).'.webp';
    }

    private function cached(string $url): ?string
    {
        $f = $this->file($url);
        if (is_file($f) && filesize($f) > 1000 && filemtime($f) > time() - self::FRESH_DAYS * 86400) {
            return 'data:image/webp;base64,'.base64_encode((string) file_get_contents($f));
        }

        return null;
    }

    /**
     * One trip to the sidecar for every page not on disk yet.
     *
     * @param  list<string>  $urls
     * @return array<string,string> url => data URI
     */
    private function capture(array $urls): array
    {
        if ($urls === []) {
            return [];
        }
        $baseUrl = rtrim((string) config('services.ai_image.base_url'), '/');
        $token = (string) config('services.ai_image.token');
        if ($baseUrl === '' || $token === '') {
            return [];
        }

        try {
            $res = Http::baseUrl($baseUrl)->withToken($token)->acceptJson()
                ->timeout(20 + 35 * count($urls))->connectTimeout(10)
                ->post('/v1/pages/check', ['urls' => $urls, 'width' => 1280, 'quality' => 60, 'timeout_ms' => 30000]);
            if (! $res->successful()) {
                Log::info('web shots: sidecar answered', ['status' => $res->status()]);

                return [];
            }
            $results = $res->json('results') ?? [];
        } catch (\Throwable $e) {
            Log::info('web shots: skipped', ['error' => mb_substr($e->getMessage(), 0, 160)]);

            return [];
        }

        $out = [];
        foreach ($results as $r) {
            $url = (string) ($r['url'] ?? '');
            $b64 = $r['screenshot_base64'] ?? null;
            if ($url === '' || ! is_string($b64) || $b64 === '') {
                continue;
            }
            $data = $this->encode((string) base64_decode($b64, true), $this->file($url));
            if ($data !== null) {
                $out[$url] = $data;
            }
        }

        return $out;
    }

    /** The top of the page at study size, filed on disk. */
    private function encode(string $jpeg, string $to): ?string
    {
        if (strlen($jpeg) < 5000) {
            return null;
        }
        $bin = collect(['/usr/bin/magick', '/usr/bin/convert', '/opt/homebrew/bin/magick'])
            ->first(fn (string $p) => is_executable($p));
        if ($bin === null) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'web-shot-').'.jpg';
        file_put_contents($tmp, $jpeg);
        try {
            (new Process([$bin, $tmp, '-gravity', 'North', '-crop', '1280x'.self::HEIGHT.'+0+0', '+repage',
                '-resize', self::WIDTH.'x>', '-quality', '75', '-strip', $to], null, null, null, 60))->run();
            if (! is_file($to) || filesize($to) < 1000) {
                @unlink($to);

                return null;
            }

            return 'data:image/webp;base64,'.base64_encode((string) file_get_contents($to));
        } finally {
            @unlink($tmp);
        }
    }
}
