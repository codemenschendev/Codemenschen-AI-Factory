<?php

namespace App\Domain\Design;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Screenshots of the trade's best-known apps, from the App Store, for the study.
 *
 * The labelled library is where a study starts, and for a trade it barely knows (two joinery
 * screens, fourteen medical) it is not enough. Apple's search API is public JSON and every listing
 * carries the app's own store screenshots: ask for "Grab" in Vietnam and the answer is Grab, with
 * seven screens of how Grab actually looks today, rated by 1.8 million people. That is the
 * competitor a customer in Hanoi will hold the prototype up against, and it costs nothing.
 *
 * The pictures are looked at and never shown to anyone: they inform the study, the study informs
 * the prototype, and the prototype is drawn from scratch.
 */
class AppStoreShots
{
    /** Screens per app. The first three of a listing are the app's own choice of its best. */
    public const PER_APP = 3;

    /** Store screenshots are 1242px wide PNGs of half a megabyte; the model needs a fifth of that. */
    private const WIDTH = 600;

    /**
     * @param  list<string>  $names  the apps the plan named, e.g. ["Grab", "Be", "Xanh SM"]
     * @param  string  $country  ISO 3166-1 alpha-2, lower case; the store the customer's users use
     * @return list<array{id:string,note:string,data:string,screen_type:string,app:string}>
     */
    public function forApps(array $names, string $country, int $maxApps = 3): array
    {
        $names = array_slice(array_values(array_filter(array_map('trim', $names))), 0, $maxApps);
        if ($names === []) {
            return [];
        }

        // Every store search at once, then every screenshot at once. Two apps were two
        // searches and six downloads in a row, twenty seconds of the study; now they are two
        // round trips.
        $apps = array_values(array_filter($this->lookupMany($names, $country)));
        $urls = [];
        foreach ($apps as $app) {
            foreach (array_slice($app['screenshots'], 0, self::PER_APP) as $url) {
                $urls[] = $url;
            }
        }
        $data = $this->fetchMany($urls);

        $out = [];
        foreach ($apps as $app) {
            foreach (array_slice($app['screenshots'], 0, self::PER_APP) as $i => $url) {
                if (! isset($data[$url])) {
                    continue;
                }
                $out[] = [
                    'id' => 'store:'.$app['id'].':'.$i,
                    'note' => "{$app['name']}, App Store {$country}, {$app['ratings']} ratings",
                    'data' => $data[$url],
                    'screen_type' => 'store screenshot '.($i + 1),
                    'app' => $app['name'],
                ];
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $names
     * @return list<array{id:string,name:string,ratings:int,screenshots:list<string>}|null>
     */
    private function lookupMany(array $names, string $country): array
    {
        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $name) => $pool->as($name)->timeout(15)->connectTimeout(8)->get('https://itunes.apple.com/search', [
                    'term' => $name, 'entity' => 'software', 'country' => $country, 'limit' => 5,
                ]),
                $names,
            ));
        } catch (\Throwable $e) {
            Log::info('app store: search skipped', ['error' => mb_substr($e->getMessage(), 0, 120)]);

            return [];
        }

        $out = [];
        foreach ($names as $name) {
            $res = $responses[$name] ?? null;
            if (! $res instanceof Response || ! $res->successful()) {
                Log::info('app store: search skipped', ['app' => $name]);
                $out[] = null;

                continue;
            }
            $out[] = $this->pick($name, $res->json('results') ?? []);
        }

        return $out;
    }

    /**
     * The listing this name most plausibly means: among the first results, the one with the most
     * ratings whose name starts with the term, else simply the most rated. "Bolt" in Austria
     * returns the ride app and a document browser; the ride app has four times the ratings.
     *
     * @return array{id:string,name:string,ratings:int,screenshots:list<string>}|null
     */
    public function lookup(string $name, string $country): ?array
    {
        return $this->lookupMany([$name], $country)[0] ?? null;
    }

    /** @param  list<array<string,mixed>>  $results */
    private function pick(string $name, array $results): ?array
    {
        $rows = [];
        foreach ($results as $r) {
            $shots = array_values(array_filter($r['screenshotUrls'] ?? [], 'is_string'));
            if ($shots === []) {
                continue;
            }
            $title = (string) ($r['trackName'] ?? '');
            $rows[] = [
                'id' => (string) ($r['trackId'] ?? ''),
                'name' => $title,
                'ratings' => (int) ($r['userRatingCount'] ?? 0),
                'screenshots' => $shots,
                'starts' => str_starts_with(mb_strtolower($title), mb_strtolower($name)) ? 1 : 0,
            ];
        }
        if ($rows === []) {
            return null;
        }
        usort($rows, fn (array $a, array $b) => [$b['starts'], $b['ratings']] <=> [$a['starts'], $a['ratings']]);
        unset($rows[0]['starts']);

        return $rows[0];
    }

    /**
     * Every screenshot not on disk yet, downloaded together; each as a data URI at study size,
     * cached on disk under its URL's hash.
     *
     * @param  list<string>  $urls
     * @return array<string,string> url => data URI
     */
    private function fetchMany(array $urls): array
    {
        $dir = storage_path('app/store-shots');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = fn (string $url) => $dir.'/'.sha1($url).'.webp';
        $urls = array_values(array_unique($urls));

        $missing = array_values(array_filter($urls, fn (string $u) => ! is_file($file($u))));
        if ($missing !== []) {
            try {
                $responses = Http::pool(fn (Pool $pool) => array_map(
                    fn (string $u) => $pool->as($u)->timeout(20)->get($u), $missing,
                ));
            } catch (\Throwable) {
                $responses = [];
            }
            $bin = collect(['/usr/bin/magick', '/usr/bin/convert', '/opt/homebrew/bin/magick'])
                ->first(fn (string $p) => is_executable($p));
            foreach ($missing as $u) {
                $res = $responses[$u] ?? null;
                if ($bin === null || ! $res instanceof Response || ! $res->successful() || strlen($res->body()) < 5000) {
                    continue;
                }
                $tmp = tempnam(sys_get_temp_dir(), 'store-shot-').'.png';
                file_put_contents($tmp, $res->body());
                try {
                    (new Process([$bin, $tmp, '-resize', self::WIDTH.'x>', '-quality', '75', '-strip', $file($u)], null, null, null, 60))->run();
                    if (! is_file($file($u)) || filesize($file($u)) < 1000) {
                        @unlink($file($u));
                    }
                } finally {
                    @unlink($tmp);
                }
            }
        }

        $out = [];
        foreach ($urls as $u) {
            if (is_file($file($u))) {
                $out[$u] = 'data:image/webp;base64,'.base64_encode((string) file_get_contents($file($u)));
            }
        }

        return $out;
    }
}
