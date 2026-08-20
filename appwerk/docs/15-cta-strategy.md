# 15 — CTA Strategy

**Project:** Appwerk — codemenschen.at
**Governing rule:** One primary action per page. On the homepage that action is
**Browse available apps** (`/apps`). Everything else is a text link. The site
is self-serve: no "book a call", ever.

---

## 1. CTA hierarchy

### Tier 1 — Primary (one per page)
- **Label:** `Browse available apps` (EN) / `Verfügbare Apps ansehen` (DE)
- **Destination:** `/apps` (`/en/apps/`)
- **Style:** solid accent #2B5BE3, paper text, 8px radius, 52px min height on
  mobile. The ONLY solid accent button on the page.
- **Homepage placements (exactly three):** Hero (§1), after listings grid (§4),
  final CTA (§9). Plus the persistent header button (same label, compact).
- **Label discipline:** identical wording at every placement. Never "Get
  started", "Explore opportunities", "Start now" — the label states literally
  what happens on click.

On the listings index the primary shifts to the card itself; on listing detail
it is `License this app` (self-serve checkout entry). Still one primary per page.

### Tier 2 — Secondary (learning path)
- **Label:** `How it works` (hero: `How it works ↓` scroll; elsewhere → page)
- **Style:** text link in accent, optional arrow glyph. Never a filled button,
  never a "ghost button" that visually competes with Tier 1.
- **Purpose:** the not-yet-convinced path. Secondary CTAs always route to
  *comprehension* (process, risk, pricing detail), never to a parallel
  conversion funnel (no newsletter, no waitlist on the homepage).

### Tier 3 — Contextual text links
`See the full process →`, `Read the full risk & exit terms →`, `See pricing on
each listing →`, `All questions →`, `About us →`. Inter, accent color,
underline on hover only. One per section maximum.

### Explicitly absent
- Email-capture forms above the fold; newsletter interstitials
- "Book a call" / phone CTAs (self-serve model)
- Exit-intent modals, scroll-hijack CTAs, chat-widget prompts
- Duplicate primary styles (two solid buttons in one viewport)

---

## 2. Placement logic (why three, why there)

| Placement | Arc moment | Rationale |
|---|---|---|
| Hero | curiosity | Lets the already-convinced or returning visitor skip the pitch. |
| After listings grid (§4) | desire peak | The offer just became concrete with real data — highest-intent moment on the page. |
| Final CTA (§9) | resolution | The visitor has seen risk and pricing; the click here is an *informed* click, the only kind we want. |

**Deliberate non-placements:** no primary CTA in the risk section (§5) or
immediately after it, and the mobile sticky bar hides while §5 is in view —
selling against a risk disclosure destroys the disclosure's credibility. No
primary CTA in §2/§3 (mid-comprehension) or §7 (credibility, not conversion).

---

## 3. Microcopy rules

### Rule 1 — No urgency language. Ever.
Banned: "Don't miss out", "Only X left", "Limited time", "Act now", "Selling
fast", "Last chance", "🔥", countdown timers, pulsing badges.
Scarcity is stated **only as fact, in body copy, never in a CTA**:
`One buyer per app.` — full stop, no exclamation mark. The button next to a
sold listing says nothing urgent; the listing simply shows status `Licensed`.

### Rule 2 — Labels describe the click, not the dream.
- Yes: `Browse available apps`, `License this app`, `Read the full terms`
- No: `Start your journey`, `Unlock opportunities`, `Own your future`,
  `Invest now` (also legally wrong — it's a license, not an investment product
  claim) [COUNSEL REVIEW on any label containing "invest"]

### Rule 3 — No manufactured social proof in or near CTAs.
No "Join 500+ investors", no fake activity toasts ("Max from Linz just
licensed…"), no star ratings we didn't earn.

### Rule 4 — Risk copy may sit next to a CTA; hype may not.
The hero and final CTA both carry an adjacent risk line. This is intentional:
the brand's conversion thesis is that honesty next to the button *increases*
qualified clicks and reduces refund/withdrawal churn. [COUNSEL REVIEW: EU
withdrawal right notice proximity to purchase CTAs in checkout]

### Rule 5 — Verbs first, ≤4 words where possible, sentence case.
Sentence case (`Browse available apps`), never ALL CAPS buttons, no trailing
exclamation marks anywhere in CTA or heading copy.

### Rule 6 — DE and EN are written, not translated.
German labels must be idiomatic (`Verfügbare Apps ansehen`, `So funktioniert's`,
`Diese App lizenzieren`), reviewed by a native speaker, and obey the same rules.

---

## 4. States & accessibility

- Hover: darken accent ~8% (#2450C9), 150ms; no growth/glow effects.
- Focus: 2px accent outline, 2px offset — always visible, never removed.
- Disabled (e.g., `Licensed` listing): warm gray #E6E3DC bg, ink @40% text,
  cursor default, with a plain-text explanation adjacent — never a disabled
  button without a stated reason.
- Loading (checkout): inline spinner replacing label, button width locked.
- Contrast: accent-on-paper and paper-on-accent both pass WCAG AA at button
  sizes; verify #1A7F5A data text ≥ 4.5:1 on paper.

---

## 5. Measurement (honest analytics)

Track: CTA click-through per placement, scroll depth to §5 (risk) before
first primary click (we *want* this high), listing-detail → checkout entry.
Do not optimize toward pre-risk clicks; the KPI is informed conversion, not
raw conversion. Consent-gated analytics per GDPR. [COUNSEL REVIEW]
