<?php

namespace App\Domain\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The few questions asked between the visitor's sentence and the build. One sentence rarely
 * says who the page is for, what it should get people to do or what is on offer, and the model
 * then guesses; two taps answer it. Nothing here is required: no answer, or no questions
 * because the call failed, and the build goes ahead on the sentence alone.
 */
class PrototypeQuestions
{
    private const TOPICS = [
        'site' => 'who the customers are, the one thing a visitor should do (call, book, buy, ask for a quote), what is special about the business, the tone',
        'app' => 'who uses the app, the one task it must make easy, what users do today instead, the tone',
        'ads' => 'who the ads should reach, what the ad should get people to do, the offer or occasion (a season, a discount, a launch), the tone',
        'email' => 'who receives the e-mails, the moments they go out (welcome, order, reminder, comeback), the offer, the tone',
    ];

    /** @return list<array{q:string,options:list<string>}> empty when there is nothing worth asking or the call failed */
    public function ask(string $sentence, string $kind, string $locale): array
    {
        $baseUrl = rtrim((string) config('services.ai_image.base_url'), '/');
        $token = (string) config('services.ai_image.token');
        if ($baseUrl === '' || $token === '') {
            return [];
        }
        $de = $locale === 'de';
        $prompt = Prompts::get('prototype/questions', [
            'kind' => ['site' => 'website', 'app' => 'app', 'ads' => 'ads', 'email' => 'e-mail'][$kind] ?? 'website',
            'sentence' => mb_substr(trim($sentence), 0, 2000),
            'topics' => self::TOPICS[$kind] ?? self::TOPICS['site'],
            'language' => $de ? 'German' : 'English',
            'you' => $de ? 'du' : 'you',
        ]);

        try {
            $request = Http::baseUrl($baseUrl)->withToken($token)->acceptJson()->timeout(60)->connectTimeout(10);
            if (($backend = ChatBackend::pin()) !== null) {
                $request = $request->withHeaders(['x-openclaw-model' => $backend]);
            }
            $res = $request->post('/v1/chat/completions', [
                'model' => config('services.ai_image.chat_model', 'openclaw/appwerk'),
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'max_completion_tokens' => 600,
            ]);
            if (! $res->successful()) {
                Log::info('prototype questions: call failed', ['status' => $res->status()]);

                return [];
            }

            return self::parse((string) $res->json('choices.0.message.content'));
        } catch (\Throwable $e) {
            Log::info('prototype questions: skipped', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return [];
        }
    }

    /** @return list<array{q:string,options:list<string>}> */
    public static function parse(string $text): array
    {
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $text, $m) === 1) {
            $text = $m[1];
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start === false || $end === false ? null : json_decode(substr($text, $start, $end - $start + 1), true);
        $out = [];
        foreach (is_array($data['questions'] ?? null) ? $data['questions'] : [] as $row) {
            $q = is_string($row['q'] ?? null) ? trim($row['q']) : '';
            if ($q === '' || mb_strlen($q) > 160) {
                continue;
            }
            $options = array_values(array_filter(array_map(
                fn ($o) => is_string($o) ? trim($o) : '', is_array($row['options'] ?? null) ? $row['options'] : []),
                fn (string $o) => $o !== '' && mb_strlen($o) <= 60));
            $out[] = ['q' => $q, 'options' => array_slice($options, 0, 4)];
        }

        return array_slice($out, 0, 3);
    }
}
