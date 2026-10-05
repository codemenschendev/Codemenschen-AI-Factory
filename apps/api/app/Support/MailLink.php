<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * A signed link for an e-mail. The customer reads the address before clicking it, so it carries
 * the storefront's own domain (appmitki.com, which passes /api/ on to the API) instead of the
 * API's technical host. Without MAIL_LINK_URL the link is built on the host of the request, as
 * before: a local setup has no proxy in front of its API.
 *
 * When the storefront serves the API too, the link opens a page of the storefront
 * (/{locale}/signin/...) and nothing happens until the visitor presses its button, which posts
 * the same signed address. A link that signs in on a plain GET and bounces on with a token is
 * what phishing filters look for (Google flagged appmitki.com, 2026-09-30), and a mail scanner
 * that opens every link would spend it before the customer does.
 */
class MailLink
{
    public static function signed(string $route, DateTimeInterface $expires, array $params): string
    {
        $root = self::root();
        if ($root === '') {
            return URL::temporarySignedRoute($route, $expires, $params);
        }
        // The scheme too: the root alone keeps the request's, and the API is reached over http
        // behind its proxy.
        URL::forceRootUrl($root);
        URL::forceScheme((string) parse_url($root, PHP_URL_SCHEME) ?: 'https');
        try {
            $url = URL::temporarySignedRoute($route, $expires, $params);
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme(null);
        }

        return self::paged() ? self::page($url, (string) ($params['locale'] ?? 'de')) : $url;
    }

    /**
     * A signed GET the API still receives (a link mailed before the page existed, or typed in):
     * the storefront's page for it, or null when there is none and the GET goes on as before.
     */
    public static function pageFor(Request $request): ?string
    {
        if (! $request->isMethod('get') || ! self::paged()) {
            return null;
        }

        return self::page(self::root().'/'.ltrim($request->getRequestUri(), '/'), (string) $request->query('locale', 'de'));
    }

    /** Hands the new token to the page: JSON for the page's POST, a redirect for a plain GET. */
    public static function handOff(Request $request, string $path, string $token): JsonResponse|RedirectResponse
    {
        if ($request->isMethod('post')) {
            // The console is another origin: the sign-in page then hands the token over by address.
            return response()->json(['to' => str_starts_with(self::portal($path), 'http') && config('console.url') ? self::portal($path) : $path, 'token' => $token]);
        }

        return redirect()->away(self::portal($path).'#token='.$token);
    }

    /**
     * Where a customer's own pages live: the console when it is set up (config/console.php),
     * otherwise the storefront. The admin console always stays on the storefront.
     */
    public static function portal(string $path): string
    {
        $console = rtrim((string) config('console.url'), '/');
        $customer = (bool) preg_match('#^/(de|en)/account(/|$)#', $path);

        return ($console !== '' && $customer ? $console : rtrim((string) config('services.frontend_url'), '/')).$path;
    }

    private static function root(): string
    {
        return rtrim((string) config('services.mail_link_url'), '/');
    }

    /** Only where the storefront and the mail link share a host: the page posts to its own origin. */
    private static function paged(): bool
    {
        $root = self::root();
        $front = rtrim((string) config('services.frontend_url'), '/');

        return $root !== '' && parse_url($root, PHP_URL_HOST) === parse_url($front, PHP_URL_HOST);
    }

    private static function page(string $url, string $locale): string
    {
        $locale = in_array($locale, ['de', 'en'], true) ? $locale : 'de';

        return self::root()."/$locale/signin/".ltrim(substr($url, strlen(self::root().'/api/')), '/');
    }
}
