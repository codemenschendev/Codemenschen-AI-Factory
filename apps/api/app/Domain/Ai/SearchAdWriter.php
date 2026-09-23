<?php

namespace App\Domain\Ai;

use RuntimeException;

/**
 * Writes the headlines and descriptions of a Google search ad for Appwerk's own campaigns
 * (2026-09-23).
 *
 * A draft for a person to edit, nothing more: the lines come back to the form, and only what the
 * admin saves is stored. A line over Google's length limit is dropped here rather than cut, because
 * a cut sentence is worse than one line fewer.
 */
class SearchAdWriter
{
    public const HEADLINE_MAX = 30;

    public const DESCRIPTION_MAX = 90;

    /**
     * @param  array<string,string>  $brief  message, audience, offer, landing_url
     * @return array{headlines:list<string>,descriptions:list<string>}
     */
    public function write(array $brief, string $language = 'de'): array
    {
        $lines = [];
        foreach (['message', 'audience', 'offer', 'landing_url'] as $key) {
            $value = trim((string) ($brief[$key] ?? ''));
            if ($value !== '') {
                $lines[] = ucfirst($key).': '.$value;
            }
        }
        if ($lines === []) {
            throw new RuntimeException('Write what the campaign is about first.');
        }

        $ask = implode("\n", $lines)."\n\nWrite in this language: {$language}.";
        foreach ([1, 2] as $round) {
            $out = self::parse(app(AgentChat::class)->ask(Prompts::get('ads/search-copy'), $ask, 'Writing the ad failed'));
            if (count($out['headlines']) >= 3 && count($out['descriptions']) >= 2) {
                return $out;
            }
            $ask .= "\n\nReturn the JSON object only, and keep every line inside its length limit.";
        }

        throw new RuntimeException('The agent answered without enough usable lines.');
    }

    /** @return array{headlines:list<string>,descriptions:list<string>} */
    public static function parse(string $text): array
    {
        if (preg_match('/\{.*\}/s', $text, $m) !== 1 || ! is_array($data = json_decode($m[0], true))) {
            return ['headlines' => [], 'descriptions' => []];
        }

        return [
            'headlines' => self::lines($data['headlines'] ?? [], self::HEADLINE_MAX, 15),
            'descriptions' => self::lines($data['descriptions'] ?? [], self::DESCRIPTION_MAX, 4),
        ];
    }

    /** @return list<string> */
    private static function lines(mixed $raw, int $max, int $count): array
    {
        $out = [];
        foreach ((array) $raw as $line) {
            $line = trim(preg_replace('~\s+~u', ' ', (string) $line) ?? '');
            // Google refuses an exclamation mark in a headline; a trailing one in a description is noise.
            $line = rtrim($line, '!');
            if ($line === '' || mb_strlen($line) > $max || in_array(mb_strtolower($line), array_map('mb_strtolower', $out), true)) {
                continue;
            }
            $out[] = $line;
        }

        return array_slice($out, 0, $count);
    }
}
