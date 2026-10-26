<?php

namespace App\Domain\Ads;

use App\Domain\Ai\PrototypeWriter;

/**
 * What Codemenschen's own ads sell, said to every writer that works on them (2026-10-26).
 *
 * The writers only read what an admin typed into the campaign. For the Google draft that was
 * "Kleine Betriebe und Selbständige ohne eigene IT", and the agent guessed the rest: 25 keywords for
 * websites, homepages and web design. Patrick: the ads sell the APP, never a website or a landing page.
 * The product comes from the storefront's offer switch, so the ads always follow what is on sale.
 */
class OwnOffer
{
    /** Searches that are about something else than an app, while only apps are on offer. */
    private const OFF_TOPIC = '~(website|webseite|web seite|homepage|webdesign|web design|landing ?page|landingpage|\bseo\b|online ?shop|webshop|grafik|logo|baukasten|wordpress|werbeanzeig|werbeagentur|anzeigen erstellen)~iu';

    public static function appsOnly(): bool
    {
        return PrototypeWriter::offered() === ['app'];
    }

    /** The product line every brief for an own campaign starts with. */
    public static function brief(): string
    {
        if (self::appsOnly()) {
            return 'Product: Appmitki (appmitki.com) builds custom apps for small businesses: iPhone, Android or web app. '
                .'The visitor describes the app in one sentence, sees a free clickable preview in minutes, then orders the app at a fixed price. '
                .'Code and rights belong to the customer. '
                .'Appmitki does NOT sell websites, homepages, landing pages, web design, SEO, online shops or ad services. '
                .'Every keyword, headline and text must be about getting an app built.';
        }

        return 'Product: Appmitki (appmitki.com) builds '.implode(', ', PrototypeWriter::offered()).' for small businesses, after a free preview, at a fixed price.';
    }

    /** True for a search or line about something not on offer. */
    public static function offTopic(string $text): bool
    {
        return self::appsOnly() && preg_match(self::OFF_TOPIC, $text) === 1;
    }
}
