<?php

namespace App\Domain\Analytics;

use App\Models\AnalyticsEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * What visitors do on Appwerk, from the first page to a paid order and beyond.
 *
 * First-party and without cookies: nothing is stored on the visitor's device, so no consent banner
 * is needed. A visitor is a hash of address, browser and a random salt that is replaced every day;
 * the address and the browser string are never stored. The price of that: the same person on two
 * days counts as two visitors, and a funnel is followed within one day. Orders are tied back to
 * their quote, which carries the visitor of the day the quote was made.
 *
 * Two sources. The portal reports page views and a few clicks (POST /api/t). The API records the
 * steps that matter itself (quote, prototype, checkout, paid, sign-in, change chat), because those
 * must not depend on a browser that blocks scripts. Recording never breaks the request it sits in.
 */
class Analytics
{
    /** Events the portal may send. Anything else is dropped. */
    public const CLIENT_EVENTS = [
        'page_view', 'cta_click', 'wizard_step', 'prototype_view',
    ];

    /** Events only the API records. */
    public const SERVER_EVENTS = [
        'quote_created', 'prototype_requested', 'checkout_started', 'order_paid',
        'signin_requested', 'signin_completed', 'chat_message', 'change_confirmed', 'review_approved',
    ];

    private const BOTS = '/bot|crawl|spider|slurp|preview|headless|lighthouse|facebookexternalhit|curl|wget|python|monitor|uptime/i';

    /**
     * @param  array{path?:?string, locale?:?string, referrer?:?string, utm_source?:?string, utm_medium?:?string, utm_campaign?:?string, click_id?:?string, quote_id?:?string, order_id?:?string, customer_id?:?int}  $context
     */
    public function record(string $name, ?Request $request = null, array $context = [], array $props = []): void
    {
        try {
            if ($request !== null && preg_match(self::BOTS, (string) $request->userAgent())) {
                return;
            }
            $ua = (string) $request?->userAgent();
            AnalyticsEvent::create([
                'name' => $name,
                'visitor' => $request !== null ? $this->visitor($request) : null,
                'path' => $this->clip($context['path'] ?? null, 200),
                'locale' => in_array($context['locale'] ?? null, ['de', 'en'], true) ? $context['locale'] : null,
                'referrer' => $this->referrerHost($context['referrer'] ?? null),
                'utm_source' => $this->clip($context['utm_source'] ?? null, 80),
                'utm_medium' => $this->clip($context['utm_medium'] ?? null, 80),
                'utm_campaign' => $this->clip($context['utm_campaign'] ?? null, 120),
                'click_id' => in_array($context['click_id'] ?? null, ['gclid', 'fbclid', 'msclkid'], true) ? $context['click_id'] : null,
                'device' => $request === null ? null : (preg_match('/Mobi|Android|iPhone|iPad/i', $ua) ? 'mobile' : 'desktop'),
                'quote_id' => Str::isUuid((string) ($context['quote_id'] ?? '')) ? $context['quote_id'] : null,
                'order_id' => Str::isUuid((string) ($context['order_id'] ?? '')) ? $context['order_id'] : null,
                'customer_id' => isset($context['customer_id']) ? (int) $context['customer_id'] : null,
                'props' => $props ? array_slice($props, 0, 12, true) : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('analytics.record_failed', ['name' => $name, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    public function visitor(Request $request): string
    {
        $salt = Cache::remember('analytics:salt:'.now()->format('Y-m-d'), now()->addDays(2), fn () => Str::random(40));

        return substr(hash('sha256', $salt.'|'.$request->ip().'|'.$request->userAgent()), 0, 16);
    }

    /** Only the host of an outside referrer: which site sent them, not which page they read. */
    private function referrerHost(?string $url): ?string
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        if ($host === '' || str_ends_with($host, 'codemenschen.at')) {
            return null;
        }

        return mb_substr($host, 0, 120);
    }

    private function clip(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
