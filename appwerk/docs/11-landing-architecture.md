# 11 — Landing Page Architecture (Homepage Section Spec)

**Project:** Appwerk (working name) — codemenschen.at, Vienna
**Status:** Fixed architecture. This document specifies each homepage section: purpose, the emotional-arc step it serves, content blocks, and CTA logic.
**Legal:** All claims subject to [COUNSEL REVIEW] where marked. No earnings projections, no FOMO, no "own a business" claims anywhere on the page.

---

## The Emotional Arc (governing model)

Every section exists to advance the visitor exactly one step along this arc:

```
1. "I didn't know this was possible"        → Hero
2. "OK, this actually makes sense"          → How it works
3. "They solved the hard part"              → Division of labor
4. "I don't need developers"                → Division of labor + Listings preview
5. "I could license my own app"             → Listings preview + Risk + Pricing
6. "Let me browse"                          → Final CTA (and every intermediate CTA)
```

A section that does not move the visitor along this arc, or that introduces
urgency/hype, is out of scope. The page persuades by *removing objections in
order*, not by adding excitement.

**Framing sentence (canonical, use everywhere):**
> "License and back your own app. We build, promote, and run it."

Never: "own", "invest in a business", "passive income", "returns".

---

## Section 1 — Hero

**Arc step served:** "I didn't know this was possible."

**Purpose:** State the offer in one plain-language sentence a non-technical
ETF/real-estate investor understands on first read. Establish honesty as the
brand voice within the first viewport — the risk note appears *before* the fold,
which is itself a trust signal.

**Content blocks:**
1. **H1 (Fraunces display):** plain-language claim.
   Working copy: *"License an app that real users already paid for."*
2. **Subline (Inter, max 68ch):** honest mechanics in one breath.
   Working copy: *"Codemenschen validates prototype apps with paying test users.
   You license one exclusively — €500 to €5,000 plus a monthly retainer — and we
   build, promote, and operate it for you."*
3. **Primary CTA:** `Browse available apps` → `/apps` (accent #2B5BE3, solid).
4. **Secondary CTA (text link):** `How it works ↓` — smooth-scrolls to Section 2.
5. **One-line risk note (small, ink at 70% opacity, NOT hidden):**
   *"Apps can fail. Licensing is a risk, not a guaranteed income."* [COUNSEL REVIEW]
6. Optional supporting visual: abstract data/structure motif (see doc 19). No
   device mockups with fake dashboards, no stock people.

**CTA logic:** Primary action from the very first viewport is *Browse* — the
page's single conversion goal. Secondary is *learn* (scroll), never a second
conversion path. No email capture in the hero.

**Exclusions:** No counters, no "X investors joined", no countdowns, no logos-wall.

---

## Section 2 — How it works

**Arc step served:** "OK, this actually makes sense."

**Purpose:** Compress the entire lifecycle into four steps so the model feels
simple and legible. This is where the visitor decides the concept is coherent.

**Content blocks — 4 steps, horizontal on desktop, stacked on mobile:**

| # | Step label | One-line body |
|---|-----------|----------------|
| 1 | **We validate** | We build prototypes and test them with real users who pay real money. Only validated apps are listed. |
| 2 | **You license** | You take the exclusive license to one app — one buyer per app, €500–5,000 one-time. |
| 3 | **We build & operate** | Our team develops, hosts, maintains, and promotes the app. Funded by your monthly retainer (5–10%) and optional ad budget. |
| 4 | **You track everything** | Live dashboard: users, revenue, costs, roadmap. You set priorities; we execute. |

Each step: number in Fraunces, thin-stroke geometric icon, label, ≤2 lines body.

**CTA logic:** Single quiet text link at the end of the row:
`See the full process →` → `/how-it-works`. No primary CTA here — the visitor
is mid-comprehension; interrupting with "Browse" would feel salesy.

---

## Section 3 — Division of labor

**Arc steps served:** "They solved the hard part" + "I don't need developers."

**Purpose:** Kill the two biggest objections of a non-technical audience in one
two-column layout: *I can't build software* and *I can't run software*. The
visual asymmetry (our column is long, yours is short) IS the message.

**Content blocks — two columns:**

**What we do (everything technical):**
- Design, development, hosting, security, updates
- App-store submissions and compliance
- Marketing execution and ad management (if ad budget chosen)
- Support, bug fixes, monitoring
- Reporting: transparent dashboard, monthly summary

**What you do:**
- Choose an app you believe in
- Fund it (license + retainer, optional ad budget)
- Decide roadmap priorities from our proposals

**Closing line (Inter, one sentence):**
*"You never write code, hire anyone, or manage a team. You back the app; we run it."*

**CTA logic:** None in-section, or at most the persistent header CTA. This
section is pure objection removal; adding a button dilutes it.

---

## Section 4 — App listings preview

**Arc steps served:** "I don't need developers" → "I could license my own app."

**Purpose:** Make the offer concrete. The abstract model becomes three real
cards with real validation numbers. This is the emotional pivot from
understanding to wanting — driven by *data*, not adjectives.

**Content blocks:**
1. Section heading: *"Apps available now"* + subline: *"Every listing shows real
   results from a paid validation test — not projections."*
2. **3 listing cards** (desktop row of 3, mobile stack), each with:
   - App icon (thin-stroke geometric placeholder) + title + category tag
   - **Validation actuals**, PLACEHOLDER data clearly marked in source:
     e.g. `PLACEHOLDER: "214 users paid during 6-week test"`,
     `PLACEHOLDER: "€1,840 test revenue · 31% week-4 retention"`
     Data figures rendered in success green #1A7F5A (validation-data color).
   - License price range + retainer % (upfront, on the card)
   - Status chip stating fact only: `Available — one buyer per app` /
     `Licensed` (grayed). Never "Only 1 left!", never countdowns.
3. **"One buyer per app" fact line** below the grid, stated flatly:
   *"Each app is licensed exclusively to one person. Once licensed, it leaves
   the marketplace unless it is later resold on-platform (5% fee)."*

**CTA logic:** Card click → listing detail. Grid-level CTA:
`Browse all available apps` (primary, accent) → `/apps`. This is the page's
strongest conversion moment; the primary CTA reappears here deliberately.

**Exclusions:** No blurred "premium" cards, no fake sold-out pressure, no
projected earnings on any card. All numbers are historical test actuals.

---

## Section 5 — Honest risk section ("What can go wrong")

**Arc step served:** Protects "I could license my own app" from collapsing later.
Trust consolidation — the section skeptical investors scroll to find.

**Purpose:** Disclose failure modes and exit mechanics in plain language before
the visitor asks. For an audience trained on ETF/real-estate risk disclosures,
this section is the credibility test. Design it as a first-class section —
same typographic quality as the hero, not fine print.

**Content blocks:**
1. Heading (Fraunces): *"What can go wrong"*
2. **Risk list (plain sentences, not softened):**
   - *"The app can fail. Validation reduces risk; it does not remove it. You can
     lose your license fee and retainer payments."*
   - *"User numbers from the test period may not continue or grow."*
   - *"You are buying an exclusive license — not the software's ownership,
     intellectual property, or equity in Codemenschen."* [COUNSEL REVIEW]
3. **Suspension & exit mechanics (plain language):**
   - *"If retainer payments stop, the license is suspended and the app is
     relisted on the marketplace."*
   - *"If it resells, you receive the surplus after outstanding arrears and the
     5% resale fee are deducted."* [COUNSEL REVIEW]
   - *"You can also resell voluntarily on-platform at any time (5% fee)."*
4. **Regulatory placeholders:** EU consumer withdrawal right notice; Austrian
   KMG prospectus-requirement assessment — both [COUNSEL REVIEW], with visible
   placeholder blocks in staging.

**CTA logic:** One text link: `Read the full risk & exit terms →` → `/terms`
(or `/faq#risk`). No primary CTA — selling immediately after disclosing risk
undercuts the disclosure.

---

## Section 6 — Pricing transparency

**Arc step served:** "I could license my own app" — removes the last practical
unknown (total cost of participation).

**Purpose:** All money flows on one screen, one diagram, zero asterisks that
hide substance.

**Content blocks:**
1. Heading: *"Every cost, on one page."*
2. **Cost items (four, equal visual weight):**
   - **License price:** €500–5,000 one-time, set per app, shown on every listing.
   - **Monthly retainer:** 5–10% (basis stated per listing) — funds ongoing
     development and operations. [COUNSEL REVIEW: basis definition]
   - **Optional ad budget:** you choose the amount; we execute; spend reported
     in the dashboard. €0 is a valid choice.
   - **Resale fee:** 5% of resale price, only if/when you resell on-platform.
3. **Single flow diagram** (static SVG, thin-stroke): You → license fee +
   retainer (+ ad budget) → Appwerk → build/operate/promote → app; resale path:
   app → marketplace → new licensee → proceeds − arrears − 5% → you.
4. Footnote: *"No hidden fees. No revenue-share beyond the retainer.
   App revenue handling: [COUNSEL REVIEW — specify flow of app income]."*

**CTA logic:** `See pricing on each listing →` (text link to `/apps`). Pricing
is per-app, so the CTA routes to listings rather than a pricing page.

---

## Section 7 — Who we are

**Arc step served:** Underwrites every prior claim with a real counterparty.
"Who is 'we'?" answered before the FAQ.

**Content blocks:**
1. Heading: *"Codemenschen, Vienna."*
2. Short paragraph: Codemenschen GmbH [COUNSEL REVIEW: exact legal form] is a
   Vienna software agency; the team that validates, builds, and operates every
   app on Appwerk. Years active, team size — real numbers only.
3. **Human anchor:** one real photo of the actual team or founder (the single
   permitted photographic element on the page — see doc 19), name and role.
4. Links: `About us →` `/about`, Impressum link, codemenschen.at.

**CTA logic:** Secondary/text links only.

---

## Section 8 — FAQ preview

**Arc step served:** Sweeps residual objections; last stop before commitment.

**Content blocks:** 4–5 accordion items, chosen to close the arc:
1. "What exactly am I buying?" (license, not ownership/IP/equity)
2. "What happens if the app fails?"
3. "What happens if I stop paying the retainer?" (suspension → relist → surplus net of arrears)
4. "Can I sell my license?" (on-platform resale, 5% fee)
5. "Do I need any technical knowledge?" (no)

Answers ≤3 sentences each, same plain language as Section 5.
Link: `All questions →` → `/faq`.

**CTA logic:** Text link only; the accordion is the interaction.

---

## Section 9 — Final CTA + risk footnote

**Arc step served:** "Let me browse."

**Content blocks:**
1. Short Fraunces line: *"See what's available."* Optionally the canonical
   framing sentence as subline.
2. **Primary CTA:** `Browse available apps` → `/apps`. Identical label to the
   hero — one action, repeated, never varied.
3. **Risk footnote (persistent, small but legible ≥14px):**
   *"Licensing an app is a risk. Apps can fail and payments are not refundable
   beyond your statutory rights. One buyer per app is a marketplace rule, not a
   promise of value."* [COUNSEL REVIEW]
4. Footer follows (nav, legal links, Impressum, language switch DE/EN).

**CTA logic:** This is the third and final appearance of the primary CTA
(hero → listings grid → final). Three appearances, one label, one destination.

---

## Cross-section rules

- **One primary action per page:** Browse available apps. All other CTAs are
  text links (see doc 15).
- **Self-serve:** no "Book a call", no phone numbers in CTAs, no chat widget
  popping open.
- **Language:** DE/EN auto-detected, switcher in header + footer (doc 12).
- **Placeholders:** every fabricated metric is marked `PLACEHOLDER` in source
  and design files until real validation data replaces it.
