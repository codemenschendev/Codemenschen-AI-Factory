# Sofabuilt: plan (2026-10-03)

Second brand on the Appwerk system. Host: `sofabuilt.com`, until it is bought `sofabuilt.codemenschen.at`
(the `*.codemenschen.at` wildcard already points at manager).

## The promise

"Your idea. We build it. You relax." The customer describes an idea, we research, scope and price it,
the customer pays, we build, test, list and promote it. The customer owns the result: use it or sell it.

Two doors on the first page:

1. **I have my own idea**: the chat sharpens it into a scope.
2. **Make my own version of a premium plugin**: the chat shows paid plugins that sell well; the customer
   picks one, we build their own version with the features people actually use, paid once.

Rules for every text and every agent reply:

- No income promises ("passive income", "earn X a month"). Selling is an option, shown with an honest
  payback calculator as on Appmitki. EU unfair-practice law and Meta ad policy both forbid earnings claims.
- A version of a premium plugin is new code with its own name. Never the original's name, logo or texts.
  The scope says plainly which features are in and which are not.
- No brand of an AI provider in customer text ("Sofabuilt AI").

## Phase 1 scope: WordPress only

Why WordPress first: a free public API for research (`api.wordpress.org/plugins/info/1.2`: installs,
rating, last update, requirements), delivery is a zip, a live demo needs no server (WordPress
Playground), and the team knows WordPress (wp-giftcard, Patrick's plugins). Shopify (hosted app, OAuth,
Billing API, weeks of review) is phase 3.

## Flow

1. **Landing** (`/`): headline, the two doors, how it works, prices from, payback calculator, FAQ.
2. **Desk** (`/desk`): chat on the left, the price sidebar on the right (Patrick's Build desk layout).
   - Door 2 opens with a short list from our premium catalog (see Research).
   - The agent asks at most a few questions per turn and keeps a **scope card**: name, one-line
     purpose, modules (see Pricing), requirements (WP/WooCommerce/PHP versions), what is not included.
   - The sidebar shows the price live from the scope card. Lanes: 1 Plugin build (required),
     2 Launch (optional: sales page, store listing, ads), 3 Care (monthly maintenance, optional).
3. **Checkout**: the existing quote → Stripe flow; the scope card is frozen into the quote.
4. **Build**: the existing pipeline with a new stack `wp-plugin` (product → coding → test/fix → release).
5. **Review**: the customer tries the plugin live in WordPress Playground (a blueprint that installs
   the built zip), asks for changes in the existing change chat (free rounds as for apps).
6. **Delivery**: zip + repository, readme.txt, changelog. Launch lane jobs run after approval.

## Research (the agent's facts)

- **Premium catalog** (our data, `config/sofabuilt-catalog.php`): about 50 paid plugins that sell well,
  with category, price per year, the core features people use, and the free wordpress.org slug when there
  is one. Kept by hand; no scraping of marketplaces.
- **Live numbers** from `api.wordpress.org` for any slug the agent names (active installs, rating, last
  update), fetched by the API and cached a day, handed to the model as data. The model never browses.
- The chat model stays the tool-less `openclaw/appwerk`; the API does the fetching. No new provider.

## Pricing

The model does not invent prices. It picks **modules** for the scope card; the price is computed:

| Module | Example |
|---|---|
| base plugin | settings page, i18n, uninstall clean-up, readme |
| custom post type / data table | bookings, wishlists |
| block or shortcode | front-end output |
| WooCommerce integration | cart, checkout, order hooks |
| admin list / report | tables, CSV export |
| REST endpoint / AJAX | |
| external API integration | per service |
| e-mail notifications | |
| cron / scheduled job | |
| roles and permissions | |

Range like Appmitki (about €290 to €1,500), exact module prices set in `Estimator` and shown in the
sidebar line by line. Launch lane: sales page 299 (the app landing page machinery), listing on
wordpress.org (free version) or a seller platform setup, ads (the existing App-Marketing package).
Care: monthly, covers WordPress/WooCommerce/PHP updates and security fixes. Store fees are passed on at
cost. Ad budget runs on the customer's own ad account.

## Build pipeline changes

- Worker: stack `wp-plugin`, template `templates/wp-plugin-app` (plugin header, namespaced classes,
  settings API, i18n, uninstall.php, readme.txt, PHPUnit-free test convention via `test/run.mjs` like
  the other templates).
- Test stage: `php -l`, WordPress Coding Standards (PHPCS) and **Plugin Check** (the tool wordpress.org
  reviews with) inside a WordPress container; acceptance criteria as before.
- Release: a versioned zip as the build artifact; Playground blueprint URL for the review screen.
- Store listing assets: readme.txt sections, banner and icon (image service), screenshots from
  Playground.

## Multi-brand foundation (needed before anything else)

Today brand, logo, metadata, `frontend_url` and mail links are Appmitki only.

- Web: `lib/brand.ts` resolves the brand from the host (`appmitki`, `sofabuilt`); `proxy.ts` rewrites
  the sofabuilt host into its own route group; layout, metadata, logo, footer, robots and sitemap per brand.
- API: `brand` column on quotes, orders, customers and prototypes; Stripe success/cancel URLs, sign-in
  links and mail sender name per brand; CSP frame hosts per brand.
- Infra: Apache vhost `sofabuilt.codemenschen.at` (later `sofabuilt.com`) to the same Next and API,
  certificate, Turnstile widget hostnames (owner adds them in Cloudflare).
- Legal: Impressum, privacy and terms for Sofabuilt (Codemenschen GmbH), terms cover ownership, GPL
  licence of WordPress plugins, change rounds, Care.

## Phases

| Phase | Content | Done when |
|---|---|---|
| 0 | Multi-brand foundation, vhost, legal pages, landing page | sofabuilt.codemenschen.at shows its own page, Appmitki unchanged |
| 1 | Desk chat with research and live price, quote/checkout, wp-plugin pipeline, Playground review, delivery | one real plugin built end to end from a chat |
| 2 | Launch lane (sales page, listing, ads), Care subscription, Meta campaign for Sofabuilt | first paid order |
| 3 | Shopify apps (owner 2026-10-05: "focus only Shopify, WordPress"; Chrome extensions stay built, hidden from the desk) | one real Shopify app built end to end |

## Shopify apps (2026-10-05)

- Stack `shopify`, template `templates/shopify-app`: Shopify's React Router app template (MIT, see
  TEMPLATE-LICENSE.md) with the privacy webhooks wired, settings in an app metafield, and a test run
  that checks `shopify.app.toml`, GraphQL-only Admin API, theme app extension blocks, typecheck and
  build, then one case per criterion against an Admin API stand-in (`test/shopify.mjs`).
- Parts, launch options and a catalogue of 12 paid Shopify apps in `config/sofabuilt.php` under
  `shopify`; catalogue prices are the cheapest paid plan on apps.shopify.com (checked 2026-10-05),
  shown per year. No live research: the App Store has no public search API.
- Delivery: the whole app as a ZIP (code, config, Dockerfile). The merchant tries it on a Shopify
  development store with `shopify app dev`, hosts it on a server of their choice and registers it
  with `shopify app deploy`. We set it up for them on request.
- `sofabuilt.offered` decides what the desk shows (`wordpress`, `shopify`).

## Open decisions (owner)

1. Module prices and the Care price per month.
2. Free part before payment: chat + scope + price only (proposed), or also a clickable admin-screen mockup.
3. Delivery time promise for a plugin (proposed: 2 to 3 working days, after the scope is agreed).
