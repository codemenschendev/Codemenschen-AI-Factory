# 28 · Shared deals & funding-stage listings

Status: prototype built 2026-07-08 · **entire mechanism pending legal review [COUNSEL REVIEW]**

> **Update 2026-07-08 (later same day):** the platform model pivoted — there is
> no real-user validation stage anymore. Apps are selected by an automated AI
> business analysis of app-store data; every listing shows KPI tiles (build
> cost, marketing budget, possible monthly revenue, price point), all marked
> as AI-estimated potential. References to "validation" below describe the old
> model and survive only as history. `mode=share` now also lets buyers take a
> pro-rata revenue share of *available* (built) apps, not just funding-stage
> ones; full-price Stripe links are never used for partial shares.

## The model

A third listing state next to `available` and `taken`: **`funding`** — an app that
does not exist yet. The listing publishes a funding goal (build + validation
budget), the amount already committed, and a minimum ticket.

Two ways in:

1. **Sole owner** — take the whole remaining amount. At 100% you hold the
   exclusive license, identical to the standard model.
2. **Join a deal** — contribute any multiple of the minimum ticket
   (e.g. €300 steps). Your revenue share equals your share of the goal:
   €1,500 of a €3,000 goal = 50% of the app's net revenue.

Rules (as prototyped, all subject to counsel):

- The build starts **only at 100% funding**. Example: goal €3,000, someone has
  paid €1,500 — the app gets built when the remaining 50% is paid.
- If the goal is not reached within **60 days**, all contributions are refunded
  in full.
- The monthly retainer is split **pro-rata** across shareholders; roadmap votes
  are weighted by share.
- If validation fails the listing threshold, we say so publicly. Funders keep
  their revenue share of whatever the app earns; there is no buy-back.

## Estimated scenarios ("business plan" section)

Every listing (available and funding) now shows a 12-month scenario model,
computed live in `app.html` from machine-readable unit economics in
`catalog.js` (`unit: { priceMo, cac, churn, startPayers }`):

- Three scenarios: worse than tested (CAC ×2, churn ×1.5), as tested,
  better than tested (CAC ×0.8, churn ×0.7).
- Each month: payers churn, the default ad budget buys new payers at CAC.
- Output: payers at month 12, 12-month revenue, costs (retainer + ads), net.
- Guardrails: churn never modelled below 4% (even where the test measured
  lower — Praxo measured 0); the worst case is visibly negative; copy states
  "reality can be worse than the worst case shown"; license price deliberately
  excluded from the net line and said so.
- Funding-stage apps show the same table with an extra warning that every
  input is an assumption (`unit.assumed: true`).

## Legal flags — why this cannot launch as-is

- Selling **revenue shares to multiple people** is very likely regulated
  crowdinvesting in Austria (**AltFG**) and/or a security-like instrument
  (**KMG**) — a materially bigger question than the single-license model in
  legal-01. Counsel must classify before any live payment.
- Holding funds until 100% / refund-at-60-days implies **escrow-like handling**
  — payment-services (ZaDiG) questions; Stripe alone does not provide this.
- The scenario tables are estimates published by the seller — consumer-law and
  prospectus-liability exposure; every table carries the [COUNSEL REVIEW]
  marker and anti-forecast disclaimers, but wording needs counsel.

## Implementation map

- `catalog.js` — `unit` economics per app; `rechni` = first funding-stage app
  (goal €3,000, committed €1,500, min ticket €300, 60-day deadline; empty
  `stripe` so checkout shows the staging notice).
- `app.html` — `planSection()` scenario table on all listings;
  `renderFunding()` funding-stage page (progress bar, deal rules, scope, risks
  first).
- `index.html` — funding card variant with progress bar.
- `checkout.html` — `?app=slug&mode=fund` (+`&full=1` to preselect the full
  remaining amount): contribution picker, pro-rata summary, fund-specific
  terms checkbox, no withdrawal-waiver panel (performance starts at 100%);
  `client_reference_id` = `slug_fund_eurN_pctN_lang`.
- `styles.css` — `.fund-bar`, `.badge-funding`, `.scen-neg/.scen-pos`.
