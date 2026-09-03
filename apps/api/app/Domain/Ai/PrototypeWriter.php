<?php

namespace App\Domain\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Turns a sentence into one self-contained HTML page: the clickable prototype.
 *
 * Goes through the same host sidecar as the other AI here. As with AdScriptWriter, the endpoint is
 * an AGENT with tools, so the prompt is written to keep it writing markup rather than running off
 * to build or deploy something: "you only write one HTML file, you never create files or run
 * anything".
 *
 * The output is untrusted and is only ever served inside a sandboxed, cross-origin iframe, so this
 * does not try to sanitise it. It does insist on a single self-contained file (inline CSS, no
 * external requests) both because the sandbox blocks external loads anyway and because a
 * prototype should look the same the day it expires as the day it was made.
 */
class PrototypeWriter
{
    private const SYSTEM = <<<'TXT'
        You are a front-end designer who outputs ONE complete HTML file and nothing else. You never
        create files, never run commands, never fetch anything: your whole reply is the HTML, from
        <!doctype html> to </html>, with no prose and no code fence around it.

        A house stylesheet is already loaded. You WRITE MARKUP, NOT CSS. Put this exact line in the
        head and nothing else for styling:

            <link rel="stylesheet" href="house.css">

        Use the classes below and no others. Do not restate their rules, do not add a <style> block
        for colour, spacing, type size, shadows or radius: those are decided. Write at most a
        handful of declarations, and only for something genuinely specific to this page.

        Choose ONE palette and put it on the body, matching the trade:
          t-slate (professional services, finance, B2B) · t-forest (trades, nature, health, food)
          t-amber (hospitality, bakery, workshop, craft) · t-indigo (software, agency, tech)
          t-rose (beauty, salon, care, boutique)

        Structure, in this order, and nothing more:
          <nav class="nav"><div class="nav-inner"><a class="brand">…</a>
            <div class="nav-links"><a href="#…">…</a>… <a class="btn btn-primary">…</a></div></div></nav>
          <header class="hero"><div class="container"> span.eyebrow, h1, p.lead,
            div.hero-actions with one .btn.btn-primary and one .btn.btn-ghost </div></header>
          THREE <section class="section"> (give the middle one class="section alt"), each with
            <div class="container">, a .section-head (h2 plus p.lead) and then its content
          <section class="section"><div class="container"><div class="cta">…</div></div></section>
          <footer class="footer"><div class="container"><div class="footer-inner">…</div></div></footer>

        Blocks to build the three sections from, one kind per section:
          .grid of .card, each with .icon (one inline SVG, stroked, no fill), h3, p
          .split of a text column and a .card
          .stats of .stat-n plus .stat-l
          .grid of .card with .price (b for the number) and ul.list, one card .featured
            with data-tag="…"
          blockquote.quote plus p.quote-by

        Rules that still hold:
          - No external URLs, fonts, images or scripts. Icons are inline SVG using the .icon block.
          - Real, specific copy about the visitor's idea, in their language. No lorem ipsum. Give
            the business a plausible name and use it.
          - Never invent prices, percentages, awards or customer quotes as facts. A testimonial is
            fine as obvious placeholder wording, a "40% cheaper" claim is not.
          - Plain sentences. Never use a dash as a sentence break: no em dash, and no spaced en
            dash either. A comma, a colon or a full stop says the same thing and does not read
            like it was written by a machine.
          - Nav anchors jump to the sections. The page must work with JavaScript switched off.
          - No cookie banner, no fake login, no form that pretends to submit.
        TXT;

    /** The house stylesheet, read once per request and inlined into whatever the model returns. */
    private function house(): string
    {
        return (string) file_get_contents(resource_path('design/house.css'));
    }

    /** @return array{title:string,html:string} */
    public function build(string $prompt): array
    {
        $baseUrl = rtrim((string) config('services.ai_image.base_url'), '/');
        $token = (string) config('services.ai_image.token');

        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('Chưa cấu hình dịch vụ AI.');
        }

        $request = Http::baseUrl($baseUrl)->withToken($token)->acceptJson()->timeout(240)->connectTimeout(10);
        $backend = (string) config('services.ai_image.chat_backend_model');
        if ($backend !== '') {
            $request = $request->withHeaders(['x-openclaw-model' => $backend]);
        }

        $res = $request->post('/v1/chat/completions', [
            'model' => config('services.ai_image.chat_model', 'openclaw/main'),
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM],
                ['role' => 'user', 'content' => "Build a prototype for:\n\n{$prompt}\n\nReply with the HTML file only."],
            ],
            // Markup only now: the house stylesheet is inlined afterwards, so the model is not
            // paying tokens to invent CSS. That roughly halves what it has to write, which is the
            // difference between a visitor waiting and a visitor leaving.
            'max_completion_tokens' => 8000,
        ]);

        if (! $res->successful()) {
            // The sidecar gives up on the gateway at CHAT_TIMEOUT_MS (180s) and answers 502. That
            // is a slow generation, not a broken service, and it is worth saying so plainly.
            $msg = $res->status() === 502
                ? 'Bản mô tả quá lớn nên dựng lâu hơn giới hạn. Thử mô tả ngắn gọn hơn.'
                : 'Dựng prototype thất bại ('.$res->status().').';
            throw new RuntimeException($msg);
        }

        $html = $this->extractHtml((string) $res->json('choices.0.message.content'));
        if ($html === '') {
            throw new RuntimeException('AI không trả về HTML dùng được.');
        }
        $html = $this->inlineHouse($html);

        return ['title' => $this->titleOf($html), 'html' => $html];
    }

    /**
     * Puts the house stylesheet into the page and takes out the placeholder link.
     *
     * The model is told to write one <link> and no CSS, but it is an agent and it improvises. So
     * this does not trust the link to be there: it strips any reference to house.css and inserts
     * the real thing at whatever anchor the document actually has. A prototype with no styling at
     * all is the one outcome worth ruling out.
     */
    private function inlineHouse(string $html): string
    {
        $html = preg_replace('~<link[^>]*house\.css[^>]*>~i', '', $html) ?? $html;
        $style = '<style>'."\n".$this->house()."\n".'</style>';

        foreach (['</head>' => 0, '<body' => 0] as $needle => $_) {
            $at = stripos($html, $needle);
            if ($at !== false) {
                return substr($html, 0, $at).$style.substr($html, $at);
            }
        }

        return $style.$html;
    }

    private function extractHtml(string $text): string
    {
        // Strip a code fence if the model added one anyway.
        if (preg_match('/```(?:html)?\s*(.*?)```/is', $text, $m) === 1) {
            $text = $m[1];
        }
        $lower = strtolower($text);
        $start = strpos($lower, '<!doctype');
        if ($start === false) {
            $start = strpos($lower, '<html');
        }
        $end = strrpos($lower, '</html>');
        if ($start === false || $end === false) {
            return '';
        }

        return trim(substr($text, $start, $end - $start + strlen('</html>')));
    }

    private function titleOf(string $html): string
    {
        if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m) === 1) {
            return mb_substr(trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5)), 0, 120);
        }

        return 'Prototype';
    }
}
