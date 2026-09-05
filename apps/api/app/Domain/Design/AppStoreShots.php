<?php

namespace App\Domain\Design;

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
        $out = [];
        foreach (array_slice(array_values(array_filter(array_map('trim', $names))), 0, $maxApps) as $name) {
            $app = $this->lookup($name, $country);
            if ($app === null) {
                continue;
            }
            foreach (array_slice($app['screenshots'], 0, self::PER_APP) as $i => $url) {
                $data = $this->fetch($url);
                if ($data === null) {
                    continue;
                }
                $out[] = [
                    'id' => 'store:'.$app['id'].':'.$i,
                    'note' => "{$app['name']}, App Store {$country}, {$app['ratings']} ratings",
                    'data' => $data,
                    'screen_type' => 'store screenshot '.($i + 1),
                    'app' => $app['name'],
                ];
            }
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
        try {
            $res = Http::timeout(15)->connectTimeout(8)->get('https://itunes.apple.com/search', [
                'term' => $name, 'entity' => 'software', 'country' => $country, 'limit' => 5,
            ]);
            $results = $res->successful() ? ($res->json('results') ?? []) : [];
        } catch (\Throwable $e) {
            Log::info('app store: search skipped', ['app' => $name, 'error' => mb_substr($e->getMessage(), 0, 120)]);

            return null;
        }

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

    /** One screenshot as a data URI at study size, cached on disk under its URL's hash. */
    private function fetch(string $url): ?string
    {
        $dir = storage_path('app/store-shots');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $cached = $dir.'/'.sha1($url).'.webp';

        if (! is_file($cached)) {
            try {
                $res = Http::timeout(20)->get($url);
                if (! $res->successful() || strlen($res->body()) < 5000) {
                    return null;
                }
            } catch (\Throwable) {
                return null;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'store-shot-').'.png';
            file_put_contents($tmp, $res->body());
            try {
                $bin = collect(['/usr/bin/magick', '/usr/bin/convert', '/opt/homebrew/bin/magick'])
                    ->first(fn (string $p) => is_executable($p));
                if ($bin === null) {
                    return null;
                }
                (new Process([$bin, $tmp, '-resize', self::WIDTH.'x>', '-quality', '75', '-strip', $cached], null, null, null, 60))->run();
                if (! is_file($cached) || filesize($cached) < 1000) {
                    @unlink($cached);

                    return null;
                }
            } finally {
                @unlink($tmp);
            }
        }

        return 'data:image/webp;base64,'.base64_encode((string) file_get_contents($cached));
    }
}
