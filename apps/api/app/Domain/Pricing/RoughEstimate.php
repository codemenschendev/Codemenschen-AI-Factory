<?php

namespace App\Domain\Pricing;

use App\Domain\Ai\ChatBackend;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The price that follows the visitor's typing on the prototype page (Patrick, 2026-10-05: "todo
 * app estimate rough: checkboxes 15, saving of items 5, releasing app to store 15"). The model
 * splits the idea into parts with a price each, from the guide in prompts/estimate/rough.md.
 * It is called while someone types, so the same text comes from cache and every visitor and
 * the whole storefront have a daily cap.
 */
class RoughEstimate
{
    public const IP_DAILY = 40;

    public const GLOBAL_DAILY = 2000;

    public const MAX_PART_EUR = 100;

    /** @return array{status: int, body: array} */
    public function run(string $idea, string $locale, string $ip): array
    {
        $idea = trim(preg_replace('/\s+/', ' ', $idea));
        $cacheKey = 'rough:'.sha1($locale.'|'.mb_strtolower($idea));
        if ($cached = Cache::get($cacheKey)) {
            return ['status' => 200, 'body' => $cached];
        }
        $day = now()->format('Y-m-d');
        $ipKey = 'rough:ip:'.sha1($ip).":$day";
        if ((int) Cache::get($ipKey, 0) >= self::IP_DAILY) {
            return ['status' => 429, 'body' => ['error' => 'limit']];
        }
        if ((int) Cache::get("rough:global:$day", 0) >= self::GLOBAL_DAILY) {
            return ['status' => 503, 'body' => ['error' => 'unavailable']];
        }

        $baseUrl = rtrim((string) config('services.ai_image.base_url'), '/');
        $token = (string) config('services.ai_image.token');
        if ($baseUrl === '' || $token === '') {
            return ['status' => 503, 'body' => ['error' => 'unavailable']];
        }
        $request = Http::baseUrl($baseUrl)->withToken($token)->acceptJson()->timeout(60)->connectTimeout(10);
        if (($backend = ChatBackend::pin()) !== null) {
            $request = $request->withHeaders(['x-openclaw-model' => $backend]);
        }
        $this->count($ipKey);
        $this->count("rough:global:$day");
        $res = $request->post('/v1/chat/completions', [
            'model' => config('services.ai_image.chat_model', 'openclaw/appwerk'),
            'messages' => [['role' => 'user', 'content' => $this->prompt($idea, $locale)]],
            'max_completion_tokens' => 600,
        ]);
        $parts = $res->successful() ? self::parse((string) $res->json('choices.0.message.content')) : null;
        if ($parts === null) {
            Log::warning('rough estimate: unusable reply', ['status' => $res->status()]);

            return ['status' => 503, 'body' => ['error' => 'unavailable']];
        }
        $body = self::totals($parts);
        Cache::put($cacheKey, $body, now()->addDay());

        return ['status' => 200, 'body' => $body];
    }

    public function prompt(string $idea, string $locale): string
    {
        return strtr((string) file_get_contents(resource_path('prompts/estimate/rough.md')), [
            '{brand}' => 'Appmitki',
            '{language}' => $locale === 'en' ? 'English' : 'German',
            '{idea}' => mb_substr($idea, 0, 800),
        ]);
    }

    /** @return list<array{name: string, eur: int}>|null */
    public static function parse(string $text): ?array
    {
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $text, $m) === 1) {
            $text = $m[1];
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start === false || $end === false ? null : json_decode(substr($text, $start, $end - $start + 1), true);
        if (! is_array($data) || ! is_array($data['parts'] ?? null)) {
            return null;
        }
        $parts = [];
        foreach (array_slice($data['parts'], 0, 8) as $p) {
            $name = is_string($p['name'] ?? null) ? mb_substr(trim(preg_replace('/\s*—\s*/u', ', ', $p['name'])), 0, 80) : '';
            $eur = is_numeric($p['eur'] ?? null) ? (int) round(((float) $p['eur']) / 5) * 5 : 0;
            if ($name !== '' && $eur > 0) {
                $parts[] = ['name' => $name, 'eur' => min(self::MAX_PART_EUR, $eur)];
            }
        }

        return $parts;
    }

    /** @param list<array{name: string, eur: int}> $parts */
    public static function totals(array $parts): array
    {
        return ['parts' => $parts, 'total' => array_sum(array_column($parts, 'eur'))];
    }

    private function count(string $key): void
    {
        Cache::add($key, 0, now()->addDays(2));
        Cache::increment($key);
    }
}
