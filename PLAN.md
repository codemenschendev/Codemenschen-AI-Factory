# AI Factory — Analysis & Implementation Plan

**Date:** 2026-08-20 · **Status:** Proposal for approval · **Author:** Claude (architecture analysis session)

Vision: *"Choose an app. Pay. We build, publish and market it."* — software creation that feels like buying a product in an online store.

---

## 1. What already exists (inventory)

### 1.1 `appwerk/site` — static marketplace prototype (60–70 % reusable)

Plain HTML + vanilla JS, no backend. Verified state:

**Reusable as-is:**
- Complete design system (`styles.css` tokens: ink `#0B0E14`, paper `#FAF9F6`, accent `#2B5BE3`, success `#1A7F5A`), self-hosted Fraunces + Inter fonts (DSGVO-safe)
- `catalog.js` — a clean, i18n-shaped data model (6 apps, `{en,de}` string pairs) that maps 1:1 to a database schema
- All DE/EN copy incl. the legal-page prose (highest-effort text in the repo)
- Pure pricing/scenario math: `estimate()` in `create.html:189-204` (the price calculator), 12-month scenario model, break-even chart
- The checkout's legal choreography: never-pre-ticked § 18 FAGG waiver checkbox, terms gating, order-summary layout
- Phone-mockup + SVG artwork system (`art-*.js`, `screens.js`)

**Stubs / not real:**
- All Stripe links are test-mode Payment Links; `fund`/`share` modes are hard-disabled (`checkout.html:397`); custom checkout is a `mailto:`
- No auth, no persistence, no webhooks, no order records; `account.html` and `success.html` are unverified static pages
- All legal pages are DRAFT with ~40 `[COUNSEL REVIEW]` markers

**Known bugs (fix during port):**
- `app.html?id=rechni` crashes — `catOppSection()` reads `app.market[0][1]` but rechni has no `market` key → funding page renders empty
- "Smallest entry ticket €300" contradicted by code (`Math.max(100, …)` gives €100 for Countbee)

### 1.2 `appwerk/docs` — 30 strategy docs + 3 legal drafts

- Business model: exclusive licenses €300–5,000 + 5–10 % monthly retainer (this is the **Appwerk** model — see §2 on how AI Factory differs)
- Doc 27: Stripe sandbox already set up (4 price IDs created 2026-07-07); live keys, branding, invoicing settings still open
- Doc 05: pricing-presentation rules (total-cost honesty, banned dark patterns) — directly applicable to AI Factory checkout
- Doc 30: review-mining idea engine with 5 validated candidate ideas (winner: **TrainerPing**, €1,600 build cost) — the seed for MVP 6
- Legal: KMG/AltFG classification of the *license* model is unresolved and blocking for that model; the § 18 FAGG withdrawal construction (waiver on commencement of performance, NOT the custom-goods exemption) applies to AI Factory too

### 1.3 `Codemenschen_OpenClaw` — existing orchestration infrastructure

Laravel `management-api` + `openclaw-worker` + WP/Joomla plugins. The documented **code-agent execution architecture** is exactly the right security pattern for the factory:

> OpenClaw only reasons and calls MCP tools. The Management API owns tenancy, permissions, jobs, confirmation and audit log. Only the Code Worker (short-lived Docker container, one site/one change) creates patches, runs allowlisted commands and deploys. No shell/SSH/Docker socket for the LLM. GitHub access via GitHub App OAuth with encrypted per-client tokens, never PATs in prompts.

Reusable: job/audit tables and patterns, ToolDispatcher permission model, GitHub App integration, Docker worker pattern, deploy scripts.

### 1.4 OpenClaw (the open-source project) — evaluated as orchestrator

- Gateway daemon with hooks API (`POST /hooks/agent`, result delivery via webhook callback), multi-agent workspaces, per-agent sandboxing, MIT license
- **Not suitable as the pipeline engine**: chat-centric, single-operator trust model (explicitly not multi-tenant), in-flight runs not documented to survive restarts, significant hardening burden (30k+ exposed instances found by researchers)
- **Well suited as the ops cockpit**: kickoff triggers, status notifications to Telegram/WhatsApp, human-approval commands

### 1.5 `review-intelligence` (was on the Windows machine)

TS/SQLite/Apify pipeline mining store reviews for app ideas. Needs porting to this machine, a valid `ANTHROPIC_API_KEY`, and a paid Apify plan. Becomes MVP 6.

---

## 2. Key strategic decision: two products, one platform

The appwerk license model (customer licenses *our* app, we keep IP, revenue share) is legally heavy — unresolved KMG/AltFG questions block launch.

The **AI Factory model is legally much simpler**: the customer commissions development of *their* app and owns the result. That is an ordinary **Werkvertrag** (contract for work), not a Veranlagung — no prospectus questions, no crowdinvesting rules. FAGG withdrawal still applies, and the already-designed § 18 waiver flow covers it.

**Recommendation:** launch AI Factory as the primary product ("we build your app — you own it"), and reuse the appwerk catalog as **ready-made app ideas you can have built for yourself** (fixed price, fixed scope, faster delivery). The license/share/fund modes stay parked until counsel clears them. This preserves ~all appwerk assets while removing the legal blocker.

Store-policy reality (confirms the brief's caution): Apple rejects templated/generated apps published in bulk under one account (Guideline 4.3 spam; template apps must ship under the *client's* developer account). Google Play has equivalent repetitive-content rules. **Therefore: each customer app is published under the customer's own developer account** (Apple $99/yr, Google $25 once) — we automate setup assistance and act via App Store Connect / Play Console API keys the customer grants. This is the "ownership/account transfer assistance" package.

---

## 3. Target architecture

```
Customer Browser
   │
Next.js storefront (marketplace, wizard, checkout, customer portal)  [apps/web]
   │  REST/JSON
Factory API — Laravel (extends management-api patterns)              [apps/api]
   ├─ PostgreSQL (system of record)
   ├─ Redis + Laravel Horizon (queues, retries, observability)
   ├─ Pipeline Orchestrator = deterministic state machine (NOT an LLM)
   │     dispatches stage jobs, enforces gates, writes audit events
   ├─ Stripe (Checkout Sessions + webhooks + invoicing)
   │
   ├─→ Build Workers [workers/pipeline] — Node + Claude Agent SDK
   │      one Docker sandbox per stage run; agents: Product, UI/UX,
   │      Coding, Test, Fix, Release; repo-per-project via GitHub App
   ├─→ Build infra: GitHub Actions CI + Expo EAS (mobile builds/submit)
   ├─→ Store integrations: App Store Connect API, Play Developer API
   ├─→ Marketing integrations: Google Ads API, Meta Marketing API
   └─→ OpenClaw Gateway (ops cockpit): Telegram notifications,
          approval commands via MCP → Factory API (loopback-only,
          hook-token auth, no direct execution rights)
```

Principles: every long-running operation is a queued, resumable, idempotent job with stage-level checkpoints in the DB; agents never see production secrets (scoped short-lived tokens only); all state transitions land in `project_events` (audit).

**Standardized customer-app stack (recommendation):**
- Mobile: **Expo (React Native + TypeScript)** — EAS Build produces installable artifacts in the cloud, EAS Submit pushes to both stores; best automation story per euro
- Web apps: Next.js template, deployed on our own server (Docker)
- **Two customer-facing app types** (decided by the Product Agent from the feature list, shown in the quote before checkout):
  - **Type A — Local app (default):** no backend, SQLite on device; **no monthly fee**. Fits many catalog apps (Countbee, BargeldBuch …)
  - **Type B — Connected app (needs accounts/sync/DB):** backend hosted on **our own server** → **mandatory monthly hosting & maintenance subscription** (Stripe subscription, priced by tier). Internally: PocketBase per app (one Go binary — SQLite + auth + storage + realtime — in its own Docker container; dozens of instances per host, ~€0 marginal cost → high-margin subscription) or shared Postgres for apps needing relational scale. Managed BaaS (e.g. Supabase) only if the customer explicitly wants it, under the customer's own account and paid by the customer.
- One golden template repo per stack/type, versioned; the Coding Agent works inside a fork of the template

---

## 4. Database schema (core)

```
customers          id, email, name, locale, stripe_customer_id, created_at
listings           id, slug, kind(catalog|custom), status(draft|live|taken|parked),
                   name, i18n JSONB (cardDesc, lede, why, market …), price_eur,
                   build_weeks_lo/hi, source(manual|idea_engine), created_at
quotes             id, customer_id?, idea_text, structured_spec JSONB,
                   features JSONB, complexity, integrations JSONB,
                   price_eur, breakdown JSONB, status(draft|presented|expired|converted),
                   valid_until
orders             id, customer_id, quote_id?, listing_id?,
                   packages JSONB (dev, publishing, transfer_assist, marketing),
                   ad_budget_eur, total_eur, currency,
                   stripe_checkout_session_id, status(pending|paid|refunded|failed),
                   fagg_waiver_at, fagg_waiver_ip, locale
payments           id, order_id, stripe_payment_intent, amount_eur, status,
                   invoice_id, raw_event JSONB
projects           id, order_id, customer_id, name,
                   status ENUM(IDEA,QUOTED,PAID,SPECIFICATION,BUILDING,TESTING,
                     FIXING,REVIEW,READY,PUBLISHING,PUBLISHED,MARKETING,
                     COMPLETED,FAILED),
                   stack(expo|nextjs), repo_full_name, spec_path,
                   fix_attempts, failed_reason, timestamps per transition
project_events     id, project_id, type, actor(system|agent:<name>|user:<id>),
                   payload JSONB, created_at            -- append-only audit
pipeline_runs      id, project_id, stage(product|uiux|coding|test|fix|release|assets|
                     publish|marketing), attempt, status(queued|running|succeeded|
                     failed|cancelled), agent_session_ref, tokens_in/out, cost_usd,
                   started_at, finished_at, error
builds             id, project_id, platform(ios|android|web), version,
                   eas_build_id?, artifact_url, status, created_at
test_reports       id, project_id, pipeline_run_id, suite(typecheck|lint|unit|e2e|
                     acceptance), passed, failed, skipped, report JSONB
acceptance_criteria id, project_id, criterion, kind(automated|manual),
                   status(pending|passed|failed|waived)
store_assets       id, project_id, kind(name|subtitle|description|keywords|icon|
                     screenshot|promo|release_notes), locale, content TEXT?,
                   file_url?, status(generated|approved|rejected), version
store_submissions  id, project_id, store(apple|google), account_ref,
                   external_id, status(preparing|submitted|in_review|approved|
                     rejected|live), review_notes, submitted_at
approvals          id, project_id, gate(publish|ad_spend|content|expensive_action),
                   requested_at, payload JSONB, decided_by, decision(approved|
                     rejected), decided_at                -- human gates
marketing_campaigns id, project_id, platform(google|meta), strategy JSONB,
                   status(draft|pending_approval|live|paused|ended),
                   service_fee_eur, ad_budget_eur, external_campaign_id
creatives          id, campaign_id, kind(copy|image|video|landing), locale,
                   content/file_url, status
credentials        id, owner(customer_id|platform), service, vault_ref,
                   scopes, expires_at                    -- refs only, secrets in vault
-- MVP 6
idea_sources       id, store, app_external_id, name, category, fetched_at
mined_reviews      id, source_id, rating, text, lang, mined_at
idea_candidates    id, title, pitch, pains JSONB, sources JSONB,
                   score_commercial, score_complexity, est_build_cost_eur,
                   est_price_eur, status(candidate|approved|listed|rejected)
```

---

## 5. API design (main endpoints)

**Public (storefront):**
```
GET  /api/listings                      marketplace catalog (locale-aware)
GET  /api/listings/{slug}
POST /api/quotes                        custom idea → AI analysis → price + scope
GET  /api/quotes/{id}
POST /api/checkout                      quote_id|listing_id + packages + ad_budget
                                        → Stripe Checkout Session URL (price
                                        recomputed server-side, never trusted
                                        from the client)
POST /api/webhooks/stripe               checkout.session.completed → order PAID
                                        → create project → enqueue pipeline
```

**Customer portal (auth: magic link):**
```
POST /api/auth/magic-link | /api/auth/verify
GET  /api/me/projects
GET  /api/me/projects/{id}              status timeline, builds, test summary
GET  /api/me/projects/{id}/artifacts    download build / assets
POST /api/me/projects/{id}/feedback     REVIEW-stage change requests (scoped)
```

**Internal (worker + admin, token-scoped):**
```
POST /internal/runs/{id}/events         agent progress callbacks
POST /internal/runs/{id}/complete       stage result → orchestrator advances
GET  /internal/projects/{id}/context    scoped context bundle for a stage
POST /admin/approvals/{id}/decide       human gates (also callable via OpenClaw MCP)
POST /admin/projects/{id}/retry|abort
```

---

## 6. Agent pipeline

The orchestrator is deterministic code (Laravel state machine). Agents are Claude Agent SDK workers, each with its own system prompt, skills, and tool allowlist, running in a Docker sandbox with a checkout of the project repo. Their output contract is files + a structured JSON result — never free text driving control flow.

| Stage | Agent | Input → Output | Advance condition |
|---|---|---|---|
| SPECIFICATION | Product Agent | order + idea → `SPEC.md`, `acceptance-criteria.json`, screen list | spec validates against JSON schema |
| SPECIFICATION | UI/UX Agent | spec → screen map, component inventory, nav structure, design tokens | artifacts present |
| BUILDING | Coding Agent | spec + template fork → implemented app, per-feature commits | build compiles |
| TESTING | Test Agent | repo → typecheck, lint, unit, e2e smoke (Maestro/Playwright), acceptance checks | all acceptance criteria automated-pass |
| FIXING | Fix Agent | failing report → patches | re-enter TESTING; max **3 attempts** then FAILED + human escalation |
| REVIEW | — human gate — | customer preview (Expo preview build / web staging) | customer approval or 7-day auto-advance (policy TBD) |
| READY→PUBLISHING | Release Agent | repo → versioned production build via EAS, release artifacts | **human approval gate (always, initially)** |
| PUBLISHING | Publish workflow | assets + builds → store submissions | store review passes |
| MARKETING | Marketing Agent | app + audience → strategy, creatives, campaign drafts | **human approval gate before any spend** |

Hard rules encoded in the orchestrator, not in prompts:
- Never mark READY unless required tests pass (DB-enforced: transition checks `acceptance_criteria`)
- Human approval rows required for: production publishing, ad spend, flagged content, external actions above a cost threshold
- Per-project token/cost budget on `pipeline_runs`; breach → pause + approval request

**OpenClaw integration:** Factory API posts every transition to the OpenClaw gateway (`/hooks/agent`, webhook-result mode) → Patrick gets Telegram messages like "Project #42 → TESTING (2/14 criteria failing)" and can reply `approve publish 42`, which OpenClaw turns into an MCP call against `/admin/approvals`. OpenClaw never executes builds itself.

---

## 7. Queue & job design

- Laravel Horizon queues: `pipeline` (stage jobs), `builds`, `assets`, `marketing`, `idea-engine`, `notifications`
- Every job: idempotency key (`project_id:stage:attempt`), stage-level checkpoint in DB before/after, exponential backoff, max retries, dead-letter → project FAILED + OpenClaw alert
- Long agent runs are supervised: worker heartbeats into `pipeline_runs`; a watchdog re-queues stalled runs (agent transcripts persist, so re-runs resume from committed repo state, not from scratch)

## 8. Payments & pricing

- **Custom ideas:** `POST /api/quotes` runs a two-layer calculator: (1) Claude structures the idea into features/integrations/complexity (structured output, stored in `quotes.structured_spec`); (2) the deterministic estimator (server-side port of `create.html`'s `estimate()`: base €700 web / €1,200 mobile / €1,800 both + feature add-ons, B2B multiplier, clamp) prices it. Scope summary is presented before checkout. No build before payment.
- **Catalog listings:** fixed price from `listings`.
- **Packages** (separate Stripe line items): App Development · Store Publishing · Ownership/Transfer Assistance · Marketing Launch · Ad Budget (kept strictly separate from service fees, per doc 05 rules) · **Hosting & Maintenance subscription (Type B apps only, mandatory, recurring)** — presented with 12-month total up front per doc 05 honesty rules.
- Stripe Checkout Sessions created server-side (replaces static Payment Links — this also unblocks arbitrary amounts); webhook is the single source of payment truth; Stripe Invoicing on (legal entity, UID — open task from doc 27).
- Keep doc 05's honesty rules: totals up front, first-year math, no dark patterns.

## 9. Security

- Secrets only in the API's vault/env (Stripe, Apple, Google, Meta, GitHub App, Anthropic); workers receive short-lived scoped tokens per run
- Generated code never sees production secrets; each customer app backend (PocketBase instance / Postgres schema) is isolated with its own credentials
- Repo-per-project isolation; GitHub App tokens encrypted per customer (pattern already in management-api)
- OpenClaw gateway loopback/VPN-only, separate hook token, `hooks.allowedAgentIds` restricted, no filesystem/shell tools
- Append-only `project_events`; log redaction for PII

---

## 10. MVP roadmap with concrete tasks

### MVP 1 — pay → build → installable app

**Phase 0 · Foundation**
- [ ] Decisions from Patrick (see §12), domain; provision own server (Docker Compose, Postgres, Redis, reverse proxy)
- [ ] Monorepo scaffold: `apps/web` (Next.js), `apps/api` (Laravel 12), `packages/pricing` (TS estimator, shared), `workers/pipeline` (Node + Claude Agent SDK), `templates/expo-app`, `infra/`
- [ ] CI (GitHub Actions), Sentry, secrets layout

**Phase 1 · Storefront**
- [ ] Port appwerk design system + fonts to Next.js (tokens → CSS variables/theme)
- [ ] Marketplace pages with SSR locale routing (de/en), catalog seeded from `catalog.js` into `listings`
- [ ] Port create-wizard; quote flow against API; fix the two known bugs during port
- [ ] Reposition copy: "have this app built for you — you own it" (park license/share/fund modes)

**Phase 2 · Commerce**
- [ ] Core DB migrations (§4)
- [ ] `POST /api/quotes` (Claude structuring + deterministic pricing)
- [ ] Stripe Checkout Sessions + webhook → order → project(PAID); invoicing + branding config; live keys (Patrick)
- [ ] Magic-link auth + customer portal (project status timeline)
- [ ] § 18 FAGG waiver capture server-side + confirmation email (template → counsel)

**Phase 3 · Factory**
- [ ] Project state machine + Horizon + `project_events`
- [ ] GitHub App: repo-per-project provisioning from `templates/expo-app`
- [ ] Product Agent (SPEC.md + acceptance-criteria.json, schema-validated)
- [ ] UI/UX Agent (screen map + components)
- [ ] Coding Agent (feature-by-feature commits in sandbox)
- [ ] Test Agent (typecheck/lint/unit/e2e + acceptance checks) → Fix loop (max 3)
- [ ] Release Agent → EAS build → installable .apk / iOS preview in portal
- [ ] OpenClaw cockpit: transition notifications + approval commands via MCP

**Definition of done MVP 1:** a real customer pays for a small catalog app and receives an installable build with passing acceptance tests, with zero manual steps except the final human approval.

### MVP 2 — store assets & publishing
Asset generation (Claude + image-gen API for icon/screenshots/promo), `store_assets` review UI, App Store Connect API + Play Developer API submission workflows, customer-developer-account onboarding flow (Apple/Google policy-compliant), transfer-assistance playbook.

### MVP 3 — marketing creatives
Marketing Agent: audience analysis, campaign concepts, ad copy, creative generation, landing-page copy; approval UI. No spend yet.

### MVP 4 — Google Ads (apply for developer token during MVP 1 — long lead time)
Campaign publishing, budget caps, performance ingestion, reports.

### MVP 5 — Meta Ads (business verification + app review — also apply early)
Same shape as MVP 4.

### MVP 6 — idea engine
Port `review-intelligence` into `workers/idea-engine`; official/permitted data sources, scoring → `idea_candidates` → one-click conversion to `listings` drafts. First candidate to list: **TrainerPing**.

---

## 11. Missing credentials / services (acquisition checklist)

| Item | Needed for | Lead time |
|---|---|---|
| Stripe live keys + invoicing config + real domain | MVP 1 | days (Patrick) |
| Anthropic API key (production, budgeted) | MVP 1 | days |
| GitHub org + GitHub App | MVP 1 | days |
| Expo/EAS account (paid tier for build minutes) | MVP 1 | days |
| Own server provisioning (Docker, Postgres, Redis, proxy) — server already available | MVP 1 | days |
| Transactional email (Postmark/Resend) | MVP 1 | days |
| Image-gen API (icons/screenshots/creatives) | MVP 2/3 | days |
| Apple Developer Program + ASC API key (ours, for tooling) | MVP 2 | ~1 week |
| Google Play Console + service account (ours) | MVP 2 | ~1 week |
| Customer developer accounts (process, not credential) | MVP 2 | per customer |
| Google Ads developer token (basic access application) | MVP 4 | **weeks — apply during MVP 1** |
| Meta business verification + Marketing API app review | MVP 5 | **weeks — apply during MVP 1** |
| Apify paid plan | MVP 6 | days |

## 12. Open decisions for Patrick

1. **Positioning** — confirm §2: AI Factory build-service ("you own your app") as primary; appwerk license/share/fund modes parked pending counsel. *(Recommended: yes)*
2. **Customer-app stack** — Expo vs Flutter. *(Recommended: Expo + tiered self-hosted backend on our own server: local-first default → PocketBase per app → shared Postgres; managed BaaS only under the customer's own account)*
3. **Platform backend** — Laravel (reuses management-api patterns, team knowledge) vs Node full-stack. *(Recommended: Laravel API + Node agent workers)*
4. **Domain & brand** — appwerk.codemenschen.at? new brand for AI Factory? (trademark/domain check from doc 02 still open)
5. **Hosting & maintenance subscription pricing** for Type B (backend) apps — mandatory monthly fee; Patrick sets the price bands (e.g. €9–29/month by tier). Type A (local) apps: optional maintenance only
6. **REVIEW-stage policy** — customer approval required vs 7-day auto-advance

## 13. Risks

- **Legal:** FAGG waiver flow must be confirmed by counsel before launch (template exists); Werkvertrag T&Cs needed (simpler than appwerk's, but new); GDPR (DPA with customers, AVV for EAS; self-hosted app backends on our own server keep the processor list short)
- **Store policy:** template-app rejection risk → publish under customer accounts, ensure per-app differentiation (design tokens, real content); app review delays are outside our SLA — communicate delivery times as estimates
- **Quality/refunds:** an AI pipeline will sometimes fail — FAILED path needs a defined refund policy and human-rescue lane (Codemenschen devs)
- **Cost control:** per-project token budgets enforced in `pipeline_runs`; expensive external actions gated
- **OpenClaw exposure:** gateway must never be internet-facing; loopback/VPN + tokens only
- **Marketing APIs:** access approval timelines are the long pole — apply early (MVP 1 timeframe)

---

*Sources: full analysis of `appwerk/docs` 01–30 + legal-01..03, `appwerk/site` code audit, `Codemenschen_OpenClaw` architecture docs, OpenClaw official docs (docs.openclaw.ai) and security research (Bitsight, Backslash, Barracuda).*
