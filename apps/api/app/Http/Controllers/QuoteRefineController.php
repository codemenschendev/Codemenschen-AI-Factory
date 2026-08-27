<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * "Sharpen my idea" in the storefront wizard: the draft goes to the worker,
 * which asks the OpenClaw gateway for a clear description, up to three
 * tap-to-answer questions and suggested feature toggles. The model call is
 * on the factory's shared subscription, so the guard rails here are about
 * abuse, not money: per-visitor and global daily caps, identical drafts
 * served from cache, only after the customer pressed the button.
 */
class QuoteRefineController extends Controller
{
    /** Rounds an anonymous visitor (per IP) gets per day. */
    public const ANON_DAILY = 3;

    /** Rounds a signed-in customer gets per day. */
    public const CUSTOMER_DAILY = 10;

    /** Circuit breaker for the whole storefront per day. */
    public const GLOBAL_DAILY = 500;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => 'required|string|min:30|max:800',
            'locale' => 'nullable|in:de,en',
            'answers' => 'array|max:6',
            'answers.*' => 'string|max:200',
        ]);
        $locale = $data['locale'] ?? 'de';
        $answers = array_values($data['answers'] ?? []);

        // Identical drafts answer from cache — no limit spent, no model call.
        $cacheKey = 'refine:'.sha1($locale.'|'.mb_strtolower(trim($data['text'])).'|'.implode('|', $answers));
        if ($cached = Cache::get($cacheKey)) {
            return response()->json($cached);
        }

        $customer = $request->user('sanctum');
        $day = now()->format('Y-m-d');
        $visitorKey = $customer ? "refine:customer:{$customer->id}:$day" : 'refine:ip:'.sha1((string) $request->ip()).":$day";
        $visitorCap = $customer ? self::CUSTOMER_DAILY : self::ANON_DAILY;
        if ((int) Cache::get($visitorKey, 0) >= $visitorCap) {
            return response()->json(['error' => 'limit', 'signed_in' => (bool) $customer], 429);
        }
        if ((int) Cache::get("refine:global:$day", 0) >= self::GLOBAL_DAILY) {
            return response()->json(['error' => 'unavailable'], 503);
        }

        $res = Http::timeout(90)
            ->withToken(config('services.worker.token'))
            ->post(rtrim(config('services.worker.url'), '/').'/refine', [
                'text' => $data['text'],
                'locale' => $locale,
                'answers' => $answers,
            ]);
        if (! $res->ok()) {
            return response()->json(['error' => 'unavailable'], 503);
        }

        $out = [
            'off_topic' => (bool) $res->json('off_topic'),
            'description' => (string) $res->json('description', ''),
            'questions' => array_values((array) $res->json('questions', [])),
            'suggested_features' => array_values((array) $res->json('suggested_features', [])),
        ];
        $this->count($visitorKey);
        $this->count("refine:global:$day");
        Cache::put($cacheKey, $out, now()->addDay());

        return response()->json($out);
    }

    private function count(string $key): void
    {
        Cache::add($key, 0, now()->addDays(2));
        Cache::increment($key);
    }
}
