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

    /** Pictures offered to the builder. More is a list the model skims, and each is a download. */
    private const MAX_IMAGES = 20;

    /** Of those, how many big enough ones the builder is shown. */
    private const OFFERED = 12;

    /** A picture bigger than this is a video poster or an unoptimised original, not worth the wait. */
    private const MAX_IMAGE_BYTES = 6_000_000;

    /** File names that are page furniture, not pictures of what is sold. */
    private const NOT_PICTURES = '~(icon|favicon|sprite|arrow|chevron|avatar|badge|flag|star|rating|payment|paypal|visa|mastercard|stripe|spinner|loader|placeholder|blank|pixel|emoji|social|facebook|instagram|twitter|linkedin|youtube|whatsapp|check|close|menu|search|cart|bg-pattern)~i';

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

    /**
     * @return array{url:string,text:string,images:list<array{url:string,alt:string}>,logo:?string}|null
     *                                                                                                   the page as text with its own pictures, or null when it cannot be read
     */
    public function read(string $domain): ?array
    {
        $key = 'product-page:v3:'.sha1($domain);
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
                $html = substr($res->body(), 0, self::MAX_BYTES);
                $text = self::text($html);
                if (mb_strlen($text) < 80) {
                    return false;
                }
                [$images, $logo] = self::images($html, $url, $domain);

                return ['url' => $url, 'text' => $text, 'images' => $this->bigEnough($images, $domain), 'logo' => $logo];
            }
        } catch (\Throwable $e) {
            Log::info('product page: not read', ['domain' => $domain, 'error' => mb_substr($e->getMessage(), 0, 160)]);
        }

        return false;
    }

    /**
     * One of the business's own pictures, as bytes, or null.
     *
     * Only from the domain the customer named or its subdomains, only a public host, redirects
     * checked hop by hop like the page itself, only an image, and capped.
     */
    public function download(string $url, string $domain): ?string
    {
        try {
            for ($hop = 0; $hop < 3; $hop++) {
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                if (! self::onDomain($host, $domain) || ! $this->isPublic($host)) {
                    return null;
                }
                $res = Http::withOptions(['allow_redirects' => false])
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; AppwerkBot/1.0; +https://appwerk.codemenschen.at)'])
                    ->timeout(15)->connectTimeout(8)->get($url);
                if ($res->redirect() && ($to = $res->header('Location')) !== '') {
                    $url = self::absolute($to, $url) ?? '';

                    continue;
                }
                $type = strtolower($res->header('Content-Type'));
                if (! $res->successful() || ! str_starts_with($type, 'image/') || strlen($res->body()) > self::MAX_IMAGE_BYTES) {
                    return null;
                }

                return $res->body();
            }
        } catch (\Throwable $e) {
            Log::info('product page: picture not read', ['url' => mb_substr($url, 0, 160), 'error' => mb_substr($e->getMessage(), 0, 160)]);
        }

        return null;
    }

    /**
     * Only pictures big enough to fill a slot, with their size for the builder to choose by.
     *
     * A name says nothing about size: wp-giftcard.com's "gift-card 4.png" is a 679-byte icon, the
     * builder picked it for the welcome e-mail and the slot fell back to a stock photograph. The
     * read is cached for a day, so this is paid once per site.
     *
     * @param  list<array{url:string,alt:string}>  $images
     * @return list<array{url:string,alt:string,size:string}>
     */
    private function bigEnough(array $images, string $domain): array
    {
        $kept = [];
        foreach ($images as $img) {
            if (count($kept) >= self::OFFERED) {
                break;
            }
            $bytes = $this->download($img['url'], $domain);
            $size = $bytes === null ? false : @getimagesizefromstring($bytes);
            if ($size !== false && $size[0] >= 300 && $size[1] >= 160) {
                $kept[] = $img + ['size' => $size[0].'x'.$size[1]];
            }
        }

        return $kept;
    }

    /**
     * The page's own pictures and its logo, in page order.
     *
     * @return array{0:list<array{url:string,alt:string}>,1:?string}
     */
    public static function images(string $html, string $pageUrl, string $domain): array
    {
        $doc = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xp = new \DOMXPath($doc);

        $fold = fn (string $s) => mb_substr(trim(preg_replace('/\s+/u', ' ', $s) ?? $s), 0, 120);
        $images = [];
        $logo = null;
        $seen = [];
        $add = function (string $src, string $alt, string $hint) use (&$images, &$logo, &$seen, $pageUrl, $domain, $fold): void {
            $url = self::absolute($src, $pageUrl);
            if ($url === null || isset($seen[$url]) || ! self::onDomain(strtolower((string) parse_url($url, PHP_URL_HOST)), $domain)) {
                return;
            }
            $seen[$url] = true;
            $file = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($logo === null && preg_match('~logo~i', $file.' '.$hint) === 1 && in_array($ext, ['svg', 'png', 'webp', 'jpg', 'jpeg'], true)) {
                $logo = $url;

                return;
            }
            if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || preg_match(self::NOT_PICTURES, $file) === 1
                || preg_match('~logo~i', $file) === 1 || count($images) >= self::MAX_IMAGES) {
                return;
            }
            $images[] = ['url' => $url, 'alt' => $fold($alt !== '' ? $alt : pathinfo($file, PATHINFO_FILENAME))];
        };

        $og = $xp->query("//meta[@property='og:image' or @name='og:image']/@content")->item(0)?->nodeValue;
        if ($og) {
            $add($og, (string) $xp->query("//meta[@property='og:title']/@content")->item(0)?->nodeValue, 'og');
        }
        foreach ($xp->query('//img') as $img) {
            /** @var \DOMElement $img */
            $w = (int) $img->getAttribute('width');
            $h = (int) $img->getAttribute('height');
            $hint = $img->getAttribute('class').' '.$img->getAttribute('alt').' '.$img->getAttribute('id');
            $src = $img->getAttribute('src');
            // Lazy loaders keep the real picture in data-src and a grey pixel in src.
            foreach (['data-src', 'data-lazy-src', 'data-original'] as $lazy) {
                if ($img->getAttribute($lazy) !== '') {
                    $src = $img->getAttribute($lazy);
                }
            }
            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }
            // Declared small is a thumbnail or an icon; the logo is small and still wanted.
            if (($w > 0 && $w < 200 || $h > 0 && $h < 120) && preg_match('~logo~i', $src.' '.$hint) !== 1) {
                continue;
            }
            $add($src, $img->getAttribute('alt'), $hint);
        }

        return [$images, $logo];
    }

    private static function absolute(string $src, string $base): ?string
    {
        $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5));
        if ($src === '') {
            return null;
        }
        if (str_starts_with($src, '//')) {
            $src = 'https:'.$src;
        }
        if (! preg_match('~^https?://~i', $src)) {
            $root = preg_replace('~^(https?://[^/]+).*$~i', '$1', $base) ?? $base;
            $src = str_starts_with($src, '/') ? $root.$src
                : preg_replace('~/[^/]*$~', '/', $base).$src;
        }
        $parts = parse_url($src);
        if (! isset($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }
        // A space in a file name ("gift-card 4.png") is legal on the page and not in a request.
        return str_replace(' ', '%20', $src);
    }

    private static function onDomain(string $host, string $domain): bool
    {
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return $host === $domain || str_ends_with($host, '.'.$domain);
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
