# 12 — Information Architecture

**Project:** Appwerk (working name) — codemenschen.at, Vienna
**Scope:** Full sitemap, DE/EN URL structure, navigation model.
**Principles:** Self-serve (every question answerable without a call), shallow
hierarchy (nothing more than 2 clicks from home), legal pages first-class
(trust product = findable terms).

---

## 1. Sitemap

```
/
├── Home                              (landing, doc 11)
├── Apps (listings index)             — the conversion destination
│   └── Listing detail (per app)
├── How it works                      (long-form process page)
├── About                             (Codemenschen, Vienna, team)
├── FAQ
├── Legal
│   ├── Impressum                     (Austrian ECG/MedienG requirement)
│   ├── Terms (License & Marketplace Terms)   [COUNSEL REVIEW]
│   ├── Privacy (DSGVO/GDPR)          [COUNSEL REVIEW]
│   └── Withdrawal right (EU consumer)        [COUNSEL REVIEW]
└── (Auth/Account — post-MVP: dashboard, licensed-app tracking; out of scope
    for this IA doc except reserved URL space /account)
```

### Page inventory & purpose

| Page | Purpose | Primary next step |
|---|---|---|
| Home | Arc from "possible?" to "browse" | → Apps |
| Apps index | Filter/scan validated apps; data-forward cards (Acquire.com-principle: numbers, not adjectives) | → Listing detail |
| Listing detail | Full validation data, price, retainer %, status, license summary, risk box, purchase flow entry | → License this app (self-serve checkout) |
| How it works | Deep version of homepage Section 2: validation methodology, operations, dashboard, suspension/resale mechanics with diagram | → Apps |
| About | Codemenschen anchor: Vienna, team, track record (facts only) | → Apps / FAQ |
| FAQ | Full objection sweep, grouped: The model / Money / Risk & exit / Legal | → Apps |
| Impressum | Statutory disclosure (name, address, FN, UID, contact) | — |
| Terms | License grant scope, retainer, suspension, relisting, resale, surplus-net-of-arrears | — |
| Privacy | GDPR notice, cookie policy | — |
| Withdrawal | EU withdrawal-right instruction + KMG note placeholder | — |

### Listing detail — content model (drives IA)

Each listing page contains, in order:
1. Title, icon, category, status (`Available — one buyer per app` / `Licensed` / `Relisted`)
2. Validation actuals block (test length, paying users, test revenue, retention — PLACEHOLDER until real)
3. What the app does (plain language, ≤150 words)
4. Price: license fee, retainer %, optional ad budget explainer
5. What the license includes / excludes (excludes: ownership, IP, equity) [COUNSEL REVIEW]
6. Risk box (same copy family as homepage Section 5)
7. Roadmap preview (what we'd build next — proposals, not promises)
8. CTA: `License this app` → self-serve flow; secondary `Ask a question` (async form, not a call)

---

## 2. URL structure — DE/EN

**Model:** Path-prefix localization, German default for `.at` audience, English
fully translated slugs (translated slugs > copied slugs for trust and SEO).

```
DE (default, x-default)          EN
/                                /en/
/apps/                           /en/apps/
/apps/{slug}/                    /en/apps/{slug}/
/so-funktionierts/               /en/how-it-works/
/ueber-uns/                      /en/about/
/faq/                            /en/faq/
/impressum/                      /en/imprint/          (Impressum applies site-wide)
/agb/                            /en/terms/
/datenschutz/                    /en/privacy/
/widerruf/                       /en/withdrawal/
```

**Rules:**
- App slugs are language-neutral (`/apps/fitpilot/`, `/en/apps/fitpilot/`) —
  one canonical slug per app, only the prefix changes.
- `hreflang` pairs on every page: `de-AT`, `en`, `x-default → de`.
- **Auto-detection:** first visit → `Accept-Language` decides DE vs EN, served
  via redirect (302) to the matching prefix; manual switch sets a persistent
  preference (cookie/localStorage) that always overrides detection. Never
  auto-redirect a user who explicitly chose a language.
- Language switcher swaps to the *same page* in the other language, not to the
  homepage.
- Trailing slashes canonical; lowercase; hyphens; no query-string content pages.

---

## 3. Navigation model

### Header (persistent, all pages)

```
[Appwerk]        Apps    So funktioniert's    Über uns    FAQ        [DE|EN]  [Apps ansehen →]
 (wordmark)      ————————— text links ——————————          switch      primary button (accent)
```

- ≤4 nav items. The primary CTA (`Browse apps` / `Apps ansehen`) is present in
  the header on every page *except* the Apps index itself (where it would be
  redundant — there it becomes inert/hidden).
- Header is sticky, paper background, 1px warm-gray #E6E3DC bottom border on
  scroll. No mega-menus, no dropdowns — the site is too shallow to need them.
- Mobile: wordmark + CTA + hamburger → full-screen sheet listing the 4 items +
  language switch + legal links (see doc 14).

### Footer (persistent)

Three columns + legal bar:

```
Column 1: Marketplace          Column 2: Company            Column 3: Legal
  Apps                           Über uns / About             Impressum
  So funktioniert's              codemenschen.at ↗            AGB / Terms
  FAQ                            Kontakt (async form)         Datenschutz / Privacy
                                                              Widerruf / Withdrawal

Legal bar: © Codemenschen, Wien · risk footnote (one line, doc 11 §9) · [DE|EN]
```

### Breadcrumbs

Only on listing detail: `Apps / {App name}`. Nowhere else (hierarchy too flat
to justify them).

### Cross-linking rules (arc-preserving)

- Every content page (How it works, About, FAQ) ends with the primary CTA block
  → Apps.
- Risk/legal pages end with NO sales CTA — a plain "Back to Apps" text link at
  most. Selling at the end of a risk disclosure is off-brand.
- Homepage sections deep-link: `#how-it-works`, `#risiken`, `#preise` etc. for
  the hero's scroll link and FAQ cross-references.

---

## 4. Out-of-scope reservations

- `/account/` — post-purchase dashboard (users, revenue, costs, roadmap voting).
  Reserved, noindex until built.
- `/apps/{slug}/resale/` — resale flow entry, reserved. [COUNSEL REVIEW on
  flow requirements before design]
