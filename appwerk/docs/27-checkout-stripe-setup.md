# 27 — Checkout & Stripe Setup

**Status:** Front-end implemented (`site/checkout.html`, `site/success.html`, `site/account.html`). **Sandbox setup done via API (2026-07-07):** prices `price_1TqZwzR1fGNz58cS0AQSC8lg` (FP license), `price_1TqZx0R1fGNz58cSpG4TxhKJ` (FP retainer), `price_1TqZx0R1fGNz58cSeINM6Czi` (MG license), `price_1TqZx1R1fGNz58cS35i6VUF8` (MG retainer); test payment links are pasted in `checkout.html` (`buy.stripe.com/test_…`). Redirect points to placeholder `https://appwerk.codemenschen.at/success.html` — update to the real domain before launch. Repeat the same API calls with a live restricted key for launch. No backend required for v1.

## Architecture (v1, no backend)

```
Listing page → checkout.html?app={slug}
  1. Ad budget: with/without (amount select) — billed separately, can be added later
  2. Withdrawal choice: deferred start (default) OR immediate start + express FAGG waiver checkbox (unticked)
  3. Terms acceptance checkbox
  → redirect to Stripe Payment Link (?client_reference_id={slug}_{wd}_{ads}_{lang}&locale=…)
     Stripe hosts the complete payment: card/SEPA, receipt, INVOICE
  → Stripe redirects back to success.html
  → account access delivered manually by email within 1 business day (dashboard is post-MVP)
```

Design decisions:
- **No multi-item cart.** One app = one buyer; the checkout page *is* the cart.
- **Ad budget is not part of the Stripe payment.** It must be changeable/stoppable monthly and starts only after campaign approval — so it is recorded as intent (`client_reference_id`) and billed separately once the account exists. This also keeps one static Payment Link per app.
- **FAGG waiver** is enforced in the UI: the immediate-start option requires the express checkbox (never pre-ticked); switching back to deferred clears it. The choice travels to Stripe in `client_reference_id` so it is stored with the payment. The waiver confirmation email (durable medium, § 7 Abs 3 FAGG) must be sent after payment — manual for v1. [COUNSEL REVIEW before launch]

## Stripe Dashboard — one-time setup per app

1. **Products** (Catalog → Products), per app:
   - `FormPilot — exclusive license`: one-time price €3,900.
   - `FormPilot — monthly retainer (7%)`: recurring price €273/month.
   - (Mealgrid: €1,400 one-time / €84 per month.)
2. **Payment Link** (Payment Links → New), per app:
   - Line items: the one-time license price + the recurring retainer price. (Payment Links support mixing one-time and recurring items; the customer sees "€3,900 today, then €273/month".)
   - **If the retainer must start only at license activation** (deferred 14-day path), either add a 14-day trial variant or start the subscription manually later via the Dashboard instead of including it in the link. Decide with counsel — simplest compliant v1: include only the license in the Payment Link and start the retainer subscription from the Dashboard at activation. [OPERATOR DECISION]
   - After payment → redirect to `https://<domain>/success.html`.
   - Options: collect billing address; enable **invoice creation** ("Create an invoice for each payment") so the customer gets a real invoice, not just a receipt; enable German + English.
3. **Branding** (Settings → Branding): Appwerk logo/colors so the Stripe page doesn't break trust mid-flow.
4. **Invoices** (Settings → Invoicing): set Codemenschen legal entity data, UID, invoice numbering — this is the invoice the buyer keeps.
5. Paste the two `https://buy.stripe.com/…` URLs into `STRIPE_LINKS` in `site/checkout.html`.
6. **Test mode first**: create the same setup with test-mode links, pay with card `4242 4242 4242 4242`, verify the invoice email and the `client_reference_id` on the payment, then swap in live links.

## What arrives with each payment

`client_reference_id` = `{app}_{immediate|deferred}_ads{0|300|500|1000|2000}_{en|de}` — visible on the Checkout Session / PaymentIntent in the Dashboard. Manual fulfillment checklist per payment (v1):

1. Send waiver confirmation (if `immediate`) — durable medium, same day. [COUNSEL REVIEW template]
2. Create account, send credentials (≤1 business day).
3. Activate license now (`immediate`) or schedule day 15 (`deferred`); send dated confirmation.
4. If `ads>0`: propose first campaign for approval; set up separate ad-budget billing (Stripe subscription or invoice) only after the go.

## Post-MVP (needs backend)

- Real login/dashboard (`/account` reserved in IA doc 12).
- Stripe webhooks (`checkout.session.completed`) to automate account creation, waiver email, and activation scheduling.
- Ad-budget self-serve toggle → creates/cancels the ad-budget subscription via API.
- Customer portal (Stripe Billing Portal) for the retainer subscription.
