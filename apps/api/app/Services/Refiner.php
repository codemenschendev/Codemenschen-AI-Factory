<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * "Sharpen my text" behind the wizard (idea) and the change-request form
 * (change): the draft goes to the worker, which asks the OpenClaw gateway
 * for a clear version, up to three tap-to-answer questions and — for change
 * requests — whether it fits the paid scope. The model runs on the factory's
 * shared subscription, so the guard rails are about abuse, not money: daily
 * caps per visitor and for the whole storefront, identical drafts from cache,
 * and only ever after the customer pressed the button.
 */
class Refiner
{
    /** Rounds an anonymous visitor (per IP) gets per day. */
    public const ANON_DAILY = 3;

    /** Rounds a signed-in customer gets per day (both kinds together). */
    public const CUSTOMER_DAILY = 10;

    /** Circuit breaker for the whole storefront per day. */
    public const GLOBAL_DAILY = 500;

    /**
     * @param  array{mode:'idea'|'change', text:string, locale:string, answers:list<string>, project_id?:string, features?:list<string>}  $payload
     * @return array{status:int, body:array}
     */
    public function run(array $payload, ?Customer $customer, string $ip): array
    {
        $cacheKey = 'refine:'.sha1(implode('|', [
            $payload['mode'], $payload['locale'], $payload['project_id'] ?? '',
            mb_strtolower(trim($payload['text'])), implode('|', $payload['answers']),
        ]));
        if ($cached = Cache::get($cacheKey)) {
            return ['status' => 200, 'body' => $cached];
        }

        $day = now()->format('Y-m-d');
        $visitorKey = $customer ? "refine:customer:{$customer->id}:$day" : 'refine:ip:'.sha1($ip).":$day";
        $visitorCap = $customer ? self::CUSTOMER_DAILY : self::ANON_DAILY;
        if ((int) Cache::get($visitorKey, 0) >= $visitorCap) {
            return ['status' => 429, 'body' => ['error' => 'limit', 'signed_in' => (bool) $customer]];
        }
        if ((int) Cache::get("refine:global:$day", 0) >= self::GLOBAL_DAILY) {
            return ['status' => 503, 'body' => ['error' => 'unavailable']];
        }

        $res = Http::timeout(90)
            ->withToken(config('services.worker.token'))
            ->post(rtrim(config('services.worker.url'), '/').'/refine', $payload);
        if (! $res->ok()) {
            return ['status' => 503, 'body' => ['error' => 'unavailable']];
        }

        $out = [
            'off_topic' => (bool) $res->json('off_topic'),
            'description' => (string) $res->json('description', ''),
            'questions' => array_values((array) $res->json('questions', [])),
            'suggested_features' => array_values((array) $res->json('suggested_features', [])),
        ];
        if ($payload['mode'] === 'change') {
            $out['in_scope'] = $res->json('in_scope') !== false;
            $out['scope_note'] = (string) $res->json('scope_note', '');
        }
        $this->count($visitorKey);
        $this->count("refine:global:$day");
        Cache::put($cacheKey, $out, now()->addDay());

        return ['status' => 200, 'body' => $out];
    }

    private function count(string $key): void
    {
        Cache::add($key, 0, now()->addDays(2));
        Cache::increment($key);
    }
}
