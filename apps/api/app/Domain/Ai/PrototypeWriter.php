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

        The page is a clickable PROTOTYPE of what the visitor described. Rules:
        - One self-contained file. All CSS in a <style> tag. No external URLs, fonts, images or
          scripts: use system fonts, CSS gradients and inline SVG for any graphics.
        - Exactly: a nav, a hero, THREE sections, and a footer. Not more. This is a first
          impression, not a finished site, and the visitor is watching a spinner while you write.
        - Keep the whole file under 300 lines. Write compact CSS: group selectors, skip decorative
          rules that do not change the look at this size.
        - Clickable via in-page anchors (nav jumps to sections). A little vanilla JS is allowed for
          things like a mobile menu, but the page must make sense with JS switched off.
        - Real, specific copy about the visitor's idea, in their language. No lorem ipsum.
        - Responsive. No cookie banners, no fake login, no forms that pretend to submit anywhere.
        TXT;

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
            // A page this size is ~12k tokens of HTML. The cap keeps a chatty model from writing
            // a 40 KB page that takes three minutes: the sidecar gives up at 180s, and a visitor
            // watching a spinner gives up sooner.
            'max_completion_tokens' => 14000,
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

        return ['title' => $this->titleOf($html), 'html' => $html];
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
