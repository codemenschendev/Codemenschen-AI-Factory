<?php

namespace App\Console\Commands;

use App\Domain\Ai\CodexPage;
use App\Models\Prototype;
use Illuminate\Console\Command;

/**
 * App prototypes Codex drew before their screens were cut apart (2026-09-29): the same picture,
 * looked at once by Claude, shown screen by screen. Nothing is drawn again.
 */
class CutScreens extends Command
{
    protected $signature = 'factory:cut-screens {id? : one prototype; without it every drawn app that is not cut yet}';

    protected $description = 'Show the screens of drawn app prototypes one by one';

    public function handle(CodexPage $pages): int
    {
        $query = Prototype::where('kind', 'app')->where('status', 'ready')->where('html', 'like', '%<img class="mockup"%')
            ->where('html', 'not like', '%data-screens=%');
        if ($this->argument('id') !== null) {
            $query->whereKey($this->argument('id'));
        }
        foreach ($query->get() as $proto) {
            if (preg_match('~<img class="mockup" src="data:[^;]+;base64,([^"]+)"~', (string) $proto->html, $m) !== 1) {
                continue;
            }
            $html = $pages->page((string) base64_decode($m[1]), 'app');
            $cut = str_contains($html, 'data-screens=');
            if ($cut) {
                $proto->update(['html' => $html]);
            }
            $this->line($proto->id.' '.($cut ? 'cut into screens' : 'left as one picture'));
        }

        return self::SUCCESS;
    }
}
