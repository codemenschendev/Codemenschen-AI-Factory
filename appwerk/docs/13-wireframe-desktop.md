# 13 — Desktop Wireframes (Homepage)

**Project:** Appwerk — codemenschen.at
**Canvas assumptions:** 1440px reference viewport, 12-column grid, 1120px max
content width, 80px gutters, generous vertical rhythm (120–160px between
sections). Paper #FAF9F6 background unless noted. Body measure capped at 68ch.

Legend: `[■ ...]` = primary button (accent #2B5BE3) · `[...]` = secondary/text
link · `▢` = thin-stroke geometric icon · `(A#)` = annotation reference.

---

## Header (sticky, all pages)

```
┌──────────────────────────────────────────────────────────────────────────────┐
│  APPWERK        Apps   So funktioniert's   Über uns   FAQ    DE|EN  [■ Apps  │
│  (wordmark)                                                        ansehen →]│
└──────────────────────────────────────────────────────────────────────────────┘
```
(A1) 64px height. Paper bg; 1px #E6E3DC bottom border appears only after
scroll > 8px. (A2) Wordmark in Fraunces; nav in Inter 15px. (A3) Header CTA
identical label/destination to hero CTA.

---

## Section 1 — Hero

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│   ┌─────────────────────────────────────┐   ┌──────────────────────────┐    │
│   │  License an app that real           │   │                          │    │
│   │  users already paid for.            │   │   ▢  abstract data/      │    │
│   │  (H1, Fraunces, ~56px)              │   │      structure motif     │    │
│   │                                     │   │      (SVG, no photo,     │    │
│   │  Codemenschen validates prototype   │   │      no fake dashboard)  │    │
│   │  apps with paying test users. You   │   │                     (A6) │    │
│   │  license one exclusively — €500 to  │   └──────────────────────────┘    │
│   │  €5,000 plus a monthly retainer —   │                                   │
│   │  and we build, promote, and run it. │                                   │
│   │  (Inter 19px, ≤68ch)          (A4)  │                                   │
│   │                                     │                                   │
│   │  [■ Browse available apps ]         │                                   │
│   │  [ How it works ↓ ]           (A5)  │                                   │
│   │                                     │                                   │
│   │  Apps can fail. Licensing is a      │                                   │
│   │  risk, not a guaranteed income.     │                                   │
│   │  (13px, ink @70%)  [COUNSEL REVIEW] │                                   │
│   └─────────────────────────────────────┘                                   │
└──────────────────────────────────────────────────────────────────────────────┘
```
(A4) Text column spans 6 of 12 cols; motif 5 cols, 1 col gap. (A5) Primary and
secondary CTAs on one row, 24px gap; secondary is a text link with ↓ glyph,
smooth-scrolls to §2. (A6) Motif animates once on load: 400ms fade/rise, then
static. Risk line is inside the first viewport at 1440×900 — non-negotiable.

---

## Section 2 — How it works

```
┌──────────────────────────────────────────────────────────────────────────────┐
│   How it works  (H2, Fraunces)                                               │
│                                                                              │
│   ┌────────────┐   ┌────────────┐   ┌────────────┐   ┌────────────┐         │
│   │ 1  ▢       │   │ 2  ▢       │   │ 3  ▢       │   │ 4  ▢       │         │
│   │ We         │──▶│ You        │──▶│ We build   │──▶│ You track  │         │
│   │ validate   │   │ license    │   │ & operate  │   │ everything │         │
│   │            │   │            │   │            │   │            │         │
│   │ Real users │   │ Exclusive. │   │ Dev, host, │   │ Users,     │         │
│   │ pay real   │   │ One buyer  │   │ promote —  │   │ revenue,   │         │
│   │ money in a │   │ per app.   │   │ funded by  │   │ costs,     │         │
│   │ test.      │   │ €500–5,000.│   │ retainer.  │   │ roadmap.   │         │
│   └────────────┘   └────────────┘   └────────────┘   └────────────┘         │
│                                                          (A7)               │
│                                     [ See the full process → ]              │
└──────────────────────────────────────────────────────────────────────────────┘
```
(A7) 4 equal cards, 3 cols each. Connecting arrows are 1px #E6E3DC strokes —
quiet, not decorative. Cards fade/rise in sequence (60ms stagger) on scroll
into view; ease-out 350ms; reduced-motion → no animation.

---

## Section 3 — Division of labor

```
┌──────────────────────────────────────────────────────────────────────────────┐
│   You never need developers.  (H2)                                           │
│                                                                              │
│   ┌───────────────────────────────────┐  ┌───────────────────────────────┐  │
│   │  WHAT WE DO          (ink panel,  │  │  WHAT YOU DO      (paper,     │  │
│   │                   #0B0E14, text   │  │            1px #E6E3DC        │  │
│   │                    in paper) (A8) │  │            border)            │  │
│   │  ▢ Design & development           │  │  ▢ Choose an app you          │  │
│   │  ▢ Hosting, security, updates     │  │    believe in                 │  │
│   │  ▢ App-store submissions          │  │  ▢ Fund it (license +         │  │
│   │  ▢ Marketing & ad management      │  │    retainer, optional         │  │
│   │  ▢ Support & monitoring           │  │    ad budget)                 │  │
│   │  ▢ Transparent reporting          │  │  ▢ Decide roadmap             │  │
│   │                                   │  │    priorities                 │  │
│   └───────────────────────────────────┘  └───────────────────────────────┘  │
│                                                                              │
│   "You never write code, hire anyone, or manage a team.                      │
│    You back the app; we run it."                            (A9)             │
└──────────────────────────────────────────────────────────────────────────────┘
```
(A8) The dark ink panel is the page's one dark surface — visual weight says
"we carry the heavy part". 7-col vs 5-col split reinforces asymmetry.
(A9) Closing line centered, Inter 19px. No CTA in this section.

---

## Section 4 — App listings preview

```
┌──────────────────────────────────────────────────────────────────────────────┐
│   Apps available now  (H2)                                                   │
│   Every listing shows real results from a paid validation test —             │
│   not projections.                                                           │
│                                                                              │
│   ┌───────────────────┐  ┌───────────────────┐  ┌───────────────────┐       │
│   │ ▢  FitPilot       │  │ ▢  RentLog        │  │ ▢  MealSync       │       │
│   │    Health · iOS   │  │    PropTech · Web │  │    Food · iOS+And │       │
│   │ ─────────────────  │  │ ────────────────── │  │ ────────────────── │       │
│   │ 214 users paid    │  │ 87 users paid     │  │ 342 users paid    │       │
│   │ during 6-week     │  │ during 4-week     │  │ during 8-week     │       │
│   │ test        (A10) │  │ test              │  │ test              │       │
│   │ €1,840 test rev.  │  │ €960 test rev.    │  │ €2,210 test rev.  │       │
│   │ (green #1A7F5A)   │  │                   │  │                   │       │
│   │ ─────────────────  │  │ ────────────────── │  │ ────────────────── │       │
│   │ License €2,400    │  │ License €900      │  │ License €3,800    │       │
│   │ Retainer 7%/mo    │  │ Retainer 5%/mo    │  │ Retainer 8%/mo    │       │
│   │ ● Available (A11) │  │ ● Available       │  │ ○ Licensed        │       │
│   └───────────────────┘  └───────────────────┘  └───────────────────┘       │
│                                                                              │
│   Each app is licensed exclusively to one person. Once licensed, it          │
│   leaves the marketplace unless later resold on-platform (5% fee).           │
│                                                                              │
│                      [■ Browse all available apps ]                          │
└──────────────────────────────────────────────────────────────────────────────┘
```
(A10) ALL metrics are `PLACEHOLDER` until real validation data exists — mark in
CMS and design files. Numbers set in tabular Inter; validation figures in
#1A7F5A. (A11) Status chip states fact only. "Licensed" card renders at 60%
opacity, not removed — proof the one-buyer rule is real. Card hover: border
#E6E3DC → accent, translateY(-2px), 200ms; whole card is the link.

---

## Section 5 — Honest risk ("What can go wrong")

```
┌──────────────────────────────────────────────────────────────────────────────┐
│   What can go wrong  (H2, Fraunces — full display size, NOT fine print)      │
│                                                                              │
│   ┌────────────────────────────────────────────────────────────┐             │
│   │  · The app can fail. Validation reduces risk; it does not  │             │
│   │    remove it. You can lose your license fee and retainer   │             │
│   │    payments.                                               │             │
│   │  · Test-period results may not continue or grow.           │             │
│   │  · You are buying an exclusive license — not ownership,    │             │
│   │    IP, or equity.                    [COUNSEL REVIEW]      │             │
│   │                                                      (A12) │             │
│   │  IF PAYMENTS STOP                                          │             │
│   │  License suspended → app relisted → if resold, you get     │             │
│   │  the surplus after arrears and the 5% resale fee.          │             │
│   │                                      [COUNSEL REVIEW]      │             │
│   │                                                            │             │
│   │  ┌ [COUNSEL REVIEW] EU withdrawal right block ┐            │             │
│   │  ┌ [COUNSEL REVIEW] Austrian KMG assessment  ┐             │             │
│   └────────────────────────────────────────────────────────────┘             │
│                                                                              │
│   [ Read the full risk & exit terms → ]                                      │
└──────────────────────────────────────────────────────────────────────────────┘
```
(A12) Single-column, 8-col width, 68ch measure. Same type scale as any other
section — the design statement is that risk gets equal typographic dignity.
No icons of warning triangles; plain sentences. No primary CTA here.

---

## Section 6 — Pricing transparency

```
┌──────────────────────────────────────────────────────────────────────────────┐
│   Every cost, on one page.  (H2)                                             │
│                                                                              │
│   ┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐       │
│   │ LICENSE      │ │ RETAINER     │ │ AD BUDGET    │ │ RESALE FEE   │       │
│   │ €500–5,000   │ │ 5–10% / mo   │ │ Optional,    │ │ 5% of resale │       │
│   │ one-time,    │ │ funds dev &  │ │ you set the  │ │ price, only  │       │
│   │ per app      │ │ operations   │ │ amount. €0   │ │ if you sell  │       │
│   │              │ │              │ │ is valid.    │ │              │       │
│   └──────────────┘ └──────────────┘ └──────────────┘ └──────────────┘ (A13) │
│                                                                              │
│   ┌────────────────────────────────────────────────────────────────┐        │
│   │            MONEY FLOW (one static SVG diagram)                 │        │
│   │                                                                │        │
│   │  YOU ──license + retainer (+ad)──▶ APPWERK ──build/operate──▶ APP │     │
│   │   ▲                                                            │        │
│   │   └── surplus (resale − arrears − 5%) ◀── MARKETPLACE ◀── relist/ │     │
│   │                                                   resale  (A14)│        │
│   └────────────────────────────────────────────────────────────────┘        │
│                                                                              │
│   No hidden fees. No revenue-share beyond the retainer. [COUNSEL REVIEW]     │
│   [ See pricing on each listing → ]                                          │
└──────────────────────────────────────────────────────────────────────────────┘
```
(A13) Four cards, equal weight — the resale fee is not buried. (A14) Diagram
is static (no autoplaying animation); strokes 1.5px ink, labels Inter 13px,
money amounts never invented — flow labels only.

---

## Section 7 — Who we are

```
┌──────────────────────────────────────────────────────────────────────────────┐
│   ┌──────────────────┐   Codemenschen, Wien.  (H2)                           │
│   │                  │                                                       │
│   │   REAL TEAM /    │   We are the Vienna software agency that validates,   │
│   │   FOUNDER PHOTO  │   builds, and operates every app on Appwerk.          │
│   │   (the only      │   [Facts: founded year, team size — real only]        │
│   │   photo on the   │                                                       │
│   │   page)   (A15)  │   — [Name], [Role]                                    │
│   └──────────────────┘                                                       │
│                          [ About us → ]   [ codemenschen.at ↗ ]              │
└──────────────────────────────────────────────────────────────────────────────┘
```
(A15) 4-col photo, 7-col text. Photo: real people, natural light, no stock.

---

## Section 8 — FAQ preview

```
┌──────────────────────────────────────────────────────────────────────────────┐
│   Questions investors actually ask  (H2)                                     │
│                                                                              │
│   ┌────────────────────────────────────────────────────────────┐             │
│   │  What exactly am I buying?                              +  │             │
│   ├────────────────────────────────────────────────────────────┤             │
│   │  What happens if the app fails?                         +  │             │
│   ├────────────────────────────────────────────────────────────┤             │
│   │  What happens if I stop paying the retainer?            +  │             │
│   ├────────────────────────────────────────────────────────────┤             │
│   │  Can I sell my license?                                 +  │             │
│   ├────────────────────────────────────────────────────────────┤             │
│   │  Do I need any technical knowledge?                     +  │             │
│   └────────────────────────────────────────────────────────────┘  (A16)      │
│                                                                              │
│   [ All questions → ]                                                        │
└──────────────────────────────────────────────────────────────────────────────┘
```
(A16) Accordion, one open at a time, 200ms height ease-out. Answers ≤3
sentences. 8-col width, centered.

---

## Section 9 — Final CTA + footer

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                       See what's available.  (Fraunces)                      │
│          License and back your own app. We build, promote, run it.           │
│                                                                              │
│                      [■ Browse available apps ]                              │
│                                                                              │
│   Licensing an app is a risk. Apps can fail and payments are not             │
│   refundable beyond your statutory rights. One buyer per app is a            │
│   marketplace rule, not a promise of value.   (14px)  [COUNSEL REVIEW] (A17) │
├──────────────────────────────────────────────────────────────────────────────┤
│  MARKETPLACE        COMPANY              LEGAL                               │
│  Apps               Über uns             Impressum                           │
│  So funktioniert's  codemenschen.at ↗    AGB · Datenschutz · Widerruf        │
│  FAQ                Kontakt                                                  │
│  ──────────────────────────────────────────────────────────────────────      │
│  © Codemenschen, Wien · risk line ·                              DE | EN     │
└──────────────────────────────────────────────────────────────────────────────┘
```
(A17) Risk footnote ≥14px, ink @70% — legible, adjacent to the CTA, never
below the footer fold. Footer on ink #0B0E14 with paper text is acceptable
alternative; default is paper with top border.

---

## Global desktop annotations

- Section spacing 120–160px; no dividers except the ink panel (§3) and footer.
- All scroll-in animations: fade + 16px rise, 300–500ms ease-out, once, honoring
  `prefers-reduced-motion`.
- No sticky CTAs on desktop (header CTA suffices), no exit-intent modals, no
  chat bubbles, no cookie-banner dark patterns.
