<?php

namespace App\Console\Commands;

use App\Models\Prototype;
use Illuminate\Console\Command;

/**
 * Where prototype pictures came from, and what that cost.
 *
 * The question this answers is "are we still paying for photographs", which nobody could answer
 * before: an empty credit meant either the shared library or a generation, and those differ by the
 * price of a call.
 */
class PhotoSources extends Command
{
    protected $signature = 'factory:photo-sources {--days=7}';

    protected $description = 'Count where prototype photos came from: the library, Pexels, or generation';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $rows = Prototype::whereNotNull('qa')->where('created_at', '>=', now()->subDays($days))->get();

        $count = ['library' => 0, 'stock' => 0, 'generated' => 0, 'none' => 0, 'unknown' => 0];
        foreach ($rows as $p) {
            $qa = (array) $p->qa;
            if (! array_key_exists('photo', $qa)) {
                continue;   // a site or an ads prototype: no picture band, nothing to count
            }
            $source = $qa['photo_source'] ?? null;
            if ($source === null) {
                // Built before the source was recorded, or nothing free matched and the band kept
                // its gradient. Reported as its own row rather than guessed at.
                $count[$qa['photo'] === null ? 'none' : 'unknown']++;

                continue;
            }
            $count[$source] = ($count[$source] ?? 0) + 1;
        }

        $total = array_sum($count);
        $this->table(
            ['source', 'prototypes', 'share'],
            collect($count)->map(fn (int $n, string $k) => [
                $k, $n, $total === 0 ? '' : round($n * 100 / $total).' %',
            ])->values()->all()
        );

        $this->line($count['generated'] === 0
            ? "  Nothing was generated in the last {$days} days."
            : "  {$count['generated']} generated in the last {$days} days: that is what the free tier cost.");

        if (! config('services.stock.generate_for_prototypes')) {
            $this->line('  Generation for prototypes is OFF (PROTOTYPE_GENERATE_PHOTOS).');
        }

        return self::SUCCESS;
    }
}
