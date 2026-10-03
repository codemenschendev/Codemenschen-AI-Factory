<?php

namespace App\Http\Controllers;

use App\Domain\Sofabuilt\DeskAgent;
use App\Domain\Sofabuilt\Pricing;
use App\Domain\Sofabuilt\WpOrg;
use App\Models\DeskSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sofabuilt's desk (docs/specs/sofabuilt.md): a free chat that turns an idea into a priced scope.
 * Anyone may open one; every turn costs a model call, so turns are capped per chat, per visitor and
 * per day on top of the route throttle and Turnstile.
 */
class DeskController extends Controller
{
    private const SESSIONS_PER_IP_DAY = 20;

    private const TURNS_PER_SESSION = 30;

    private const TURNS_PER_IP_DAY = 80;

    private const TURNS_GLOBAL_DAY = 2000;

    /** The premium plugins for the "own version" door, with live numbers and a typical price. */
    public function catalog(WpOrg $wporg): JsonResponse
    {
        $items = collect(config('sofabuilt.catalog'))->map(function ($p) use ($wporg) {
            $live = $p['slug'] ? $wporg->info($p['slug']) : null;

            return [
                'id' => $p['id'], 'name' => $p['name'], 'category' => $p['category'], 'price' => $p['price'],
                'features' => $p['features'],
                'installs' => $live['installs'] ?? null,
                'own_from_eur' => Pricing::quote(['modules' => array_map(fn ($k) => ['key' => $k], $p['modules'])])['build_eur'],
            ];
        })->values();

        return response()->json(['items' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['door' => 'required|in:idea,premium', 'locale' => 'nullable|in:de,en']);
        $key = 'desk:sessions:'.$request->ip().':'.now()->toDateString();
        abort_if((int) Cache::get($key, 0) >= self::SESSIONS_PER_IP_DAY, 429, 'Too many chats today.');
        self::bump($key);
        $session = DeskSession::create([
            'door' => $data['door'], 'locale' => $data['locale'] ?? 'en', 'ip' => $request->ip(),
            'customer_id' => $request->user('sanctum')?->id,
        ]);

        return response()->json(self::payload($session), 201);
    }

    public function show(DeskSession $session): JsonResponse
    {
        return response()->json(self::payload($session));
    }

    public function message(Request $request, DeskSession $session, DeskAgent $agent, WpOrg $wporg): JsonResponse
    {
        $data = $request->validate(['text' => 'required|string|min:2|max:2000']);
        abort_unless($session->status === 'open', 409, 'This chat is closed.');
        $day = now()->toDateString();
        $ipKey = "desk:turns:{$request->ip()}:$day";
        $globalKey = "desk:turns:global:$day";
        $turns = $session->messages()->where('role', 'customer')->count();
        if ($turns >= self::TURNS_PER_SESSION || (int) Cache::get($ipKey, 0) >= self::TURNS_PER_IP_DAY || (int) Cache::get($globalKey, 0) >= self::TURNS_GLOBAL_DAY) {
            return response()->json(['message' => 'Limit reached.', 'error' => 'limit'], 429);
        }
        self::bump($ipKey);
        self::bump($globalKey);

        $session->messages()->create(['role' => 'customer', 'body' => trim($data['text'])]);
        try {
            $out = $agent->turn($session);
        } catch (\Throwable $e) {
            Log::warning('desk turn failed', ['session' => $session->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return response()->json(['message' => 'The assistant is not reachable right now.', 'error' => 'unavailable'] + self::payload($session), 503);
        }

        $session->messages()->create(['role' => 'assistant', 'body' => $out['reply'], 'meta' => ['questions' => $out['questions']]]);
        $scope = $out['scope'] ?? $session->scope;
        $research = $session->research ?? [];
        if ($out['search'] !== null) {
            foreach ($wporg->search($out['search']) as $row) {
                $research[$row['slug']] = $row;
            }
            $research = array_slice($research, -8, null, true);
        }
        $session->update(['scope' => $scope, 'research' => $research, 'ready' => $out['ready'] && $scope !== null]);

        return response()->json(self::payload($session->fresh()));
    }

    private static function payload(DeskSession $session): array
    {
        return [
            'id' => $session->id,
            'door' => $session->door,
            'locale' => $session->locale,
            'status' => $session->status,
            'ready' => $session->ready,
            'scope' => $session->scope,
            'research' => array_values($session->research ?? []),
            'price' => $session->scope ? Pricing::quote($session->scope, $session->locale) : null,
            'messages' => $session->messages()->get(['id', 'role', 'body', 'meta', 'created_at']),
        ];
    }

    private static function bump(string $key): void
    {
        Cache::add($key, 0, now()->addDays(2));
        Cache::increment($key);
    }
}
