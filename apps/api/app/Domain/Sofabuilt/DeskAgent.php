<?php

namespace App\Domain\Sofabuilt;

use App\Domain\Ai\ChatBackend;
use App\Domain\Ai\Prompts;
use App\Models\DeskSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * One turn of the Sofabuilt desk: the chat so far, the catalogue, the research and the scope go to
 * the tool-less text model (the same path as the prototype questions, openclaw/appwerk), which
 * answers with a reply and an updated scope. The scope is checked here: unknown module keys are
 * dropped, the price is never the model's.
 */
class DeskAgent
{
    public function __construct(private WpOrg $wporg) {}

    /** @return array{reply: string, questions: list<array{q: string, options: list<string>}>, scope: ?array, search: ?string, ready: bool} */
    public function turn(DeskSession $session): array
    {
        $baseUrl = rtrim((string) config('services.ai_image.base_url'), '/');
        $token = (string) config('services.ai_image.token');
        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('desk: no text model configured');
        }
        $request = Http::baseUrl($baseUrl)->withToken($token)->acceptJson()->timeout(120)->connectTimeout(10);
        if (($backend = ChatBackend::pin()) !== null) {
            $request = $request->withHeaders(['x-openclaw-model' => $backend]);
        }
        $res = $request->post('/v1/chat/completions', [
            'model' => config('services.ai_image.chat_model', 'openclaw/appwerk'),
            'messages' => [['role' => 'user', 'content' => $this->prompt($session)]],
            'max_completion_tokens' => 2000,
        ]);
        if (! $res->successful()) {
            throw new RuntimeException('desk: model call failed with '.$res->status());
        }
        $out = self::parse((string) $res->json('choices.0.message.content'));
        if ($out === null) {
            Log::warning('desk: unusable reply', ['session' => $session->id]);
            throw new RuntimeException('desk: unusable reply');
        }

        return $out;
    }

    public function prompt(DeskSession $session): string
    {
        $modules = collect(config('sofabuilt.modules'))->map(fn ($m, $k) => "  - {$k}: {$m['en']}")->implode("\n");
        $catalog = collect(config('sofabuilt.catalog'))->map(function ($p) {
            $live = $p['slug'] ? $this->wporg->info($p['slug']) : null;
            $stats = $live ? sprintf(', free version on wordpress.org: %s installs, rating %d/100', number_format($live['installs']), $live['rating']) : '';

            return "  - {$p['name']} ({$p['category']}, about {$p['price']}/year{$stats}): ".implode(', ', $p['features']);
        })->implode("\n");
        $research = collect($session->research ?? [])->map(fn ($r) => sprintf('  - %s: %s installs, rating %d/100, %d ratings, updated %s',
            $r['name'], number_format($r['installs']), $r['rating'], $r['ratings'], $r['updated']))->implode("\n");
        $conversation = $session->messages()->latest('id')->limit(24)->get()->reverse()
            ->map(fn ($m) => ($m->role === 'customer' ? 'Customer' : 'You').': '.mb_substr($m->body, 0, 2000))->implode("\n\n");

        return Prompts::get('sofabuilt/desk', [
            'language' => $session->locale === 'de' ? 'German' : 'English',
            'door' => $session->door === 'premium'
                ? 'own version of a premium plugin (help them pick one from the list below, or the one they name)'
                : 'own idea (help them make it clear)',
            'modules' => $modules,
            'catalog' => $catalog,
            'research' => $research !== '' ? $research : '  (nothing yet)',
            'scope' => $session->scope ? json_encode($session->scope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null',
            'conversation' => $conversation !== '' ? $conversation : '(the customer has not written yet)',
        ]);
    }

    /** @return array{reply: string, questions: list<array{q: string, options: list<string>}>, scope: ?array, search: ?string, ready: bool}|null */
    public static function parse(string $text): ?array
    {
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $text, $m) === 1) {
            $text = $m[1];
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start === false || $end === false ? null : json_decode(substr($text, $start, $end - $start + 1), true);
        if (! is_array($data) || ! is_string($data['reply'] ?? null) || trim($data['reply']) === '') {
            return null;
        }
        $str = fn ($v, int $max) => is_string($v) ? mb_substr(trim(str_replace('—', ',', $v)), 0, $max) : '';
        $list = fn ($v, int $n, int $max) => array_values(array_filter(array_map(fn ($x) => $str($x, $max), is_array($v) ? array_slice($v, 0, $n) : []), fn ($x) => $x !== ''));

        $questions = [];
        foreach (is_array($data['questions'] ?? null) ? array_slice($data['questions'], 0, 2) : [] as $q) {
            $options = $list($q['options'] ?? null, 4, 60);
            if ($str($q['q'] ?? null, 200) !== '' && count($options) >= 2) {
                $questions[] = ['q' => $str($q['q'], 200), 'options' => $options];
            }
        }

        $scope = null;
        if (is_array($data['scope'] ?? null)) {
            $s = $data['scope'];
            $known = config('sofabuilt.modules');
            $modules = [];
            foreach (is_array($s['modules'] ?? null) ? $s['modules'] : [] as $m) {
                $key = is_array($m) ? ($m['key'] ?? null) : $m;
                if (is_string($key) && isset($known[$key])) {
                    $modules[] = ['key' => $key, 'qty' => max(1, (int) (is_array($m) ? ($m['qty'] ?? 1) : 1)), 'why' => $str(is_array($m) ? ($m['why'] ?? '') : '', 80)];
                }
            }
            $req = is_array($s['requires'] ?? null) ? $s['requires'] : [];
            $scope = [
                'name' => $str($s['name'] ?? '', 60),
                'purpose' => $str($s['purpose'] ?? '', 300),
                'features' => $list($s['features'] ?? null, 12, 160),
                'not_included' => $list($s['not_included'] ?? null, 8, 160),
                'requires' => [
                    'woocommerce' => (bool) ($req['woocommerce'] ?? false),
                    'wordpress' => $str($req['wordpress'] ?? '6.4', 8) ?: '6.4',
                    'php' => $str($req['php'] ?? '8.1', 8) ?: '8.1',
                ],
                'modules' => $modules,
            ];
        }

        return [
            'reply' => $str($data['reply'], 2500),
            'questions' => $questions,
            'scope' => $scope,
            'search' => is_string($data['search'] ?? null) && trim($data['search']) !== '' ? $str($data['search'], 80) : null,
            // Whether a scope exists at all (this turn or before) is the controller's to check.
            'ready' => (bool) ($data['ready'] ?? false),
        ];
    }
}
