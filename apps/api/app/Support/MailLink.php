<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Facades\URL;

/**
 * A signed link for an e-mail. The customer reads the address before clicking it, so it carries
 * the storefront's own domain (appmitki.com, which passes /api/ on to the API) instead of the
 * API's technical host. Without MAIL_LINK_URL the link is built on the host of the request, as
 * before: a local setup has no proxy in front of its API.
 */
class MailLink
{
    public static function signed(string $route, DateTimeInterface $expires, array $params): string
    {
        $root = rtrim((string) config('services.mail_link_url'), '/');
        if ($root === '') {
            return URL::temporarySignedRoute($route, $expires, $params);
        }
        // The scheme too: the root alone keeps the request's, and the API is reached over http
        // behind its proxy.
        URL::forceRootUrl($root);
        URL::forceScheme((string) parse_url($root, PHP_URL_SCHEME) ?: 'https');
        try {
            return URL::temporarySignedRoute($route, $expires, $params);
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme(null);
        }
    }
}
