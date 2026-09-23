<?php

namespace App\Domain\Ads;

use Illuminate\Http\Request;

/**
 * The ad click a visitor arrived with, as the site sends it (2026-09-23).
 *
 * The browser keeps a gclid or fbclid only after the visitor allowed ad measurement, and sends
 * it in the X-Ad-Click header. No header means no consent or no ad click, and then nothing about
 * this visitor is ever reported to a platform. The values are checked for shape, never trusted
 * further than that.
 */
class AdClick
{
    public const HEADER = 'X-Ad-Click';

    /** @return array{gclid?:string,fbclid?:string,clicked_at:string,url?:string}|null */
    public static function fromRequest(Request $request): ?array
    {
        $raw = (string) $request->header(self::HEADER, '');
        if ($raw === '' || strlen($raw) > 1200) {
            return null;
        }
        $data = json_decode($raw, true);
        if (! is_array($data) || ($data['consent'] ?? null) !== true) {
            return null;
        }

        $out = [];
        foreach (['gclid', 'fbclid'] as $key) {
            $value = (string) ($data[$key] ?? '');
            if (preg_match('~^[A-Za-z0-9_\-]{8,500}$~', $value) === 1) {
                $out[$key] = $value;
            }
        }
        if ($out === []) {
            return null;
        }

        // When the click happened, in milliseconds as the browser saw it; never in the future and
        // never older than the 90 days Google keeps a click.
        $ms = (int) ($data['ts'] ?? 0);
        $at = $ms > 0 ? (int) floor($ms / 1000) : time();
        $at = min(time(), max(time() - 90 * 86400, $at));
        $out['clicked_at'] = date(DATE_ATOM, $at);

        $url = (string) ($data['url'] ?? '');
        if (str_starts_with($url, 'https://') && strlen($url) <= 500) {
            $out['url'] = $url;
        }

        return $out;
    }
}
