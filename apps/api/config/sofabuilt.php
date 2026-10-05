<?php

/*
 * Sofabuilt (docs/specs/sofabuilt.md): what the desk agent may price and what it knows about the
 * market. The agent picks module keys; the price is computed here, never by the model.
 *
 * Prices in EUR: halved on 2026-10-05 (owner: a low price to win customers; the money is in Care
 * and the work that follows). Earlier proposal 2026-10-03. Catalogue prices
 * are approximate list prices of other vendors for one site and one year, shown as "about".
 */
return [

    /*
     * Prices by build time (owner, 2026-10-05): every part has the minutes our AI developer usually
     * needs to build and test it; the desk agent estimates the minutes for the idea at hand, and the
     * price is minutes times the platform's hourly rate (admin setting rate_<platform>, default
     * below). At 60 € an hour a minute is a euro, so the old prices carry over unchanged.
     */
    'rate_eur_hour' => 60,

    'modules' => [
        'base' => ['minutes' => 149, 'en' => 'The plugin itself: a settings page, ready for other languages, removes itself cleanly', 'de' => 'Das Plugin selbst: eine Einstellungsseite, bereit für andere Sprachen, entfernt sich sauber'],
        'data' => ['minutes' => 49, 'en' => 'Its own kind of entries you manage in the admin (for example bookings)', 'de' => 'Eigene Einträge, die du im Admin verwaltest (zum Beispiel Buchungen)'],
        'block' => ['minutes' => 39, 'en' => 'A part you place on any page of your site', 'de' => 'Ein Baustein, den du auf jede Seite setzen kannst'],
        'form' => ['minutes' => 49, 'en' => 'A form for your visitors that checks what they type', 'de' => 'Ein Formular für deine Besucher, das die Eingaben prüft'],
        'woo' => ['minutes' => 79, 'en' => 'Works with your shop: products, cart, checkout or orders', 'de' => 'Arbeitet mit deinem Shop: Produkte, Warenkorb, Kasse oder Bestellungen'],
        'admin_list' => ['minutes' => 49, 'en' => 'An overview in the admin with filters and export to Excel', 'de' => 'Eine Übersicht im Admin mit Filtern und Export nach Excel'],
        'rest' => ['minutes' => 29, 'en' => 'Updates on the page without reloading it', 'de' => 'Änderungen auf der Seite ohne neu zu laden'],
        'external_api' => ['minutes' => 79, 'en' => 'Connection to another service you use, per service', 'de' => 'Verbindung zu einem anderen Dienst, den du nutzt, pro Dienst'],
        'email' => ['minutes' => 29, 'en' => 'E-mails to you or your customers, with texts you can change', 'de' => 'E-Mails an dich oder deine Kunden, mit Texten, die du ändern kannst'],
        'schedule' => ['minutes' => 29, 'en' => 'Something that runs by itself on a schedule', 'de' => 'Etwas, das von selbst nach Zeitplan läuft'],
        'roles' => ['minutes' => 29, 'en' => 'Who may see or change what', 'de' => 'Wer was sehen oder ändern darf'],
        'payments' => ['minutes' => 99, 'en' => 'Taking payments', 'de' => 'Zahlungen annehmen'],
        'import_export' => ['minutes' => 49, 'en' => 'Import and export of data', 'de' => 'Import und Export von Daten'],
    ],

    // Modules that may be ordered more than once (one line per service).
    'repeatable' => ['external_api' => 4],

    // A scope above this is too big for one fixed-price build: the desk suggests splitting it.
    'max_build_eur' => 990,

    'launch' => [
        'salesPage' => ['eur' => 299, 'en' => 'Sales page with sign-up form', 'de' => 'Verkaufsseite mit Anmeldeformular'],
        'listing' => ['eur' => 79, 'en' => 'Store listing: texts, banner, icon, submission', 'de' => 'Store-Eintrag: Texte, Banner, Icon, Einreichung'],
        // Sell-ready (2026-10-05): licence keys, automatic updates for buyers and a checkout that also
        // handles EU VAT, through Freemius. We build it in; the seller's Freemius account is theirs.
        'sellReady' => ['eur' => 199, 'en' => 'Ready to sell: licence keys, automatic updates and checkout with VAT handled', 'de' => 'Bereit zum Verkauf: Lizenzschlüssel, automatische Updates und Checkout inklusive Mehrwertsteuer'],
        'ads' => ['eur' => 129, 'en' => 'Ads for Google and Meta, plus AI search visibility', 'de' => 'Anzeigen für Google und Meta, dazu Sichtbarkeit in KI-Suchen'],
    ],

    /*
     * Appmitki apps on the same desk (2026-10-05, owner: "Appmitki the Sofabuilt way, for apps").
     * Prices on Patrick's scale (Teams 2026-10-05: "todo app estimate rough: checkboxes 15, saving of
     * items 5, releasing app to store 15"). The price at checkout is the price on the desk. Parts
     * marked `server` need our server, so the app carries the monthly hosting of a connected app.
     */
    'app' => [
        'modules' => [
            'base' => ['minutes' => 15, 'en' => 'Your app for iPhone and Android, released in the App Store and Google Play', 'de' => 'Deine App für iPhone und Android, veröffentlicht im App Store und bei Google Play'],
            'screen' => ['minutes' => 15, 'en' => 'A screen with its own job, for example a list where you tick off tasks', 'de' => 'Ein Bildschirm mit eigener Aufgabe, zum Beispiel eine Liste zum Abhaken'],
            'local_save' => ['minutes' => 5, 'en' => 'Keeps your entries on the phone', 'de' => 'Merkt sich deine Einträge am Handy'],
            'accounts' => ['minutes' => 20, 'en' => 'Accounts and login', 'de' => 'Konten und Login', 'server' => true],
            'sync' => ['minutes' => 25, 'en' => 'Your data on every device, shared with others', 'de' => 'Deine Daten auf jedem Gerät, mit anderen geteilt', 'server' => true],
            'payments' => ['minutes' => 30, 'en' => 'Payments or subscriptions', 'de' => 'Zahlungen oder Abos', 'server' => true],
            'notifications' => ['minutes' => 10, 'en' => 'Reminders and notifications', 'de' => 'Erinnerungen und Benachrichtigungen'],
            'photos' => ['minutes' => 10, 'en' => 'Photos and camera', 'de' => 'Fotos und Kamera'],
            'maps' => ['minutes' => 15, 'en' => 'Maps and location', 'de' => 'Karten und Standort'],
            'booking' => ['minutes' => 20, 'en' => 'Calendar or booking', 'de' => 'Kalender oder Buchungen'],
            'chat' => ['minutes' => 30, 'en' => 'Chat between users', 'de' => 'Chat zwischen Nutzern', 'server' => true],
            'ai' => ['minutes' => 30, 'en' => 'AI features, for example suggestions or texts', 'de' => 'KI-Funktionen, zum Beispiel Vorschläge oder Texte', 'server' => true],
            'stats' => ['minutes' => 20, 'en' => 'Statistics or an admin area', 'de' => 'Statistiken oder ein Verwaltungsbereich'],
            'external_api' => ['minutes' => 20, 'en' => 'Connection to another service you use, per service', 'de' => 'Verbindung zu einem anderen Dienst, den du nutzt, pro Dienst'],
            'language' => ['minutes' => 5, 'en' => 'Another language, per language', 'de' => 'Eine weitere Sprache, pro Sprache'],
        ],
        'repeatable' => ['screen' => 8, 'external_api' => 3, 'language' => 5],
        // The store release is a part of the app here, so the store package is not sold again.
        'launch' => [
            'landingPage' => ['eur' => 299, 'en' => 'Landing page for your app', 'de' => 'Landingpage für deine App'],
            'marketingLaunch' => ['eur' => 129, 'en' => 'Ads for Google and Meta, plus AI search visibility', 'de' => 'Anzeigen für Google und Meta, dazu Sichtbarkeit in KI-Suchen'],
            'transferAssist' => ['eur' => 49, 'en' => 'Help moving the app to your own store accounts', 'de' => 'Hilfe beim Umzug der App auf deine eigenen Store-Konten'],
        ],
        'care_monthly_eur' => 9,
        'hosting_monthly_eur' => 19,
        'delivery_days' => [1, 2],
    ],

    /*
     * Chrome extensions (2026-10-05, roadmap step 2). Same desk, same pricing rules, its own parts.
     * No premium catalogue yet: Chrome extensions come in through the "own idea" door.
     */
    'chrome' => [
        'modules' => [
            'base' => ['minutes' => 250, 'en' => 'The extension itself: a small window from the toolbar, a settings page, icons and store texts', 'de' => 'Die Erweiterung selbst: ein kleines Fenster aus der Leiste, eine Einstellungsseite, Icons und Store-Texte'],
            'page' => ['minutes' => 120, 'en' => 'Works on the websites you visit: reads or changes what is on the page', 'de' => 'Arbeitet auf den Websites, die du besuchst: liest oder ändert, was dort steht'],
            'background' => ['minutes' => 80, 'en' => 'Runs in the background, for example reminders or regular checks', 'de' => 'Läuft im Hintergrund, zum Beispiel Erinnerungen oder regelmäßige Prüfungen'],
            'context_menu' => ['minutes' => 50, 'en' => 'Entries in the right-click menu', 'de' => 'Einträge im Rechtsklick-Menü'],
            'side_panel' => ['minutes' => 90, 'en' => 'A side panel next to the page', 'de' => 'Eine Seitenleiste neben der Seite'],
            'sync' => ['minutes' => 50, 'en' => 'Your settings follow you to every computer', 'de' => 'Deine Einstellungen begleiten dich auf jeden Computer'],
            'notifications' => ['minutes' => 50, 'en' => 'Notices on your screen', 'de' => 'Hinweise auf deinem Bildschirm'],
            'external_api' => ['minutes' => 150, 'en' => 'Connection to another service you use, per service', 'de' => 'Verbindung zu einem anderen Dienst, den du nutzt, pro Dienst'],
            'shortcuts' => ['minutes' => 40, 'en' => 'Keyboard shortcuts', 'de' => 'Tastenkürzel'],
            'export' => ['minutes' => 70, 'en' => 'Save or export data, for example to Excel', 'de' => 'Daten speichern oder exportieren, zum Beispiel nach Excel'],
            'payments' => ['minutes' => 200, 'en' => 'Paid features with a licence, one-time or monthly', 'de' => 'Bezahlte Funktionen mit Lizenz, einmalig oder monatlich'],
        ],
        'repeatable' => ['external_api' => 4],
        'launch' => [
            'salesPage' => ['eur' => 299, 'en' => 'Sales page with sign-up form', 'de' => 'Verkaufsseite mit Anmeldeformular'],
            'listing' => ['eur' => 79, 'en' => 'Chrome Web Store listing: texts, screenshots, icon, submission', 'de' => 'Eintrag im Chrome Web Store: Texte, Screenshots, Icon, Einreichung'],
            'ads' => ['eur' => 129, 'en' => 'Ads for Google and Meta, plus AI search visibility', 'de' => 'Anzeigen für Google und Meta, dazu Sichtbarkeit in KI-Suchen'],
        ],
        'catalog' => [],
    ],

    /*
     * Shopify apps (2026-10-05, Patrick's plan: "a pipeline for stores like Shopify and WordPress").
     * An embedded app in the Shopify admin on Shopify's own app template; the merchant hosts it.
     * Catalogue prices are the cheapest paid plan on apps.shopify.com, checked 2026-10-05; the desk
     * shows them per year.
     */
    'shopify' => [
        'modules' => [
            'base' => ['minutes' => 199, 'en' => 'The app itself: its own page in your Shopify admin, safe install and removal, privacy rules handled', 'de' => 'Die App selbst: eine eigene Seite in deinem Shopify-Admin, sichere Installation und Entfernung, Datenschutz-Regeln erledigt'],
            'storefront' => ['minutes' => 59, 'en' => 'A block on your shop pages that you place in the theme editor', 'de' => 'Ein Baustein auf deinen Shop-Seiten, den du im Theme-Editor platzierst'],
            'products' => ['minutes' => 49, 'en' => 'Works with your products: reads or changes them', 'de' => 'Arbeitet mit deinen Produkten: liest oder ändert sie'],
            'orders' => ['minutes' => 59, 'en' => 'Works with your orders', 'de' => 'Arbeitet mit deinen Bestellungen'],
            'customers' => ['minutes' => 49, 'en' => 'Works with your customers', 'de' => 'Arbeitet mit deinen Kunden'],
            'discounts' => ['minutes' => 79, 'en' => 'Your own discount rules in the cart and at checkout', 'de' => 'Eigene Rabattregeln im Warenkorb und an der Kasse'],
            'checkout' => ['minutes' => 99, 'en' => 'Changes in the checkout or on the thank-you page', 'de' => 'Änderungen an der Kasse oder auf der Danke-Seite'],
            'data' => ['minutes' => 49, 'en' => 'Its own kind of entries you manage in the admin', 'de' => 'Eigene Einträge, die du im Admin verwaltest'],
            'admin_list' => ['minutes' => 49, 'en' => 'An overview in the admin with filters and export to Excel', 'de' => 'Eine Übersicht im Admin mit Filtern und Export nach Excel'],
            'external_api' => ['minutes' => 79, 'en' => 'Connection to another service you use, per service', 'de' => 'Verbindung zu einem anderen Dienst, den du nutzt, pro Dienst'],
            'email' => ['minutes' => 29, 'en' => 'E-mails to you or your customers, with texts you can change', 'de' => 'E-Mails an dich oder deine Kunden, mit Texten, die du ändern kannst'],
            'schedule' => ['minutes' => 29, 'en' => 'Something that runs by itself on a schedule', 'de' => 'Etwas, das von selbst nach Zeitplan läuft'],
            'import_export' => ['minutes' => 49, 'en' => 'Import and export of data', 'de' => 'Import und Export von Daten'],
            'billing' => ['minutes' => 69, 'en' => 'Monthly plans for the shops that install it, billed by Shopify', 'de' => 'Monatliche Tarife für die Shops, die sie installieren, abgerechnet über Shopify'],
        ],
        'repeatable' => ['external_api' => 4],
        // Care for a Shopify app includes running it on our server: Shopify needs it hosted.
        'care_monthly_eur' => 29,
        'launch' => [
            'salesPage' => ['eur' => 299, 'en' => 'Sales page with sign-up form', 'de' => 'Verkaufsseite mit Anmeldeformular'],
            'listing' => ['eur' => 99, 'en' => 'Shopify App Store listing: texts, screenshots, icon, submission', 'de' => 'Eintrag im Shopify App Store: Texte, Screenshots, Icon, Einreichung'],
            'ads' => ['eur' => 129, 'en' => 'Ads for Google and Meta, plus AI search visibility', 'de' => 'Anzeigen für Google und Meta, dazu Sichtbarkeit in KI-Suchen'],
        ],
        'catalog' => [
            ['id' => 'reviews', 'name' => 'Judge.me Product Reviews', 'category' => 'Reviews', 'price' => '$15/mo', 'reviews' => 47971,
                'features' => ['stars and reviews on products', 'customers add photos and videos', 'review request after each order', 'reply to reviews', 'bring in old reviews'],
                'modules' => ['base', 'storefront', 'products', 'orders', 'email', 'data', 'admin_list']],
            ['id' => 'wishlist', 'name' => 'Swym Wishlist Plus', 'category' => 'Wishlist', 'price' => '$29.99/mo', 'reviews' => 1481,
                'features' => ['heart button on products', 'save favourites for later', 'share a list with friends', 'mail when price drops', 'see most wanted products'],
                'modules' => ['base', 'storefront', 'products', 'customers', 'data', 'email']],
            ['id' => 'back-in-stock', 'name' => 'Amp Back in Stock & Preorder', 'category' => 'Restock alerts', 'price' => '$19/mo', 'reviews' => 954,
                'features' => ['notify me button', 'mail when item is back', 'low stock badge', 'list of waiting customers', 'see which items people want'],
                'modules' => ['base', 'storefront', 'products', 'data', 'email']],
            ['id' => 'product-options', 'name' => 'Infinite Options', 'category' => 'Product options', 'price' => '$12.99/mo', 'reviews' => 2618,
                'features' => ['text field for engraving', 'dropdowns, checkboxes and swatches', 'extra cost for add-ons', 'show options only when needed', 'choices saved on the order'],
                'modules' => ['base', 'storefront', 'products', 'orders', 'data']],
            ['id' => 'size-chart', 'name' => 'Kiwi Size Chart & Recommender', 'category' => 'Size charts', 'price' => '$7.99/mo', 'reviews' => 1226,
                'features' => ['size chart on product page', 'ready-made chart templates', 'upload charts from a spreadsheet', 'cm and inch switch', 'fewer returns from wrong sizes'],
                'modules' => ['base', 'storefront', 'products', 'data', 'import_export']],
            ['id' => 'bundles', 'name' => 'Kaching Bundles', 'category' => 'Bundles', 'price' => '$14.99/mo', 'reviews' => 6158,
                'features' => ['buy more, pay less', 'product sets at one price', 'free gift with order', 'buy one get one', 'see extra money earned'],
                'modules' => ['base', 'storefront', 'products', 'discounts', 'data']],
            ['id' => 'loyalty', 'name' => 'Smile: Loyalty Program Rewards', 'category' => 'Loyalty', 'price' => '$15/mo', 'reviews' => 4616,
                'features' => ['points for every purchase', 'swap points for discounts', 'reward for inviting friends', 'vip levels for top buyers', 'points balance in customer account'],
                'modules' => ['base', 'storefront', 'customers', 'orders', 'discounts', 'data', 'email']],
            ['id' => 'image-seo', 'name' => 'TinySEO: SEO & Image Optimizer', 'category' => 'SEO, Images', 'price' => '$14/mo', 'reviews' => 2527,
                'features' => ['smaller pictures, same quality', 'pages open faster', 'pictures load only when needed', 'picture descriptions for Google', 'fix broken links'],
                'modules' => ['base', 'products', 'schedule', 'admin_list']],
            ['id' => 'cookie-consent', 'name' => 'Pandectes GDPR Compliance', 'category' => 'Cookie consent', 'price' => '$9/mo', 'reviews' => 3091,
                'features' => ['cookie banner in your design', 'trackers wait for consent', 'record of every consent', 'banner in several languages', 'different rules per country'],
                'modules' => ['base', 'storefront', 'data', 'admin_list']],
            ['id' => 'invoices', 'name' => 'Sufio: Invoice You Can Trust', 'category' => 'Invoices', 'price' => '$7/mo', 'reviews' => 466,
                'features' => ['invoice made for every order', 'invoice sent by mail', 'invoice numbers in order', 'your logo on documents', 'print many invoices at once'],
                'modules' => ['base', 'orders', 'customers', 'email', 'data']],
            ['id' => 'countdown', 'name' => 'Hextom: Countdown Timer Bar', 'category' => 'Urgency', 'price' => '$9.99/mo', 'reviews' => 764,
                'features' => ['countdown bar for sales', 'timer repeats every day', 'plan sales in advance', 'own colours and text', 'show only on chosen pages'],
                'modules' => ['base', 'storefront', 'data', 'schedule']],
            ['id' => 'wholesale', 'name' => 'Wholesale Pricing Discount B2B', 'category' => 'Wholesale', 'price' => '$24.99/mo', 'reviews' => 736,
                'features' => ['special prices for trade customers', 'cheaper when buying more', 'sign-up form for resellers', 'pay later by invoice', 'upload prices from a spreadsheet'],
                'modules' => ['base', 'storefront', 'products', 'customers', 'discounts', 'data', 'import_export']],
        ],
    ],

    // What the desk offers (2026-10-05, owner: "focus only Shopify, WordPress"). Chrome stays built and hidden.
    'offered' => ['wordpress', 'shopify'],

    /*
     * Care (2026-10-05): updates for new platform versions, security fixes, and changes asked for in
     * the customer's project page. The first months are free when the buyer ticks it at checkout;
     * then it renews monthly until cancelled. Care customers pay less for new features.
     */
    'care_monthly_eur' => 19,
    'care_trial_months' => 3,
    'care_feature_discount_pct' => 20,

    'delivery_days' => [2, 3],

    /*
     * Paid plugins that sell well, for the "own version" door. `slug` is the free plugin on
     * wordpress.org when the vendor has one (live installs and rating come from its API).
     * `modules` is a typical scope for our own version of the core features, not a clone.
     */
    'catalog' => [
        ['id' => 'caching', 'name' => 'WP Rocket', 'category' => 'Speed', 'price' => '$59', 'slug' => null,
            'features' => ['pages open faster', 'smaller page files', 'pictures load only when needed', 'cleans up old copies of posts', 'keeps important pages ready'],
            'modules' => ['base', 'schedule', 'admin_list']],
        ['id' => 'forms', 'name' => 'Gravity Forms', 'category' => 'Forms', 'price' => '$59', 'slug' => null,
            'features' => ['build forms by clicking', 'fields that appear only when needed', 'all answers in the admin', 'e-mail when someone sends a form', 'file uploads'],
            'modules' => ['base', 'form', 'data', 'admin_list', 'email']],
        ['id' => 'wpforms', 'name' => 'WPForms Pro', 'category' => 'Forms', 'price' => '$199', 'slug' => 'wpforms-lite',
            'features' => ['build forms by dragging', 'surveys', 'payments in forms', 'all answers in the admin', 'e-mail notices'],
            'modules' => ['base', 'form', 'data', 'admin_list', 'email']],
        ['id' => 'acf', 'name' => 'Advanced Custom Fields PRO', 'category' => 'Content fields', 'price' => '$49', 'slug' => 'advanced-custom-fields',
            'features' => ['extra fields on pages and posts', 'repeating sections', 'settings pages', 'picture galleries'],
            'modules' => ['base', 'data', 'block']],
        ['id' => 'seo', 'name' => 'Yoast SEO Premium', 'category' => 'SEO', 'price' => '$99', 'slug' => 'wordpress-seo',
            'features' => ['forward old addresses to new pages', 'suggestions for links between your pages', 'several search words per page', 'how a page looks when shared'],
            'modules' => ['base', 'data', 'admin_list']],
        ['id' => 'subscriptions', 'name' => 'WooCommerce Subscriptions', 'category' => 'Shop', 'price' => '$279', 'slug' => null,
            'features' => ['customers pay every month', 'customers manage their subscription', 'renews by itself', 'reminder e-mails'],
            'modules' => ['base', 'woo', 'data', 'schedule', 'email', 'payments']],
        ['id' => 'bookings', 'name' => 'WooCommerce Bookings', 'category' => 'Shop', 'price' => '$249', 'slug' => null,
            'features' => ['book appointments or rentals', 'your opening times and free slots', 'booking calendar', 'confirmation e-mails'],
            'modules' => ['base', 'woo', 'data', 'admin_list', 'email']],
        ['id' => 'addons', 'name' => 'WooCommerce Product Add-Ons', 'category' => 'Shop', 'price' => '$59', 'slug' => null,
            'features' => ['extra choices on a product', 'each choice can change the price', 'customers can add a text or a file'],
            'modules' => ['base', 'woo', 'form']],
        ['id' => 'wishlist', 'name' => 'YITH WooCommerce Wishlist Premium', 'category' => 'Shop', 'price' => '€95', 'slug' => 'yith-woocommerce-wishlist',
            'features' => ['several wishlists', 'share a wishlist by e-mail', 'notice when a price drops', 'see what people wish for'],
            'modules' => ['base', 'woo', 'data', 'email', 'admin_list']],
        ['id' => 'invoices', 'name' => 'PDF Invoices & Packing Slips Pro', 'category' => 'Shop', 'price' => '€69', 'slug' => 'woocommerce-pdf-invoices-packing-slips',
            'features' => ['invoices as PDF', 'packing slips', 'credit notes', 'invoice attached to the order e-mail'],
            'modules' => ['base', 'woo', 'email']],
        ['id' => 'membership', 'name' => 'MemberPress', 'category' => 'Memberships', 'price' => '$179', 'slug' => null,
            'features' => ['paid memberships', 'pages only members can see', 'a member account page', 'members pay online'],
            'modules' => ['base', 'roles', 'data', 'payments', 'email']],
        ['id' => 'courses', 'name' => 'LearnDash', 'category' => 'Courses', 'price' => '$199', 'slug' => null,
            'features' => ['courses and lessons', 'quizzes', 'see how far each student is', 'certificates'],
            'modules' => ['base', 'data', 'block', 'roles', 'admin_list']],
        ['id' => 'instagram', 'name' => 'Smash Balloon Instagram Feed Pro', 'category' => 'Social', 'price' => '$49', 'slug' => 'instagram-feed',
            'features' => ['your Instagram posts on your site', 'different layouts', 'posts with a hashtag'],
            'modules' => ['base', 'external_api', 'block', 'schedule']],
        ['id' => 'analytics', 'name' => 'MonsterInsights Pro', 'category' => 'Analytics', 'price' => '$199', 'slug' => 'google-analytics-for-wordpress',
            'features' => ['your visitor numbers in the admin', 'shop sales reports', 'your most read pages'],
            'modules' => ['base', 'external_api', 'admin_list']],
        ['id' => 'smtp', 'name' => 'WP Mail SMTP Pro', 'category' => 'E-mail', 'price' => '$49', 'slug' => 'wp-mail-smtp',
            'features' => ['your site e-mails arrive reliably', 'a list of sent e-mails', 'send failed e-mails again'],
            'modules' => ['base', 'external_api', 'admin_list']],
        ['id' => 'backup', 'name' => 'UpdraftPlus Premium', 'category' => 'Backups', 'price' => '$70', 'slug' => 'updraftplus',
            'features' => ['automatic backups', 'stored safely online', 'restore with one click'],
            'modules' => ['base', 'schedule', 'external_api', 'import_export']],
        ['id' => 'search', 'name' => 'SearchWP', 'category' => 'Search', 'price' => '$99', 'slug' => null,
            'features' => ['better search on your site', 'also finds text in PDFs', 'see what people search for'],
            'modules' => ['base', 'data', 'admin_list']],
        ['id' => 'filters', 'name' => 'FacetWP', 'category' => 'Search', 'price' => '$99', 'slug' => null,
            'features' => ['filters for lists and shops', 'results change without reloading', 'filter by category, price or colour'],
            'modules' => ['base', 'block', 'rest']],
        ['id' => 'import', 'name' => 'WP All Import Pro', 'category' => 'Data', 'price' => '$99', 'slug' => 'wp-all-import',
            'features' => ['import from Excel or other files', 'choose which column goes where', 'imports that run by themselves'],
            'modules' => ['base', 'import_export', 'schedule']],
        ['id' => 'perf', 'name' => 'Perfmatters', 'category' => 'Speed', 'price' => '$25', 'slug' => null,
            'features' => ['switch off what you do not use', 'load only what each page needs', 'pictures load only when needed'],
            'modules' => ['base', 'admin_list']],
    ],
];
