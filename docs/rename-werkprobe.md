# Rename: Appwerk -> Werkprobe

## Progress

- 2026-09-29: brand texts, logo ("W" mark), favicon, mails, 2FA issuer, ad names, templates renamed and
  deployed (PR #160); codemenschen.at promo texts renamed (9227cef). Hosts still appwerk.codemenschen.at,
  domain not bought yet. Frame CSP already allows werkprobe.at.
- Known name clash, accepted by the owner 2026-09-29: codemenschen.at also lists the free plugin
  "Codemenschen Werk Probe" (card 27009 in the wp-giftcard.com catalogue, zip on werk.codemenschen.at).
  Plan to rename the plugin later (for example "Codemenschen Werk Connector").

Decided 2026-09-29. Reason: "appwerke" is a registered mark of codewerke GmbH (EU 019095051,
DE 3020240048665, classes 9/35/38/42) and appwerk GmbH Hamburg owns appwerk.de/.com.
"Werkprobe": no TMview hit (2026-09-29), werkprobe.at and werkprobe.com free, werkprobe.de taken
(private test server).

Brand: **Werkprobe**, small line "von Codemenschen". Main domain `werkprobe.at`, `werkprobe.com`
held and redirected. Hosts after the switch: `werkprobe.at` (site), `api.werkprobe.at`,
`admin.werkprobe.at`.

Rules for the whole rename:

- Rename what a customer, a search engine or an ad platform sees. Internal names stay (see
  "Stays as it is"), renaming them only risks outages.
- The old hosts keep answering. Share links `/p/{id}`, live landing pages `/l/{id}`, magic links in
  sent mails and bought sites all point at `appwerk.codemenschen.at` / `api.appwerk.codemenschen.at`.
  Old site host: 301 to the new host, same path. Old API host: keeps serving, no redirect (iframes,
  landing pages in running ads). Keep both for at least 12 months.
- No customer-facing "—" in the new copy.

Tick a box only after checking it live, not after the commit.

## Phase 0: decisions and purchases (owner / boss)

- [ ] Boss approves the name "Werkprobe"
- [ ] Buy `werkprobe.at` and `werkprobe.com` (owner pays, registrar account of Codemenschen GmbH)
- [ ] Trademark: word + figure mark "Werkprobe" at ÖPA or EUIPO, classes 9, 35, 42 (boss decides, ~280 to 850 EUR)
- [ ] Firmenbuch / Handelsregister check for "Werkprobe" (companies, not only marks)
- [ ] New logo approved (wordmark + icon), light and dark

## Phase 1: infrastructure (new domain works beside the old one)

- [ ] DNS: A/AAAA for `werkprobe.at`, `www`, `api`, `admin` -> 65.108.206.249; `werkprobe.com` + `www` redirect
- [ ] Apache vhosts next to `infra/apache/*.appwerk.codemenschen.at.conf`, TLS via certbot for all names
- [ ] Admin htpasswd path reused (`/etc/apache2/.htpasswd-appwerk-admin`, name stays)
- [ ] Server `.env`: `APP_URL`, frontend URL, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, CORS origins, `MAIL_FROM_NAME=Werkprobe`
- [x] `PrototypeController::raw` CSP `frame-ancestors`: add `https://werkprobe.at` (else every prototype frame is blank on the new host)
- [ ] Other hard-coded hosts: `git grep -n "appwerk.codemenschen.at"` in api, web, workers, infra, templates
- [ ] `LandingController::url()` and every mail link build the new host
- [ ] Stripe: success/cancel URLs, webhook endpoint (add new, keep old until switch), public business name and statement descriptor "WERKPROBE"
- [ ] Deploy registry: new row or updated `werk-appwerkcodemenschenat`, dispatcher site config if hosts are listed there
- [ ] Mail: keep sending as `developerweb@codemenschen.at` (DKIM ok). Only if a `@werkprobe.at` sender is wanted: mailbox, SPF, DKIM, DMARC first

## Phase 2: brand in the code (one PR per app)

- [x] `apps/web/src/dictionaries/de.ts` + `en.ts`: every "Appwerk" (name, meta titles, FAQ, legal pages)
- [ ] `apps/web/src/components/Logo.tsx`, favicon, `icon.svg`, `apple-icon.png`, OG images, `manifest`
- [ ] `apps/web` layouts: `<title>`, metadata, JSON-LD, canonical and hreflang on the new host, sitemap, robots
- [x] Admin UI texts (`AdminPanel`, `AdminSignIn`, `TwoFactorGate`, panels)
- [x] API mail subjects and bodies (`AuthController`, `PrototypeController`, `CustomerMail`, all mail views)
- [x] `Totp` issuer "Werkprobe" (existing authenticator entries keep the old label, harmless)
- [x] `LandingController` default title, consent text "e-mails from ..."
- [x] Prompts in `apps/api/resources/prompts/` that name the product to the customer (`change/assistant.md` etc.)
- [x] `MetaAdsPublisher` campaign name prefix "Appwerk #" -> "Werkprobe #"
- [x] User agents `AppwerkBot/1.0`, `AppwerkAdBot/1.0` -> `WerkprobeBot/1.0` (sites we read see this)
- [ ] Legal pages: imprint, privacy (product name, domains, processors), terms, withdrawal, date
- [ ] Old static pages `appwerk/site/*`: delete if nothing serves them, else rename
- [ ] Gate before merge: `git grep -n -i appwerk -- apps/web/src apps/api/app/Http apps/api/resources` shows only internal names
- [x] Tests updated, CI green, deploy, check in the container

## Phase 3: switch day

- [ ] Old site host `appwerk.codemenschen.at` -> 301 to `werkprobe.at`, same path and query
- [ ] Old API host keeps serving, frames and `/l/{id}` still load (open one old share link and one live landing page)
- [ ] Magic link, checkout, Stripe webhook, prototype build, change request: one real run each on the new host
- [ ] Usersnap: project renamed `werkprobe`, Target rule `Contains werkprobe.at` (keep the old row)

## Phase 4: platforms outside

- [ ] Meta Page "Appwerk" -> "Werkprobe" (name change request), username, logo, cover, Impressum text, website link
- [ ] Meta Business: verify domain `werkprobe.at`, pixel `408896953700952` allowed on it
- [ ] Meta campaign #4 (paused): creatives cannot be edited. Delete the old ad + creative, publish a new one with the new text, picture and landing URL, still PAUSED. Start only on the owner's "start"
- [ ] GA4 G-2G06XK6HEM: stream URL, property name; GTM GTM-N3QGZSVM: domain triggers, consent. Owner publishes GTM
- [ ] Google Search Console: add `werkprobe.at`, submit sitemap, "Change of address" from the old host
- [ ] Google Ads (not live yet): account and campaign names, final URLs on the new domain
- [ ] Google Cloud OAuth consent screen app name if it says Appwerk
- [ ] Stripe dashboard branding (logo, colour, name on receipts)
- [ ] Any listings or directories that link the old name

## Phase 5: codemenschen.at (the site that sells the plugins)

Repo `/Volumes/CodemenschenSSD/Workspace/codemenschen.at`, deploy target `codemenschen-at`.

- [ ] `client/src/components/AppwerkPromo.tsx` -> `WerkprobePromo.tsx`, origin `https://werkprobe.at`, GA `promotion_id` / `promotion_name`
- [x] `client/src/contexts/LanguageContext.tsx`: the 34 `appwerk.*` / `header.appwerk` keys (DE + EN text)
- [ ] `Header.tsx` (desktop + mobile), `Footer.tsx`, `WhatWeDo.tsx`, `Contact.tsx`
- [x] Images `client/public/images/appwerk/*`: checked 2026-09-29, no name or logo in the pictures (folder name stays until the component is renamed)
- [ ] Plugin pages (`PluginDetail.tsx`) and plugin texts in the site database: search for "Appwerk" (owner runs the DB search, the classifier blocks production reads for me)
- [ ] Later: rename the plugin "Codemenschen Werk Probe" (catalogue card, static fallback in `client/src/data/plugins.ts`, zip name) so it is not mistaken for the product
- [ ] Optional: a Werkprobe box on the plugin pages ("Website oder App zum Plugin? Gratis Vorschau")
- [ ] SEO: sitemap, internal links, structured data mention the new name

## Phase 6: internal records

- [ ] Commit footer from switch day: `Project: Werkprobe` (Teams report footer follows)
- [ ] `CLAUDE.md` (repo) and memory files: new name, domains, the old hosts that must stay
- [ ] `~/.openclaw/workspace/ops/projects.json`, `CONTEXT.md`, `gen-projects.sh`, `design-ref.sh`, `video.sh`
- [ ] Reports folder `Documents/Codemenschen Reports/Werkprobe/`
- [ ] `Codemenschen_Werk` dashboard texts (`site_analytics.dart`, `conversation_view.dart`, `AgentRunTracker.php`)
- [ ] OpenClaw `buzz/agents/prompts/appwerk-product.md`: product name the agents use in answers
- [ ] Team informed (Teams post, English)

## Stays as it is (internal)

Repo and folder names, the `openclaw/appwerk` and `appwerk-code` agents, Docker services and
volumes, `/var/appwerk-media/*`, the htpasswd file, Buzz channels `#appwerk-alerts` /
`#appwerk-agents`, Expo bundle ids `at.codemenschen.appwerk.*`, localStorage and cookie keys
(`appwerk.consent` etc.; renaming them would drop every visitor's consent), the Meta system user
"Appwerk API", the Google service account `appwerk-ads@cm-ops-507408`, the backup bucket names.
