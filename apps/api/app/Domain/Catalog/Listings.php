<?php

namespace App\Domain\Catalog;

/**
 * Server-side source of truth for catalog listing prices. The storefront
 * renders its own copy (apps/web/src/lib/catalog.ts) but every checkout
 * price is taken from here. Moves to the `listings` table with MVP 2.
 */
class Listings
{
    public const ALL = [
        'formpilot' => ['name' => 'FormPilot', 'price' => 3900, 'appType' => 'B', 'weeksLo' => 6, 'weeksHi' => 9],
        'mealgrid' => ['name' => 'Mealgrid', 'price' => 1400, 'appType' => 'A', 'weeksLo' => 4, 'weeksHi' => 7],
        'countbee' => ['name' => 'Countbee', 'price' => 300, 'appType' => 'A', 'weeksLo' => 3, 'weeksHi' => 5],
        'praxo' => ['name' => 'Praxo', 'price' => 2400, 'appType' => 'B', 'weeksLo' => 5, 'weeksHi' => 8],
        'rechni' => ['name' => 'Rechni', 'price' => 3000, 'appType' => 'B', 'weeksLo' => 5, 'weeksHi' => 8],
    ];

    /** @return array{name:string,price:int,appType:string,weeksLo:int,weeksHi:int}|null */
    public static function find(string $slug): ?array
    {
        return self::ALL[$slug] ?? null;
    }
}
