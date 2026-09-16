<?php

namespace App\Domain\Qa;

/**
 * Facts a page states that the customer never gave.
 *
 * The browser audit measures; it cannot read. The first builds with layout packs on printed
 * twelve bread prices with no hint that they were examples, and a physiotherapy page told its
 * visitors which health insurers pay a share, a promise the customer never made. Both were
 * forbidden in the prompt and the model did it anyway, twice, so the rule is a check now and a
 * page that breaks it goes to the repair pass like any other fault.
 *
 * Everything the customer's own sentence names is allowed: a bakery that says "seit 1998" gets
 * to print it.
 */
class ContentClaims
{
    /** A price the visitor would read as the business's own. */
    private const PRICE = '/(?<![\p{L}\p{N},.])(?:\d{1,4}(?:[.,]\d{1,2})?(?:,[-–])?[\s\x{00A0}\x{202F}]?(?:€|EUR\b|Euro\b)|€[\s\x{00A0}\x{202F}]?\d{1,4}(?:[.,]\d{1,2})?)/u';

    /** The one line that turns invented prices into honest ones. */
    private const EXAMPLE = '/Beispielpreis|Preisbeispiel|Beispiel-Preis|example price|sample price/iu';

    /**
     * Claims a visitor relies on and a customer can be held to: who pays, who certified, who
     * awarded, how long. Written the way they appear on Austrian and German small-business pages.
     */
    private const CLAIMS = [
        'ÖGK', 'SVS', 'BVAEB', 'KFA', 'alle Kassen', 'Kassenvertrag', 'Kassenpraxis', 'kassenzertifiziert',
        'zertifiziert', 'Zertifikat', 'TÜV', 'ISO 9001', 'Gütesiegel', 'Testsieger', 'Auszeichnung',
        'ausgezeichnet mit', 'Award', 'Meisterbetrieb', 'Innungsmitglied',
    ];

    /** @return list<array{severity:string,check:string,viewports:list<string>,detail:string,elements:list<string>}> */
    public static function findings(string $html, string $prompt, string $kind): array
    {
        $text = self::text($html);
        $asked = mb_strtolower($prompt);
        $out = [];

        // Prices on a site only. An app screen with a basket or an ad with an offer is showing a
        // mechanism, and "Beispielpreise" in the corner of a phone screen helps nobody.
        if ($kind === 'site' && preg_match(self::PRICE, $prompt) !== 1
            && preg_match_all(self::PRICE, $text, $m) > 0 && preg_match(self::EXAMPLE, $text) !== 1) {
            $out[] = ['severity' => 'blocking', 'check' => 'price-unmarked', 'viewports' => [],
                'detail' => 'the page prints prices the customer never gave; add one small visible line "Beispielpreise" beside the price list, or take the prices out',
                'elements' => array_values(array_slice(array_unique($m[0]), 0, 4))];
        }

        $claims = [];
        foreach (self::CLAIMS as $word) {
            if (str_contains($asked, mb_strtolower($word))) {
                continue;
            }
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'/iu', $text) === 1) {
                $claims[] = $word;
            }
        }
        if (preg_match_all('/(?:seit|since|gegründet)\s+(19\d{2}|20\d{2})/iu', $text, $years, PREG_SET_ORDER) > 0) {
            foreach ($years as $y) {
                if (! str_contains($asked, $y[1])) {
                    $claims[] = $y[0];
                }
            }
        }
        if (preg_match_all('/(?:über\s+)?\d+\s+Jahren?\s+(?:Erfahrung|Backstube|Tradition|im Team|am Markt)/iu', $text, $exp) > 0) {
            $claims = array_merge($claims, $exp[0]);
        }
        if ($claims !== []) {
            $out[] = ['severity' => 'blocking', 'check' => 'claim-invented', 'viewports' => [],
                'detail' => 'the page states facts the customer never gave: insurers, certificates, awards or years in business; take these lines out or say the same thing without the claim',
                'elements' => array_values(array_slice(array_unique($claims), 0, 6))];
        }

        return $out;
    }

    private static function text(string $html): string
    {
        $body = preg_replace('~<(script|style|title)\b.*?</\1>~is', ' ', $html) ?? $html;

        // A space for every tag: "<small>saftig</small><b>4,80 €</b>" is two words, not "saftig4,80".
        return html_entity_decode(preg_replace('~<[^>]*>~', ' ', $body) ?? $body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
