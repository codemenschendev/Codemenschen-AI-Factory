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
        'formpilot' => ['name' => 'FormPilot', 'price' => 1290, 'appType' => 'B'],
        'mealgrid' => ['name' => 'Mealgrid', 'price' => 590, 'appType' => 'A'],
        'countbee' => ['name' => 'Countbee', 'price' => 300, 'appType' => 'A'],
        'praxo' => ['name' => 'Praxo', 'price' => 890, 'appType' => 'B'],
        'rechni' => ['name' => 'Rechni', 'price' => 990, 'appType' => 'B'],
    ];

    /** @return array{name:string,price:int,appType:string}|null */
    public static function find(string $slug): ?array
    {
        return self::ALL[$slug] ?? null;
    }
}
