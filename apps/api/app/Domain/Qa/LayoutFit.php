<?php

namespace App\Domain\Qa;

use DOMDocument;
use DOMXPath;

/**
 * Whether a page built on a layout pack kept what the pack was chosen for.
 *
 * The prompt says to keep every section and every device, and three rounds of real builds showed
 * that the model reads that as advice: the same bakery brief kept six sections once and five the
 * next time, and the physiotherapy page never put its booking form in the first screen. So each
 * pack names, in its manifest line, what it must keep, and a page that dropped it goes to the
 * repair pass like any other fault.
 *
 * Only what can be counted is checked. Whether the page is beautiful stays a human judgement.
 */
class LayoutFit
{
    /** Devices a manifest line can ask for, and what the repair is told when one is missing. */
    public const DEVICES = [
        'form-first' => 'the first section must hold the working form (fields and a submit button), not only a button that jumps down to it',
        'dotted-leader' => 'the price list must run a dotted rule between each name and its price',
        'quote' => 'a customer quote must stand as a <blockquote>',
        'numbered-list' => 'the steps must be an <ol> of at least three numbered items',
        'rotated-badge' => 'a small badge must sit rotated a few degrees over the corner of a picture or block',
    ];

    /**
     * @param  array{sections?:int,devices?:list<string>}  $keeps
     * @return list<array{severity:string,check:string,viewports:list<string>,detail:string,elements:list<string>}>
     */
    public static function findings(string $html, array $keeps, string $slug): array
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xp = new DOMXPath($doc);
        $css = implode("\n", array_map(fn ($n) => $n->textContent, iterator_to_array($xp->query('//style'))));

        $missing = [];
        $sections = $xp->query('//section')->length;
        $want = (int) ($keeps['sections'] ?? 0);
        if ($want > 0 && $sections < $want) {
            $missing[] = "the skeleton has {$want} sections and the page has {$sections}; put back the ones that were left out, in the skeleton's order";
        }

        foreach ($keeps['devices'] ?? [] as $device) {
            $kept = match ($device) {
                'form-first' => $xp->query('(//section)[1]//*[self::form or self::select or self::input[not(@type="hidden")]]')->length > 0,
                'dotted-leader' => preg_match('/\bdotted\b/i', $css) === 1,
                'quote' => $xp->query('//blockquote')->length > 0,
                'numbered-list' => $xp->query('//ol[count(li) >= 3]')->length > 0,
                'rotated-badge' => preg_match('/rotate\(\s*-?(?:[1-9]|0?\.\d*[1-9])[\d.]*deg/i', $css) === 1,
                default => true,
            };
            if (! $kept && isset(self::DEVICES[$device])) {
                $missing[] = self::DEVICES[$device];
            }
        }

        if ($missing === []) {
            return [];
        }

        return [[
            'severity' => 'blocking',
            'check' => 'layout-dropped',
            'viewports' => [],
            'detail' => "the page was built on the layout pack {$slug} and dropped part of it; restore it with your own colours and words",
            'elements' => $missing,
        ]];
    }
}
