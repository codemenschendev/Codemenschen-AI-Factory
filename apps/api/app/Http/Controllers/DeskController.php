<?php

namespace App\Http\Controllers;

use App\Domain\Sofabuilt\DeskAgent;
use App\Domain\Sofabuilt\Platforms;
use App\Domain\Sofabuilt\Settings;
use App\Domain\Sofabuilt\Pricing;
use App\Domain\Sofabuilt\WpOrg;
use App\Domain\Pricing\Packages;
use App\Models\DeskSession;
use App\Models\Quote;
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


    /** What the desk offers right now (admin switches, Domain\Sofabuilt\Settings). */
    public function config(): JsonResponse
    {
        return response()->json(['offered' => Platforms::offered(), 'open' => (bool) Settings::get('desk_open')]);
    }

    /** The premium plugins for the "own version" door, with live numbers and a typical price. */
    public function catalog(Request $request, WpOrg $wporg): JsonResponse
    {
        $platform = in_array($request->query('platform'), Platforms::ALL, true) ? $request->query('platform') : 'wordpress';
        $items = collect(Platforms::catalog($platform))->map(function ($p) use ($wporg, $platform) {
            $live = ($p['slug'] ?? null) ? $wporg->info($p['slug']) : null;

            return [
                'id' => $p['id'], 'name' => $p['name'], 'category' => $p['category'], 'price' => Platforms::yearly($p['price']),
                'features' => $p['features'],
                'installs' => $live['installs'] ?? null,
                'reviews' => $p['reviews'] ?? null,
                'own_from_eur' => Pricing::quote(['platform' => $platform, 'modules' => array_map(fn ($k) => ['key' => $k], $p['modules'])])['build_eur'],
            ];
        })->values();

        return response()->json(['items' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        // Appmitki's app chat (platform app) is always open; Sofabuilt's follows its admin switch.
        $data = $request->validate(['door' => 'required|in:idea,premium', 'locale' => 'nullable|in:de,en', 'platform' => 'nullable|in:'.implode(',', [...Platforms::offered(), Platforms::APP])]);
        // The admin can close the desk (Settings desk_open): chats already open go on.
        abort_unless(($data['platform'] ?? null) === Platforms::APP || (bool) Settings::get('desk_open'), 503, 'The desk is closed right now.');
        $key = 'desk:sessions:'.$request->ip().':'.now()->toDateString();
        abort_if((int) Cache::get($key, 0) >= self::SESSIONS_PER_IP_DAY, 429, 'Too many chats today.');
        self::bump($key);
        $session = DeskSession::create([
            'door' => $data['door'], 'locale' => $data['locale'] ?? 'en', 'ip' => $request->ip(),
            'platform' => $data['platform'] ?? 'wordpress',
            'brand' => ($data['platform'] ?? null) === Platforms::APP ? 'appmitki' : 'sofabuilt',
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
        if ($turns >= self::TURNS_PER_SESSION || (int) Cache::get($ipKey, 0) >= self::TURNS_PER_IP_DAY || (int) Cache::get($globalKey, 0) >= (int) Settings::get('turns_per_day')) {
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
        if (is_array($scope)) {
            $scope['platform'] = $session->platform; // prices and the build follow the chat's platform
        }
        // Parts the customer picked by hand win over the model: it may only reword the features.
        if ($out['scope'] !== null && ($session->scope['modules_changed'] ?? false)) {
            $scope['modules'] = $session->scope['modules'];
        }
        $research = $session->research ?? [];
        // Live research is wordpress.org's directory; Shopify's App Store has no public search API.
        if ($out['search'] !== null && $session->platform === 'wordpress') {
            foreach ($wporg->search($out['search']) as $row) {
                $research[$row['slug']] = $row;
            }
            $research = array_slice($research, -8, null, true);
        }
        $session->update(['scope' => $scope, 'research' => $research, 'ready' => $out['ready'] && $scope !== null]);

        return response()->json(self::payload($session->fresh()));
    }

    /**
     * The customer adds or removes parts of the plugin themselves. No model call: the price follows
     * at once, and the next chat turn sees the changed modules and brings the features in line.
     */
    public function modules(Request $request, DeskSession $session): JsonResponse
    {
        abort_unless($session->status === 'open' && $session->scope !== null, 409, 'No scope yet.');
        $data = $request->validate(['modules' => 'present|array|max:20', 'modules.*.key' => 'required|string', 'modules.*.qty' => 'nullable|integer|min:1|max:9']);
        $known = Platforms::modules($session->platform);
        $before = collect($session->scope['modules'] ?? [])->keyBy('key');
        $modules = [];
        foreach ($data['modules'] as $m) {
            if (! isset($known[$m['key']]) || $m['key'] === 'base' || isset($modules[$m['key']])) {
                continue;
            }
            $modules[$m['key']] = ['key' => $m['key'], 'qty' => (int) ($m['qty'] ?? 1),
                'why' => (string) ($before[$m['key']]['why'] ?? ''), 'by_customer' => ! $before->has($m['key']) ? true : (bool) ($before[$m['key']]['by_customer'] ?? false)]
                // A kept part keeps its estimated minutes; a new one is priced at its usual time.
                + (isset($before[$m['key']]['minutes']) ? ['minutes' => (int) $before[$m['key']]['minutes']] : [])
                + (isset($before[$m['key']]['tokens_k']) ? ['tokens_k' => (int) $before[$m['key']]['tokens_k']] : []);
        }
        $scope = $session->scope;
        if (isset($before['base'])) {
            $modules = ['base' => $before['base']] + $modules;
        }
        $scope['modules'] = array_values($modules);
        // The customer changed the parts by hand; the next turn reconciles the feature list with them.
        $scope['modules_changed'] = true;
        $session->update(['scope' => $scope]);

        return response()->json(self::payload($session->fresh()));
    }

    /**
     * The agreed scope becomes a quote: the price frozen as it stands, valid 14 days. The launch
     * options are chosen at checkout like any package.
     */
    public function quote(DeskSession $session): JsonResponse
    {
        abort_unless($session->ready && $session->scope !== null, 409, 'The scope is not ready yet.');
        $price = Pricing::quote($session->scope, $session->locale);
        abort_if($price['too_big'], 409, 'Too big for one build.');
        $scope = $session->scope;
        // An Appmitki app is an app order like any other (stack expo), only priced from its parts.
        $app = $session->platform === Platforms::APP;
        $quote = Quote::create([
            'kind' => $app ? 'app' : 'plugin',
            'brand' => $app ? 'appmitki' : 'sofabuilt',
            'idea' => mb_substr(trim(($scope['name'] ?? '').': '.($scope['purpose'] ?? ''), ': '), 0, 200),
            'platform' => $app ? 'mobile' : null,
            // An app's parts in the feature words the app pipeline and change chat know.
            'features' => $app ? self::appFeatures($scope) : array_column($scope['modules'] ?? [], 'key'),
            'breakdown' => ['scope' => $scope, 'lines' => $price['lines'], 'delivery_days' => $price['delivery_days'],
                'care_monthly_eur' => $price['care_monthly_eur'], 'desk_session_id' => $session->id],
            'price_eur' => $price['build_eur'],
            'app_type' => $price['hosting_monthly_eur'] > 0 ? 'B' : 'A',
            'hosting_monthly_eur' => $price['hosting_monthly_eur'],
            'locale' => $session->locale,
            'valid_until' => now()->addDays(14),
        ]);
        $session->update(['quote_id' => $quote->id]);

        return response()->json(['quote_id' => $quote->id, 'price_eur' => $quote->price_eur, 'packages' => Packages::for($quote)], 201);
    }

    private static function payload(DeskSession $session): array
    {
        return [
            'id' => $session->id,
            'door' => $session->door,
            'locale' => $session->locale,
            'status' => $session->status,
            'quote_id' => $session->quote_id,
            'ready' => $session->ready,
            'scope' => $session->scope,
            'research' => array_values($session->research ?? []),
            'price' => $session->scope ? Pricing::quote($session->scope, $session->locale) : null,
            'module_options' => Pricing::options($session->locale, $session->platform),
            'platform' => $session->platform,
            'messages' => $session->messages()->get(['id', 'role', 'body', 'meta', 'created_at']),
        ];
    }

    /** @return list<string> Estimator feature keys for the app desk's parts */
    private static function appFeatures(array $scope): array
    {
        $map = ['accounts' => 'auth', 'payments' => 'pay', 'stats' => 'dash', 'ai' => 'ai', 'notifications' => 'notif',
            'external_api' => 'api', 'language' => 'i18n', 'local_save' => 'offline'];

        return array_values(array_unique(array_filter(array_map(fn ($m) => $map[$m['key'] ?? ''] ?? null, $scope['modules'] ?? []))));
    }

    private static function bump(string $key): void
    {
        Cache::add($key, 0, now()->addDays(2));
        Cache::increment($key);
    }
}
