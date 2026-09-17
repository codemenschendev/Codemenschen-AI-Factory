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

    /** Urgency a visitor is pushed by: limited places, last chances, closing dates. */
    private const SCARCITY = '/\b(?:begrenzte?n?\s+(?:Zahl|Anzahl|Plätze|Kapazität)|nur\s+noch\s+\w+|wenige\s+(?:freie\s+)?(?:Plätze|Termine|Projektplätze)|\w*plätze\s+(?:in\s+\w+\s+)?frei|solange\s+(?:der\s+)?Vorrat|nur\s+(?:diese|heute|bis)\b\s*\w*|limited\s+(?:spots|places|time|availability)|only\s+\d+\s+(?:left|spots)|last\s+chance|letzte\s+Chance)/iu';

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
        // Scarcity and deadlines the business never announced. Forbidden in the ads prompt, and a
        // codemenschen.at ad closed on "Nur eine begrenzte Zahl an WordPress-Projekten
        // gleichzeitig, jetzt Platz sichern" two builds running anyway.
        if (preg_match_all(self::SCARCITY, $text, $rush) > 0) {
            foreach ($rush[0] as $phrase) {
                if (mb_stripos($asked, mb_strtolower(trim($phrase))) === false) {
                    $claims[] = trim($phrase);
                }
            }
        }
        if (preg_match_all('/(?:über\s+)?\d+\s+Jahren?\s+(?:Erfahrung|Backstube|Tradition|im Team|am Markt)/iu', $text, $exp) > 0) {
            $claims = array_merge($claims, $exp[0]);
        }
        if ($claims !== []) {
            $out[] = ['severity' => 'blocking', 'check' => 'claim-invented', 'viewports' => [],
                'detail' => 'the page states facts the customer never gave: insurers, certificates, awards, years in business, or scarcity and deadlines; take these lines out or say the same thing without the claim',
                'elements' => array_values(array_slice(array_unique($claims), 0, 6))];
        }

        if (($reworded = self::reworded($text, $prompt)) !== []) {
            $out[] = ['severity' => 'blocking', 'check' => 'number-reworded', 'viewports' => [],
                'detail' => 'a number from the customer\'s own words or website is printed with a different meaning; keep the noun it has there (downloads stay downloads)',
                'elements' => array_slice($reworded, 0, 4)];
        }

        if (($personal = self::personal($text, $prompt)) !== []) {
            $out[] = ['severity' => 'blocking', 'check' => 'personal-data', 'viewports' => [],
                'detail' => 'the page prints a real person\'s name or e-mail address that the customer never gave; replace it with an invented person on example.com, for example "Anna" and anna@example.com',
                'elements' => $personal];
        }

        if ($kind === 'ads' && ($mixed = self::labelLanguage($html, $text)) !== null) {
            $out[] = ['severity' => 'blocking', 'check' => 'label-language', 'viewports' => [],
                'detail' => 'the platform labels are in a different language from the page; write "sponsored" and "learn more" in the language of the ads and of <html lang>',
                'elements' => [$mixed]];
        }

        return $out;
    }

    /** Numbers of three digits or more with the words that follow them. The ad sizes are not claims. */
    private const COUNT = '/(?<![\p{N}.,])(\d{1,3}(?:[.,\x{00A0}\x{202F} ]\d{3})+|\d{3,})\+?\s+(\p{L}[\p{L}-]*)(?:\s+(\p{L}[\p{L}-]*))?(?:\s+(\p{L}[\p{L}-]*))?/u';

    private const SIZES = ['1080', '1920', '1200', '628'];

    /**
     * A number the source gives, printed with another noun. The site said "20,000 Downloads" and
     * the ad said "20,000 stores already sell gift cards with it": the number was real, the claim
     * was not. A number the source never gives is not judged here.
     *
     * @return list<string> the page's phrase and what the source says, for the repair
     */
    private static function reworded(string $text, string $source): array
    {
        $stems = fn (array $words) => array_values(array_filter(array_map(
            fn ($w) => mb_strlen((string) $w) >= 3 ? mb_substr(mb_strtolower((string) $w), 0, 5) : null, $words)));
        $said = [];
        if (preg_match_all(self::COUNT, $source, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $hit) {
                $said[preg_replace('/\D/', '', $hit[1])][] = $hit;
            }
        }

        $out = [];
        preg_match_all(self::COUNT, $text, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            $n = preg_replace('/\D/', '', $hit[1]);
            if (in_array($n, self::SIZES, true) || ! isset($said[$n])) {
                continue;
            }
            $page = $stems(array_slice($hit, 2));
            $matches = false;
            foreach ($said[$n] as $there) {
                if (array_intersect($page, $stems(array_slice($there, 2))) !== []) {
                    $matches = true;
                    break;
                }
            }
            if (! $matches) {
                $out[] = trim($hit[0]).' (the source says: '.trim($said[$n][0][0]).')';
            }
        }

        return array_values(array_unique($out));
    }

    /** Mail providers people use privately. An invented info@ address of the business is not a person. */
    private const PRIVATE_MAIL = ['gmail.com', 'googlemail.com', 'gmx.at', 'gmx.net', 'gmx.de', 'outlook.com', 'hotmail.com', 'live.com', 'yahoo.com', 'icloud.com', 'me.com', 'aon.at', 'web.de', 'proton.me', 'protonmail.com'];

    /**
     * A real person on the page. The gateway runs claude-cli signed in as the owner, and Claude
     * Code puts that account's e-mail in the model's context: a prototype's account screen came
     * back as the owner's full name and address, and an app greeted its user "Servus, Patrick".
     * Checked against the configured identities, and any private-mail address the customer did
     * not give.
     *
     * @return list<string>
     */
    private static function personal(string $text, string $source): array
    {
        $found = [];
        foreach ((array) config('services.ai_image.private_identities', []) as $who) {
            $who = trim((string) $who);
            if (mb_strlen($who) < 3 || mb_stripos($source, $who) !== false) {
                continue;
            }
            if (preg_match('/(?<![\p{L}\p{N}@.])'.preg_quote($who, '/').'(?![\p{L}\p{N}])/iu', $text) === 1) {
                $found[] = $who;
            }
        }
        if (preg_match_all('/[\p{L}\p{N}._%+-]+@([\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)+)/u', $text, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $mail) {
                if (in_array(mb_strtolower($mail[1]), self::PRIVATE_MAIL, true) && mb_stripos($source, $mail[0]) === false) {
                    $found[] = $mail[0];
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** "Gesponsert" on an English page or "Sponsored" on a German one. */
    private static function labelLanguage(string $html, string $text): ?string
    {
        $lang = preg_match('/<html[^>]*\blang=["\']?([a-z]{2})/i', $html, $m) === 1 ? strtolower($m[1]) : null;
        $german = preg_match('/\b(Gesponsert|Mehr dazu)\b/u', $text, $g) === 1;
        $english = preg_match('/\b(Sponsored|Learn more)\b/u', $text, $e) === 1;
        if ($german && ($lang === 'en' || $english)) {
            return "\"{$g[1]}\" on a page in ".($lang ?? 'English');
        }
        if ($english && $lang === 'de') {
            return "\"{$e[1]}\" on a page in de";
        }

        return null;
    }

    private static function text(string $html): string
    {
        $body = preg_replace('~<(script|style|title)\b.*?</\1>~is', ' ', $html) ?? $html;

        // A space for every tag: "<small>saftig</small><b>4,80 €</b>" is two words, not "saftig4,80".
        return html_entity_decode(preg_replace('~<[^>]*>~', ' ', $body) ?? $body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
