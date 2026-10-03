<?php

/*
 * Sofabuilt (docs/specs/sofabuilt.md): what the desk agent may price and what it knows about the
 * market. The agent picks module keys; the price is computed here, never by the model.
 *
 * Prices in EUR are the first proposal (2026-10-03), to be confirmed by the owner. Catalogue prices
 * are approximate list prices of other vendors for one site and one year, shown as "about".
 */
return [

    'modules' => [
        'base' => ['eur' => 290, 'en' => 'The plugin itself: a settings page, ready for other languages, removes itself cleanly', 'de' => 'Das Plugin selbst: eine Einstellungsseite, bereit für andere Sprachen, entfernt sich sauber'],
        'data' => ['eur' => 90, 'en' => 'Its own kind of entries you manage in the admin (for example bookings)', 'de' => 'Eigene Einträge, die du im Admin verwaltest (zum Beispiel Buchungen)'],
        'block' => ['eur' => 80, 'en' => 'A part you place on any page of your site', 'de' => 'Ein Baustein, den du auf jede Seite setzen kannst'],
        'form' => ['eur' => 90, 'en' => 'A form for your visitors that checks what they type', 'de' => 'Ein Formular für deine Besucher, das die Eingaben prüft'],
        'woo' => ['eur' => 150, 'en' => 'Works with your shop: products, cart, checkout or orders', 'de' => 'Arbeitet mit deinem Shop: Produkte, Warenkorb, Kasse oder Bestellungen'],
        'admin_list' => ['eur' => 90, 'en' => 'An overview in the admin with filters and export to Excel', 'de' => 'Eine Übersicht im Admin mit Filtern und Export nach Excel'],
        'rest' => ['eur' => 60, 'en' => 'Updates on the page without reloading it', 'de' => 'Änderungen auf der Seite ohne neu zu laden'],
        'external_api' => ['eur' => 150, 'en' => 'Connection to another service you use, per service', 'de' => 'Verbindung zu einem anderen Dienst, den du nutzt, pro Dienst'],
        'email' => ['eur' => 60, 'en' => 'E-mails to you or your customers, with texts you can change', 'de' => 'E-Mails an dich oder deine Kunden, mit Texten, die du ändern kannst'],
        'schedule' => ['eur' => 50, 'en' => 'Something that runs by itself on a schedule', 'de' => 'Etwas, das von selbst nach Zeitplan läuft'],
        'roles' => ['eur' => 60, 'en' => 'Who may see or change what', 'de' => 'Wer was sehen oder ändern darf'],
        'payments' => ['eur' => 200, 'en' => 'Taking payments', 'de' => 'Zahlungen annehmen'],
        'import_export' => ['eur' => 90, 'en' => 'Import and export of data', 'de' => 'Import und Export von Daten'],
    ],

    // Modules that may be ordered more than once (one line per service).
    'repeatable' => ['external_api' => 4],

    // A scope above this is too big for one fixed-price build: the desk suggests splitting it.
    'max_build_eur' => 1990,

    'launch' => [
        'salesPage' => ['eur' => 299, 'en' => 'Sales page with sign-up form', 'de' => 'Verkaufsseite mit Anmeldeformular'],
        'listing' => ['eur' => 79, 'en' => 'Store listing: texts, banner, icon, submission', 'de' => 'Store-Eintrag: Texte, Banner, Icon, Einreichung'],
        'ads' => ['eur' => 129, 'en' => 'Ads for Google and Meta, plus AI search visibility', 'de' => 'Anzeigen für Google und Meta, dazu Sichtbarkeit in KI-Suchen'],
    ],

    'care_monthly_eur' => 19,

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
