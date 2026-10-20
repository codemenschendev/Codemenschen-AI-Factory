<?php

namespace App\Domain\Werk;

use App\Models\Setting;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Appwerk as a werk-connect site (Werk docs/API.md 7.10): the status Werk reads, and the hourly
 * ping that keeps the site's dot green in Werk and brings back the analytics beacon key (7.2, 7.6).
 *
 * Werk knows two kinds of site, WordPress and "node" (anything else that speaks werk-connect).
 * Appwerk is the second kind, so `cms` says "node" and the real stack (Laravel, PHP) is named in
 * cms_version, platform and packages.
 */
class Probe
{
    public const VERSION = '1.0.0';

    /** What this site offers Werk beyond the probe: werk.ai reads the list to know the routes. */
    public const FEATURES = ['overview', 'ads', 'business'];

    /** Where the last ping and the beacon key are kept (settings table, survives a deploy). */
    public const PING_SETTING = 'werk.ping';

    public const ANALYTICS_SETTING = 'werk.analytics';

    private const DEFAULT_SCRIPT = 'https://werk.codemenschen.at/a.js';

    public static function configured(): bool
    {
        return trim((string) config('services.werk.token')) !== '';
    }

    /** GET /_werk/v1/probe. @return array<string,mixed> */
    public function status(): array
    {
        return [
            'probe_version' => self::VERSION,
            'cms' => 'node',
            'cms_version' => 'Laravel '.Application::VERSION,
            'platform' => 'PHP '.PHP_VERSION.', '.PHP_OS_FAMILY.' '.php_uname('m'),
            // The container's age, from the first process of its namespace. Null off Linux.
            'uptime_seconds' => self::uptime(),
            // This PHP worker, not the whole container: PHP has no long-lived process to measure.
            'memory_mb' => (int) round(memory_get_usage(true) / 1048576),
            'packages' => [
                'php' => PHP_VERSION,
                'laravel/framework' => Application::VERSION,
            ],
            'site_url' => (string) config('services.werk.site_url'),
            'last_ping' => Setting::read(self::PING_SETTING),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * One ping (7.2). Stores the answer's analytics key and script for the storefront. Failures are
     * logged and change nothing, except a 409 wrong_site, which drops the key as the probe does.
     *
     * @return array{ok:bool, status:int, message:?string}
     */
    public function ping(): array
    {
        $token = trim((string) config('services.werk.token'));
        if ($token === '') {
            return ['ok' => false, 'status' => 0, 'message' => 'no_token_configured'];
        }
        $url = rtrim((string) config('services.werk.api_url'), '/').'/probe/ping';
        try {
            $res = Http::withToken($token)->acceptJson()->timeout(10)->connectTimeout(5)
                ->withUserAgent('appwerk-werk-connect/'.self::VERSION)
                ->post($url, [
                    'probe_version' => self::VERSION,
                    'cms' => 'node',
                    'cms_version' => 'Laravel '.Application::VERSION,
                    'php_version' => PHP_VERSION,
                    'site_url' => (string) config('services.werk.site_url'),
                    'cron_ok' => true,
                    'features' => self::FEATURES,
                ]);
        } catch (\Throwable $e) {
            Setting::write(self::PING_SETTING, ['ok' => false, 'status' => 0, 'at' => now()->toIso8601String()], 'werk:ping');
            Log::warning('werk.ping_failed', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return ['ok' => false, 'status' => 0, 'message' => mb_substr($e->getMessage(), 0, 200)];
        }

        Setting::write(self::PING_SETTING, ['ok' => $res->successful(), 'status' => $res->status(), 'at' => now()->toIso8601String()], 'werk:ping');
        if ($res->status() === 409) {
            // The token belongs to another site: no beacon key for this one.
            Setting::write(self::ANALYTICS_SETTING, null, 'werk:ping');
        }
        if (! $res->successful()) {
            Log::warning('werk.ping_refused', ['status' => $res->status(), 'body' => mb_substr((string) $res->body(), 0, 200)]);

            return ['ok' => false, 'status' => $res->status(), 'message' => (string) ($res->json('error.code') ?? $res->json('error') ?? '')];
        }
        $analytics = $res->json('analytics');
        if (is_array($analytics)) {
            Setting::write(self::ANALYTICS_SETTING, self::cleanAnalytics($analytics), 'werk:ping');
        }

        return ['ok' => true, 'status' => $res->status(), 'message' => null];
    }

    /**
     * The beacon the storefront loads, or null: no token, no key yet, or analytics switched off
     * for the site in Werk.
     *
     * @return array{key:string, script:string}|null
     */
    public static function analytics(): ?array
    {
        if (! self::configured()) {
            return null;
        }
        $stored = Setting::read(self::ANALYTICS_SETTING);

        return is_array($stored) && is_string($stored['key'] ?? null) && $stored['key'] !== '' ? $stored : null;
    }

    /**
     * Only a key that looks like one, and only a script from Codemenschen's own hosts over https:
     * the storefront puts this into a script tag on every page.
     *
     * @param  array<string,mixed>  $analytics
     * @return array{key:string, script:string}|null
     */
    private static function cleanAnalytics(array $analytics): ?array
    {
        $key = $analytics['key'] ?? null;
        if (! is_string($key) || preg_match('/^[A-Za-z0-9_-]{6,128}$/', $key) !== 1) {
            return null;
        }
        $script = (string) ($analytics['script'] ?? '');
        $host = (string) parse_url($script, PHP_URL_HOST);
        $ours = parse_url($script, PHP_URL_SCHEME) === 'https' && ($host === 'codemenschen.at' || str_ends_with($host, '.codemenschen.at'));

        return ['key' => $key, 'script' => $ours ? $script : self::DEFAULT_SCRIPT];
    }

    private static function uptime(): ?int
    {
        $stat = @file_get_contents('/proc/1/stat');
        $up = @file_get_contents('/proc/uptime');
        if ($stat === false || $up === false) {
            return null;
        }
        // Field 22 (starttime) counts clock ticks since boot; the name in field 2 may hold spaces.
        $fields = explode(' ', trim(substr($stat, (int) strrpos($stat, ')') + 2)));
        $start = isset($fields[19]) ? (int) $fields[19] / 100 : null;

        return $start === null ? null : max(0, (int) ((float) $up - $start));
    }
}
