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
        'base' => ['eur' => 290, 'en' => 'Plugin base: settings page, translations, clean uninstall, readme', 'de' => 'Plugin-Grundlage: Einstellungsseite, Übersetzungen, sauberes Deinstallieren, Readme'],
        'data' => ['eur' => 90, 'en' => 'Own data type or table (for example bookings or entries)', 'de' => 'Eigener Datentyp oder Tabelle (zum Beispiel Buchungen oder Einträge)'],
        'block' => ['eur' => 80, 'en' => 'Block or shortcode for the front end', 'de' => 'Block oder Shortcode für die Website'],
        'form' => ['eur' => 90, 'en' => 'Front-end form with validation', 'de' => 'Formular auf der Website mit Prüfung der Eingaben'],
        'woo' => ['eur' => 150, 'en' => 'WooCommerce integration (products, cart, checkout or orders)', 'de' => 'WooCommerce-Anbindung (Produkte, Warenkorb, Kasse oder Bestellungen)'],
        'admin_list' => ['eur' => 90, 'en' => 'Admin list or report with filters and CSV export', 'de' => 'Admin-Liste oder Bericht mit Filtern und CSV-Export'],
        'rest' => ['eur' => 60, 'en' => 'REST endpoint or AJAX actions', 'de' => 'REST-Schnittstelle oder AJAX-Aktionen'],
        'external_api' => ['eur' => 150, 'en' => 'Connection to an external service, per service', 'de' => 'Anbindung an einen externen Dienst, pro Dienst'],
        'email' => ['eur' => 60, 'en' => 'E-mail notifications with editable texts', 'de' => 'E-Mail-Benachrichtigungen mit anpassbaren Texten'],
        'schedule' => ['eur' => 50, 'en' => 'Scheduled task (cron)', 'de' => 'Geplante Aufgabe (Cron)'],
        'roles' => ['eur' => 60, 'en' => 'Roles and permissions', 'de' => 'Rollen und Berechtigungen'],
        'payments' => ['eur' => 200, 'en' => 'Payments through a payment provider', 'de' => 'Zahlungen über einen Zahlungsanbieter'],
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
        ['id' => 'caching', 'name' => 'WP Rocket', 'category' => 'Speed and caching', 'price' => '$59', 'slug' => null,
            'features' => ['page cache', 'minify CSS and JS', 'lazy load images', 'database clean-up', 'cache preload'],
            'modules' => ['base', 'schedule', 'admin_list']],
        ['id' => 'forms', 'name' => 'Gravity Forms', 'category' => 'Forms', 'price' => '$59', 'slug' => null,
            'features' => ['form builder', 'conditional fields', 'entries in the admin', 'e-mail notifications', 'file uploads'],
            'modules' => ['base', 'form', 'data', 'admin_list', 'email']],
        ['id' => 'wpforms', 'name' => 'WPForms Pro', 'category' => 'Forms', 'price' => '$199', 'slug' => 'wpforms-lite',
            'features' => ['drag and drop forms', 'surveys', 'payments in forms', 'entries', 'notifications'],
            'modules' => ['base', 'form', 'data', 'admin_list', 'email']],
        ['id' => 'acf', 'name' => 'Advanced Custom Fields PRO', 'category' => 'Content fields', 'price' => '$49', 'slug' => 'advanced-custom-fields',
            'features' => ['repeater fields', 'flexible content', 'options pages', 'gallery field'],
            'modules' => ['base', 'data', 'block']],
        ['id' => 'seo', 'name' => 'Yoast SEO Premium', 'category' => 'SEO', 'price' => '$99', 'slug' => 'wordpress-seo',
            'features' => ['redirect manager', 'internal link suggestions', 'several focus keywords', 'social previews'],
            'modules' => ['base', 'data', 'admin_list']],
        ['id' => 'subscriptions', 'name' => 'WooCommerce Subscriptions', 'category' => 'Shop', 'price' => '$279', 'slug' => null,
            'features' => ['recurring payments', 'customer subscription page', 'automatic renewals', 'renewal e-mails'],
            'modules' => ['base', 'woo', 'data', 'schedule', 'email', 'payments']],
        ['id' => 'bookings', 'name' => 'WooCommerce Bookings', 'category' => 'Shop', 'price' => '$249', 'slug' => null,
            'features' => ['bookable products', 'availability rules', 'booking calendar', 'confirmation e-mails'],
            'modules' => ['base', 'woo', 'data', 'admin_list', 'email']],
        ['id' => 'addons', 'name' => 'WooCommerce Product Add-Ons', 'category' => 'Shop', 'price' => '$59', 'slug' => null,
            'features' => ['extra product options', 'price per option', 'text and file fields on products'],
            'modules' => ['base', 'woo', 'form']],
        ['id' => 'wishlist', 'name' => 'YITH WooCommerce Wishlist Premium', 'category' => 'Shop', 'price' => '€95', 'slug' => 'yith-woocommerce-wishlist',
            'features' => ['several wishlists', 'share by e-mail', 'price drop notices', 'wishlist report'],
            'modules' => ['base', 'woo', 'data', 'email', 'admin_list']],
        ['id' => 'invoices', 'name' => 'PDF Invoices & Packing Slips Pro', 'category' => 'Shop', 'price' => '€69', 'slug' => 'woocommerce-pdf-invoices-packing-slips',
            'features' => ['PDF invoices', 'packing slips', 'credit notes', 'invoices attached to e-mails'],
            'modules' => ['base', 'woo', 'email']],
        ['id' => 'membership', 'name' => 'MemberPress', 'category' => 'Memberships', 'price' => '$179', 'slug' => null,
            'features' => ['paid memberships', 'content rules', 'member account page', 'payment gateways'],
            'modules' => ['base', 'roles', 'data', 'payments', 'email']],
        ['id' => 'courses', 'name' => 'LearnDash', 'category' => 'Courses', 'price' => '$199', 'slug' => null,
            'features' => ['courses and lessons', 'quizzes', 'progress tracking', 'certificates'],
            'modules' => ['base', 'data', 'block', 'roles', 'admin_list']],
        ['id' => 'instagram', 'name' => 'Smash Balloon Instagram Feed Pro', 'category' => 'Social', 'price' => '$49', 'slug' => 'instagram-feed',
            'features' => ['Instagram feed on the site', 'layouts', 'hashtag feeds'],
            'modules' => ['base', 'external_api', 'block', 'schedule']],
        ['id' => 'analytics', 'name' => 'MonsterInsights Pro', 'category' => 'Analytics', 'price' => '$199', 'slug' => 'google-analytics-for-wordpress',
            'features' => ['Google Analytics in the dashboard', 'e-commerce reports', 'popular posts'],
            'modules' => ['base', 'external_api', 'admin_list']],
        ['id' => 'smtp', 'name' => 'WP Mail SMTP Pro', 'category' => 'E-mail', 'price' => '$49', 'slug' => 'wp-mail-smtp',
            'features' => ['send through an e-mail provider', 'e-mail log', 'resend failed e-mails'],
            'modules' => ['base', 'external_api', 'admin_list']],
        ['id' => 'backup', 'name' => 'UpdraftPlus Premium', 'category' => 'Backups', 'price' => '$70', 'slug' => 'updraftplus',
            'features' => ['scheduled backups', 'cloud storage', 'restore'],
            'modules' => ['base', 'schedule', 'external_api', 'import_export']],
        ['id' => 'search', 'name' => 'SearchWP', 'category' => 'Search', 'price' => '$99', 'slug' => null,
            'features' => ['better site search', 'search in custom fields and PDFs', 'search statistics'],
            'modules' => ['base', 'data', 'admin_list']],
        ['id' => 'filters', 'name' => 'FacetWP', 'category' => 'Search', 'price' => '$99', 'slug' => null,
            'features' => ['filters for lists and shops', 'AJAX results', 'filter by fields and categories'],
            'modules' => ['base', 'block', 'rest']],
        ['id' => 'import', 'name' => 'WP All Import Pro', 'category' => 'Data', 'price' => '$99', 'slug' => 'wp-all-import',
            'features' => ['import CSV and XML', 'field mapping', 'scheduled imports'],
            'modules' => ['base', 'import_export', 'schedule']],
        ['id' => 'perf', 'name' => 'Perfmatters', 'category' => 'Speed and caching', 'price' => '$25', 'slug' => null,
            'features' => ['turn off unused features', 'script manager per page', 'lazy load'],
            'modules' => ['base', 'admin_list']],
    ],
];
