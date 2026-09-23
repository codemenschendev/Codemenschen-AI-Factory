<?php

namespace App\Domain\Ai;

use App\Models\MarketingCampaign;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Asks Appwerk AI which searches a campaign should be bought for (2026-09-23).
 *
 * It proposes, it does not decide. Everything that comes back is written down as a proposal and
 * an admin keeps or removes each one by hand. Nothing here reaches Google, and nothing here can
 * spend: applying is a separate button on a separate screen.
 *
 * The call goes through the same host sidecar as AdScriptWriter, so there is one door to the
 * gateway and one place where the model is pinned.
 */
class KeywordWriter
{
    /**
     * @return array{keywords:list<array{text:string,match:string}>,negatives:list<string>}
     */
    public function propose(MarketingCampaign $campaign, string $language = 'de'): array
    {
        $brief = self::brief($campaign);
        if (trim($brief) === '') {
            throw new RuntimeException('This campaign has no written message to read, so there is nothing to propose from.');
        }

        // Two attempts. The agent answers conversationally now and then, and a blunter reminder
        // costs one more cheap call instead of an empty screen.
        $ask = "The campaign, in its own words:\n{$brief}\n\nWrite the keywords in this language: {$language}.";
        foreach ([1, 2] as $round) {
            $out = self::parse($this->ask(Prompts::get('ads/keywords'), $ask));
            if ($out['keywords'] !== []) {
                return $out;
            }
            $ask .= "\n\nReturn the JSON object only. Do not do anything else.";
        }

        throw new RuntimeException('The agent answered without a usable keyword list.');
    }

    /** Everything the campaign already says about itself, which is what the keywords come from. */
    private static function brief(MarketingCampaign $campaign): string
    {
        $lines = [];
        foreach (['audience', 'angle', 'promise', 'offer', 'message', 'landing_url'] as $key) {
            $value = $campaign->strategy[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $lines[] = ucfirst($key).': '.trim($value);
            }
        }
        if (($name = trim((string) ($campaign->project?->name ?? ''))) !== '') {
            $lines[] = 'Business: '.$name;
        }
        // The ad copy that was already written for this campaign says, in finished sentences, what
        // it sells. A keyword list that ignores it would be about a different business.
        foreach ($campaign->creatives->whereIn('kind', ['headline', 'ad_copy']) as $creative) {
            $text = trim((string) $creative->content);
            if ($text !== '') {
                $lines[] = 'Ad text: '.$text;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{keywords:list<array{text:string,match:string}>,negatives:list<string>}
     */
    private static function parse(string $text): array
    {
        if (preg_match('/\{.*\}/s', $text, $m) !== 1) {
            return ['keywords' => [], 'negatives' => []];
        }
        $data = json_decode($m[0], true);
        if (! is_array($data)) {
            return ['keywords' => [], 'negatives' => []];
        }

        $keywords = [];
        $seen = [];
        foreach (array_slice((array) ($data['keywords'] ?? []), 0, 25) as $row) {
            $word = self::clean(is_array($row) ? ($row['text'] ?? '') : $row);
            if ($word === '' || isset($seen[$word])) {
                continue;
            }
            $seen[$word] = true;
            // Anything that is not exact becomes phrase. Broad match is never accepted, whatever
            // the model answers: it is the one setting that quietly empties a budget.
            $match = is_array($row) && strtolower((string) ($row['match'] ?? '')) === 'exact' ? 'exact' : 'phrase';
            $keywords[] = ['text' => $word, 'match' => $match];
        }

        $negatives = [];
        foreach (array_slice((array) ($data['negatives'] ?? []), 0, 25) as $row) {
            $word = self::clean(is_array($row) ? ($row['text'] ?? '') : $row);
            if ($word !== '' && ! isset($seen[$word])) {
                $seen[$word] = true;
                $negatives[] = $word;
            }
        }

        return ['keywords' => $keywords, 'negatives' => $negatives];
    }

    /** Google takes no punctuation in a keyword and no more than ten words. */
    private static function clean(mixed $raw): string
    {
        $word = mb_strtolower(trim((string) $raw));
        $word = preg_replace('~["\'\[\]+,.!?;:()]~u', ' ', $word) ?? '';
        $word = trim(preg_replace('~\s+~u', ' ', $word) ?? '');

        return str_word_count($word, 0, 'äöüßáéíóúàèìòùâêîôûçñ0123456789-') > 10 ? '' : mb_substr($word, 0, 80);
    }

    private function ask(string $system, string $brief): string
    {
        $baseUrl = rtrim((string) config('services.ai_image.base_url'), '/');
        $token = (string) config('services.ai_image.token');
        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('AI service is not configured (AI_IMAGE_SERVICE_TOKEN).');
        }

        $request = Http::baseUrl($baseUrl)->withToken($token)->acceptJson()->timeout(120)->connectTimeout(10);
        if (($backend = ChatBackend::pin()) !== null) {
            $request = $request->withHeaders(['x-openclaw-model' => $backend]);
        }

        $res = $request->post('/v1/chat/completions', [
            'model' => config('services.ai_image.chat_model', 'openclaw/appwerk'),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $brief],
            ],
        ]);

        if (! $res->successful()) {
            throw new RuntimeException('Proposing keywords failed ('.$res->status().').');
        }

        return (string) $res->json('choices.0.message.content');
    }
}
