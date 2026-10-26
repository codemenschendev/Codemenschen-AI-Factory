<?php

namespace App\Domain\Ads;

use App\Jobs\SendAdConversion;
use App\Models\AdConversion;
use App\Models\Prototype;
use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Conversion tracking (2026-09-23): results reported back to the ad platform that brought the
 * visitor, so Meta and Google learn which ads bring people who ask and buy, not only people who click.
 *
 * Only for a visitor who allowed ad measurement on the site: without that the browser sends no
 * click and nothing here runs. Two results are reported: a lead (a quote or a prototype asked for)
 * and a purchase (an order paid, with its amount). Meta gets them through the Conversions API,
 * Google as click conversions. Each is a row first and a platform call second, so a refusal or a
 * platform that is not set up yet is visible in the console and sent again later.
 */
class Conversions
{
    /** How long after the event a platform still takes it. */
    private const WINDOW_DAYS = ['meta' => 7, 'google' => 90];

    public const MAX_ATTEMPTS = 5;

    /** A quote or a prototype was asked for by a visitor who came from an ad. */
    public function lead(array $click, Request $request, ?Quote $quote = null, ?Prototype $prototype = null, ?string $email = null): void
    {
        $this->record('lead', $click, self::match($email, $request->ip(), $request->userAgent()), $quote, $prototype, null);
    }

    /**
     * The steps between a page view and a lead, for Meta only (2026-10-26, Patrick asked whether the
     * landing page's events reach Facebook). The browser sends the same step to the pixel with the same
     * event id, so Meta keeps one of the two. Only called for a visitor who allowed ad measurement.
     */
    public const FUNNEL = ['ViewContent', 'InitiateCheckout', 'ChatOpen', 'ChatMessage', 'CTAClick', 'Scroll75'];

    /** @param array{fbclid?:?string,clicked_at?:?int,fbp?:?string,fbc?:?string,url?:?string,value?:?float} $data */
    public function funnel(string $event, string $eventId, Request $request, array $data): ?AdConversion
    {
        if (! in_array($event, self::FUNNEL, true)) {
            return null;
        }
        $fbclid = preg_match('~^[A-Za-z0-9_\-]{8,500}$~', (string) ($data['fbclid'] ?? '')) === 1 ? $data['fbclid'] : null;
        $fbp = preg_match('~^fb\.\d\.\d{10,16}\.\d{1,25}$~', (string) ($data['fbp'] ?? '')) === 1 ? $data['fbp'] : null;
        $fbc = preg_match('~^fb\.\d\.\d{10,16}\.[A-Za-z0-9_\-]{8,500}$~', (string) ($data['fbc'] ?? '')) === 1 ? $data['fbc'] : null;
        // Nothing to match the event to a person on Meta's side: not worth a call.
        if ($fbclid === null && $fbp === null && $fbc === null) {
            return null;
        }
        $ms = (int) ($data['clicked_at'] ?? 0);
        $row = AdConversion::firstOrCreate(['event_id' => $eventId], [
            'platform' => 'meta',
            'event' => $event,
            'click_id' => $fbclid,
            'clicked_at' => $fbclid && $ms > 0 ? date(DATE_ATOM, min(time(), (int) floor($ms / 1000))) : null,
            'happened_at' => now(),
            'value_eur' => isset($data['value']) && is_numeric($data['value']) ? round(min((float) $data['value'], 100000), 2) : null,
            'match' => array_filter(self::match(null, $request->ip(), $request->userAgent()) + ['fbp' => $fbp, 'fbc' => $fbc]),
            'source_url' => str_starts_with((string) ($data['url'] ?? ''), 'https://') ? mb_substr((string) $data['url'], 0, 500) : null,
        ]);
        if ($row->wasRecentlyCreated) {
            SendAdConversion::dispatch($row->id);
        }

        return $row;
    }

    /** An order was paid. The click comes from its quote, kept there when the quote was made. */
    public function purchase(Quote $quote, float $valueEur, ?string $email): void
    {
        $click = $quote->ad_click;
        if (! is_array($click) || $click === []) {
            return;
        }
        $this->record('purchase', $click, self::match($email, $click['ip'] ?? null, $click['ua'] ?? null), $quote, null, $valueEur);
    }

    /** What the quote keeps of the click: the ids, when, and the address and browser for matching. */
    public static function forQuote(array $click, Request $request): array
    {
        return $click + ['ip' => $request->ip(), 'ua' => mb_substr((string) $request->userAgent(), 0, 300)];
    }

    private function record(string $event, array $click, array $match, ?Quote $quote, ?Prototype $prototype, ?float $value): void
    {
        foreach (['meta' => 'fbclid', 'google' => 'gclid'] as $platform => $key) {
            if (empty($click[$key])) {
                continue;
            }
            // A payment webhook delivered twice must not report the purchase twice.
            $row = AdConversion::firstOrCreate(['event_id' => $event.'-'.($quote?->id ?? $prototype?->id ?? Str::uuid()).'-'.$platform], [
                'platform' => $platform,
                'event' => $event,
                'quote_id' => $quote?->id,
                'prototype_id' => $prototype?->id,
                'click_id' => $click[$key],
                'clicked_at' => $click['clicked_at'] ?? now(),
                'happened_at' => now(),
                'value_eur' => $value,
                'match' => $match,
                'source_url' => $click['url'] ?? null,
            ]);
            if ($row->wasRecentlyCreated) {
                SendAdConversion::dispatch($row->id);
            }
        }
    }

    /**
     * Sends one row. Never throws: the row says what happened. A platform that is not set up keeps
     * the row waiting without counting it as a try, so it goes out once somebody sets it up.
     */
    public function send(AdConversion $row): void
    {
        if ($row->status === 'sent') {
            return;
        }
        if ($row->happened_at->lt(now()->subDays(self::WINDOW_DAYS[$row->platform] ?? 7))) {
            $row->update(['status' => 'expired', 'match' => null, 'error' => 'Older than the platform accepts.']);

            return;
        }
        if (($missing = $this->missing($row->platform, $row->event)) !== null) {
            $row->update(['error' => 'Not set up yet: '.$missing]);

            return;
        }

        try {
            $row->platform === 'meta' ? $this->toMeta($row) : $this->toGoogle($row);
            // Sent: the matching data has done its job and is not kept.
            $row->update(['status' => 'sent', 'sent_at' => now(), 'error' => null, 'match' => null, 'attempts' => $row->attempts + 1]);
        } catch (\Throwable $e) {
            $attempts = $row->attempts + 1;
            $row->update(['attempts' => $attempts, 'error' => mb_substr($e->getMessage(), 0, 500),
                'status' => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending']);
        }
    }

    /** What is missing before a platform can take this event; null when it is ready. */
    public function missing(string $platform, string $event): ?string
    {
        if ($platform === 'meta') {
            return match (true) {
                AdSettings::get('meta_pixel_id') === '' => 'META_PIXEL_ID',
                self::metaToken() === '' => 'META_CAPI_TOKEN',
                default => null,
            };
        }
        $google = app(GoogleAdsPublisher::class);

        return match (true) {
            ! $google->isConfigured() => 'Google Ads',
            AdSettings::get('google_conversion_'.$event) === '' => 'GOOGLE_ADS_CONVERSION_'.strtoupper($event),
            default => null,
        };
    }

    /** @return array<string,array<string,?string>> per platform and event: null when ready */
    public function setup(): array
    {
        $out = [];
        foreach (['meta', 'google'] as $platform) {
            foreach (['lead', 'purchase'] as $event) {
                $out[$platform][$event] = $this->missing($platform, $event);
            }
        }

        return $out;
    }

    private function toMeta(AdConversion $row): void
    {
        $match = $row->match ?? [];
        $event = array_filter([
            'event_name' => match ($row->event) { 'purchase' => 'Purchase', 'lead' => 'Lead', default => $row->event },
            'event_time' => $row->happened_at->getTimestamp(),
            'event_id' => $row->event_id,
            'action_source' => 'website',
            'event_source_url' => $row->source_url ?: 'https://appmitki.com',
            'user_data' => array_filter([
                // Meta's click cookie format, built from the fbclid the visitor arrived with.
                'fbc' => $row->click_id ? 'fb.1.'.($row->clicked_at ?? $row->happened_at)->getTimestampMs().'.'.$row->click_id : ($match['fbc'] ?? null),
                'fbp' => $match['fbp'] ?? null,
                'em' => isset($match['em']) ? [$match['em']] : null,
                'client_ip_address' => $match['ip'] ?? null,
                'client_user_agent' => $match['ua'] ?? null,
            ]),
            'custom_data' => $row->value_eur !== null ? ['currency' => 'EUR', 'value' => $row->value_eur] : null,
        ]);
        $version = (string) config('services.ads.meta.api_version') ?: 'v26.0';
        $res = Http::asForm()->timeout(30)->post("https://graph.facebook.com/{$version}/".AdSettings::get('meta_pixel_id').'/events', array_filter([
            'data' => json_encode([$event]),
            'access_token' => self::metaToken(),
            'test_event_code' => (string) config('services.ads.meta.capi_test_code') ?: null,
        ]));
        if (! $res->successful() || (int) $res->json('events_received') < 1) {
            throw new RuntimeException('Meta: '.($res->json('error.message') ?? mb_substr((string) $res->body(), 0, 300)));
        }
    }

    private function toGoogle(AdConversion $row): void
    {
        app(GoogleAdsPublisher::class)->uploadClickConversion([
            'gclid' => $row->click_id,
            'conversionActionId' => AdSettings::get('google_conversion_'.$row->event),
            'conversionDateTime' => $row->happened_at->copy()->utc()->format('Y-m-d H:i:sP'),
            'conversionValue' => $row->value_eur ?? 0,
            'currencyCode' => 'EUR',
            'orderId' => $row->event_id,
        ]);
    }

    private static function metaToken(): string
    {
        return (string) (config('services.ads.meta.capi_token') ?: config('services.ads.meta.token'));
    }

    /** @return array{em?:string,ip?:string,ua?:string} */
    private static function match(?string $email, ?string $ip, ?string $ua): array
    {
        return array_filter([
            // Meta wants the address normalised and SHA-256 hashed; the plain address never leaves.
            'em' => $email ? hash('sha256', mb_strtolower(trim($email))) : null,
            'ip' => $ip ?: null,
            'ua' => $ua ? mb_substr($ua, 0, 300) : null,
        ]);
    }
}
