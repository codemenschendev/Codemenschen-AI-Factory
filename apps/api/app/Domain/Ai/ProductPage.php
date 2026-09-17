<?php

namespace App\Domain\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The business's own homepage, read as text, when the customer's sentence names its domain.
 *
 * "make the banner for wp-giftcard.com" produced five ads for a service that buys gift cards for
 * cash, because the name was all the model had. wp-giftcard is a WordPress plugin that lets a
 * business sell its own vouchers. A designer opens the site before drawing a single ad; this is
 * that step. It reads, it does not render: the words on the page are what say what is sold.
 *
 * The page is someone else's text and goes to the model as data. Only public hosts are fetched,
 * redirects are followed by hand so every hop is checked, and the body is capped.
 */
class ProductPage
{
    /** What the model reads of the page. A homepage says what it sells in its first screens. */
    private const TEXT_CHARS = 6000;

    private const MAX_BYTES = 2_000_000;

    /** @param  ?\Closure(string):list<string>  $resolve  host to IPs; tests pass their own */
    public function __construct(private ?\Closure $resolve = null) {}

    /** File names look like domains to a regex: "index.html" is not a business. */
    private const NOT_TLDS = ['html', 'htm', 'php', 'js', 'css', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'pdf', 'md', 'json', 'txt', 'zip', 'webp', 'mp4'];

    /** The first domain or URL the sentence names, lower case, without a path. */
    public static function domainIn(string $prompt): ?string
    {
        $prompt = preg_replace('/\S+@\S+/', ' ', $prompt) ?? $prompt;
        if (preg_match_all('~(?:https?://)?((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+([a-z]{2,24}))(?![a-z0-9-])~i', $prompt, $m, PREG_SET_ORDER) === 0) {
            return null;
        }
        foreach ($m as $hit) {
            if (! in_array(strtolower($hit[2]), self::NOT_TLDS, true)) {
                return strtolower(preg_replace('/^www\./i', '', $hit[1]) ?? $hit[1]);
            }
        }

        return null;
    }

    /**
     * True when the sentence says too little about the product to write ads without the site:
     * the domain and a handful of words like "make the banner for".
     */
    public static function sentenceIsThin(string $prompt, string $domain): bool
    {
        $rest = str_ireplace(['https://', 'http://', 'www.'.$domain, $domain], ' ', $prompt);
        $words = preg_split('/[^\p{L}\p{N}]+/u', $rest, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($words) <= 8;
    }

    /** @return array{url:string,text:string}|null the page as text, or null when it cannot be read */
    public function read(string $domain): ?array
    {
        $key = 'product-page:'.sha1($domain);
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached ?: null;
        }
        $page = $this->fetch($domain);
        // A site that answered is kept a day; one that did not is tried again in ten minutes,
        // because the usual reason is a slow host, not a missing one.
        Cache::put($key, $page, $page === false ? now()->addMinutes(10) : now()->addDay());

        return $page ?: null;
    }

    /** @return array{url:string,text:string}|false false so a failed read is cached too, briefly enough */
    private function fetch(string $domain): array|false
    {
        $url = "https://{$domain}/";
        try {
            for ($hop = 0; $hop < 4; $hop++) {
                if (! $this->isPublic((string) parse_url($url, PHP_URL_HOST))) {
                    return false;
                }
                $res = Http::withOptions(['allow_redirects' => false, 'stream' => false])
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; AppwerkBot/1.0; +https://appwerk.codemenschen.at)', 'Accept-Language' => 'de,en;q=0.8'])
                    ->timeout(15)->connectTimeout(8)->get($url);
                if ($res->redirect() && ($to = $res->header('Location')) !== '') {
                    $url = str_starts_with($to, 'http') ? $to : rtrim($url, '/').'/'.ltrim($to, '/');

                    continue;
                }
                if (! $res->successful() || ! str_contains(strtolower($res->header('Content-Type')), 'html')) {
                    return false;
                }
                $text = self::text(substr($res->body(), 0, self::MAX_BYTES));

                return mb_strlen($text) < 80 ? false : ['url' => $url, 'text' => $text];
            }
        } catch (\Throwable $e) {
            Log::info('product page: not read', ['domain' => $domain, 'error' => mb_substr($e->getMessage(), 0, 160)]);
        }

        return false;
    }

    private function isPublic(string $host): bool
    {
        if ($host === '' || ! str_contains($host, '.')) {
            return false;
        }
        $ips = $this->resolve !== null ? ($this->resolve)($host) : (gethostbynamel($host) ?: []);

        return $ips !== [] && array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) === [];
    }

    /** Title, descriptions, headings first, then the visible text, whitespace folded. */
    public static function text(string $html): string
    {
        $doc = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xp = new \DOMXPath($doc);
        foreach (iterator_to_array($xp->query('//script|//style|//noscript|//svg|//template|//iframe')) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $fold = fn (string $s) => trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        $lines = [];
        $title = $xp->query('//title')->item(0)?->textContent;
        if ($title) {
            $lines[] = 'Title: '.$fold($title);
        }
        foreach (['description', 'og:description', 'og:title'] as $name) {
            $content = $xp->query("//meta[@name='{$name}' or @property='{$name}']/@content")->item(0)?->nodeValue;
            if ($content) {
                $lines[] = ucfirst(str_replace('og:', '', $name)).': '.$fold($content);
            }
        }
        $headings = array_filter(array_map(fn ($n) => $fold($n->textContent), iterator_to_array($xp->query('//h1|//h2|//h3'))));
        if ($headings !== []) {
            $lines[] = 'Headings: '.implode(' | ', array_slice(array_unique($headings), 0, 25));
        }
        $body = $xp->query('//body')->item(0);
        if ($body) {
            $lines[] = 'Text: '.$fold($body->textContent);
        }

        return mb_substr(implode("\n", $lines), 0, self::TEXT_CHARS);
    }
}
