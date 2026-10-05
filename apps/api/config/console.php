<?php

/*
 * The customer console (2026-10-05, console.appmitki.com): one page per customer for all their
 * projects, where they ask for changes themselves. What a change costs:
 *   - Care includes a number of changes a month (Sofabuilt; Appmitki's Care stays unlimited).
 *   - Changes beyond that are paid from change credits, bought in packs.
 *   - Without credits a single change round is paid on its own (Estimator::REVISION_PRICE_EUR).
 */
return [

    // Where the console lives. Empty: the customer's pages stay on the storefront (/account).
    'url' => env('CONSOLE_URL', ''),

    // Brands whose Care has a monthly allowance; the others' Care covers every change round.
    'care_quota_brands' => ['sofabuilt'],
    'care_edits_per_month' => 3,

    // Packs of change credits: number of changes => price in EUR.
    'packs' => [
        5 => 45,
        15 => 119,
    ],
];
