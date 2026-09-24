<?php

namespace App\Domain\Sites;

use App\Models\Project;

/**
 * The Impressum of a bought website (2026-09-24).
 *
 * A business website in Austria (§ 5 ECG, § 25 MediaG) and in Germany (§ 5 DDG) must name who
 * runs it. The owner fills the fields in the portal; the page renders them at /impressum and the
 * site's footer links there. The content is the owner's statement: we check that the fields every
 * business needs are there, not whether a register number is right.
 */
class Imprint
{
    /** Every business: who, where, how to reach them. */
    public const REQUIRED = ['name', 'street', 'zip_city', 'country', 'email'];

    /** Depending on the business: VAT id, register, chamber, authority, trade, anything else. */
    public const OPTIONAL = ['owner', 'phone', 'vat_id', 'register', 'chamber', 'authority', 'trade', 'extra'];

    /** @return array<string,string> */
    public static function rules(): array
    {
        $rules = [];
        foreach (self::REQUIRED as $f) {
            $rules[$f] = 'required|string|max:200';
        }
        foreach (self::OPTIONAL as $f) {
            $rules[$f] = 'nullable|string|max:200';
        }
        $rules['email'] = 'required|email|max:200';
        $rules['extra'] = 'nullable|string|max:1500';

        return $rules;
    }

    /** @param array<string,mixed>|null $imprint */
    public static function complete(?array $imprint): bool
    {
        foreach (self::REQUIRED as $f) {
            if (trim((string) ($imprint[$f] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /** The page. Plain, readable, in the site's own language. */
    public static function page(Project $project, string $lang, string $home): string
    {
        $i = $project->imprint ?? [];
        $de = $lang === 'de';
        $v = fn (string $k) => trim((string) ($i[$k] ?? ''));
        $row = fn (string $label, string $value) => $value === '' ? '' : '<dt>'.e($label).'</dt><dd>'.nl2br(e($value)).'</dd>';

        $rows = implode('', [
            $row($de ? 'Unternehmen' : 'Business', $v('name')),
            $row($de ? 'Inhaber oder Geschäftsführung' : 'Owner or managing director', $v('owner')),
            $row($de ? 'Unternehmensgegenstand' : 'Business activity', $v('trade')),
            $row($de ? 'Anschrift' : 'Address', implode("\n", array_filter([$v('street'), $v('zip_city'), $v('country')]))),
            $row('E-Mail', $v('email')),
            $row($de ? 'Telefon' : 'Phone', $v('phone')),
            $row($de ? 'UID-Nummer' : 'VAT ID', $v('vat_id')),
            $row($de ? 'Firmenbuch oder Handelsregister' : 'Company register', $v('register')),
            $row($de ? 'Kammer oder Berufsverband' : 'Chamber or professional body', $v('chamber')),
            $row($de ? 'Aufsichtsbehörde' : 'Supervisory authority', $v('authority')),
            $row($de ? 'Weitere Angaben' : 'Further information', $v('extra')),
        ]);
        $title = $de ? 'Impressum' : 'Legal notice';
        $lead = $de
            ? 'Angaben nach § 5 ECG und § 25 Mediengesetz (Österreich) sowie § 5 DDG (Deutschland).'
            : 'Information under § 5 ECG and § 25 Media Act (Austria) and § 5 DDG (Germany).';
        $back = $de ? 'Zurück zur Startseite' : 'Back to the home page';
        $made = $de ? 'Website erstellt mit Appwerk (Codemenschen GmbH).' : 'Website made with Appwerk (Codemenschen GmbH).';

        return '<!doctype html><html lang="'.$lang.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.e($title.' · '.$v('name')).'</title>'
            .'<style>body{font:16px/1.55 system-ui,-apple-system,sans-serif;margin:0;background:#f7f7f5;color:#1d1d1f}main{max-width:640px;margin:0 auto;padding:40px 20px 56px}'
            .'h1{font-size:28px;margin:0 0 6px}p{margin:0 0 20px;color:#555}dl{margin:0;display:grid;grid-template-columns:minmax(140px,220px) 1fr;gap:10px 18px}'
            .'dt{color:#666}dd{margin:0}a{color:inherit}.foot{margin-top:36px;font-size:13px;color:#777}'
            .'@media(max-width:520px){dl{grid-template-columns:1fr;gap:2px}dd{margin-bottom:10px}}</style></head>'
            .'<body><main><h1>'.e($title).'</h1><p>'.e($lead).'</p><dl>'.$rows.'</dl>'
            .'<p class="foot"><a href="'.e($home).'">'.e($back).'</a><br>'.e($made).'</p></main></body></html>';
    }
}
