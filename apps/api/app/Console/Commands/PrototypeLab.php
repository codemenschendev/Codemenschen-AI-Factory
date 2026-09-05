<?php

namespace App\Console\Commands;

use App\Domain\Ai\PrototypeWriter;
use App\Domain\Design\DesignLibrary;
use App\Domain\Qa\PageAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Generate one prototype from a named prompt variant and audit it, without touching the queue.
 *
 * For settling arguments about the prompt with evidence. The house style caps how distinctive a
 * page can be, which is deliberate for a product and arguable for a sales preview, and the only
 * honest way to compare the two is to build the same brief both ways and look.
 *
 * Writes the page to a file and prints what the audit found, how long it took and how big it is.
 */
class PrototypeLab extends Command
{
    protected $signature = 'factory:prototype-lab {brief} {--kind=app} {--style=house} {--out=}';

    protected $description = 'Build one prototype from a prompt variant and audit it (house|free)';

    /**
     * The free variant. Everything structural survives, because the structure is what the
     * reference library taught and what the photo pipeline hooks into; only the look is handed
     * back to the model.
     */
    private const FREE = <<<'TXT'
        You are a product designer who outputs ONE complete HTML file and nothing else. You never
        create files, never run commands, never fetch anything: your whole reply is the HTML, from
        <!doctype html> to </html>, with no prose and no code fence around it.

        THIS IS THE APP, NOT A PAGE ABOUT THE APP. The whole document is one phone screen, shown
        inside a phone. No navigation bar, no marketing hero, no footer, no bezel of your own.

        YOU WRITE THE CSS. One <style> block in the head, and design it properly: choose a type
        scale, a colour, a rhythm and a radius that suit THIS trade. A dentist is not a bakery is
        not a carpenter. Aim for the work of a designer who has an opinion, not a template.

        Keep these class names, because the page is fed to other tools that look for them. Style
        them however you like:

          body.app-page          the page
          .app                   the phone-width column, max 430px, centred, full height
          .screen                one screen; only the checked one is shown
          .screen-bar            the screen's title
          .tabbar / label        the bottom tab bar
          .app-art               a wide picture band. WRITE INSIDE IT what the picture would show.
          .app-thumb             a small square picture on a row. Same: write the brief inside.
          .app-cover             a picture at the top of a card. Same.

        Screens switch with radio inputs and a sibling selector, so it works with scripting off:

          <div class="app">
            <input type="radio" name="screen" id="s1" checked> … four of them …
            <section class="screen"> … </section> … four of them, same order …
            <nav class="tabbar"><label for="s1">Start</label> … four, same order … </nav>
          </div>

        Write the CSS that shows screen N when input N is checked, and that marks tab N.

        Rules:
          - Real content everywhere: actual service names, times, prices, places from the idea.
            No "Item 1", no lorem ipsum.
          - Four or five blocks per screen. A phone is small.
          - No external URLs, fonts, images or scripts. Everything inline. It must work offline.
          - The first screen ends without a call to action: the tab bar is its navigation.
          - Plain sentences. Never a dash as a sentence break.
          - Invent no prices, percentages or guarantees as facts about the business.
        TXT;

    public function handle(PrototypeWriter $writer, PageAudit $audit): int
    {
        $brief = (string) $this->argument('brief');
        $style = (string) $this->option('style');
        $out = (string) ($this->option('out') ?: sys_get_temp_dir()."/lab-{$style}-".time().'.html');

        $started = microtime(true);

        if ($style === 'house') {
            $result = $writer->build($brief, (string) $this->option('kind'), null, $audit, app(DesignLibrary::class));
            $html = $result['html'];
            $qa = $result['qa'];
        } else {
            $html = $this->free($brief);
            $qa = $audit->run($html);
        }

        file_put_contents($out, $html);
        $seconds = round(microtime(true) - $started);

        $blocking = PageAudit::blocking($qa);
        $this->table(['', ''], [
            ['style', $style],
            ['seconds', $seconds],
            ['size', round(strlen($html) / 1024).' KB'],
            ['audit', ($qa['ok'] ?? null) === true ? 'clean' : count($blocking).' blocking'],
            ['findings', implode(', ', array_unique(array_column($blocking, 'check'))) ?: '-'],
            ['file', $out],
        ]);

        return self::SUCCESS;
    }

    /** The free variant goes straight to the gateway: no house stylesheet, no inlining. */
    private function free(string $brief): string
    {
        $res = Http::baseUrl(rtrim((string) config('services.ai_image.base_url'), '/'))
            ->withToken((string) config('services.ai_image.token'))
            ->withHeaders(array_filter(['x-openclaw-model' => (string) config('services.ai_image.chat_backend_model')]))
            ->acceptJson()->timeout(240)->connectTimeout(10)
            ->post('/v1/chat/completions', [
                'model' => config('services.ai_image.chat_model', 'openclaw/main'),
                'messages' => [
                    ['role' => 'system', 'content' => self::FREE],
                    ['role' => 'user', 'content' => "Build a prototype for:\n\n{$brief}\n\nReply with the HTML file only."],
                ],
                'max_completion_tokens' => 16000,
            ]);

        $text = (string) $res->json('choices.0.message.content');
        preg_match('~<!doctype html.*</html>~is', $text, $m);

        return $m[0] ?? $text;
    }
}
