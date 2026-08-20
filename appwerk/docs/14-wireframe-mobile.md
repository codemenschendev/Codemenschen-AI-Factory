# 14 — Mobile Wireframes (Homepage)

**Project:** Appwerk — codemenschen.at
**Canvas assumptions:** 390px reference viewport, single column, 20px side
padding, 80–96px between sections. Same content as desktop — nothing is hidden
on mobile, especially not risk copy.

Legend: `[■ ...]` = primary button (accent, full-width, 52px min height) ·
`[...]` = text link · `▢` = thin-stroke icon · `(M#)` = annotation.

---

## Stacking order (global rule)

Within every section, the mobile stack is:
**heading → body → data/visual → CTA.**
CTAs always come *after* the content that justifies them — never a button the
user reaches before understanding what it does. Desktop side-by-side layouts
stack left-column-first (text before visual), except Section 7 where the photo
leads (human anchor works better before the text on small screens).

Full page order (unchanged from desktop): Header → 1 Hero → 2 How it works →
3 Division of labor → 4 Listings → 5 Risk → 6 Pricing → 7 Who we are →
8 FAQ → 9 Final CTA → Footer.

---

## Thumb-zone CTA policy (M0)

- All tappable CTAs are full-width, 52px min height, placed at the *bottom* of
  their section — the natural resting thumb zone as the user finishes reading.
- One **sticky bottom bar** may appear: `[■ Browse available apps]`, 64px,
  paper bg + top border #E6E3DC. It appears only after the user scrolls past
  Section 4 (listings) — i.e., after the offer is concrete — and it hides
  while Section 5 (risk) is in view, so the risk section is never visually
  footered by a sales button. Reappears from Section 6 onward.
- No floating round buttons, no chat bubbles competing for the thumb zone.
- Tap targets ≥44×44px everywhere; accordion rows full-bleed tappable.

---

## Header + menu

```
┌────────────────────────────┐        Menu sheet (full screen, paper):
│ APPWERK        [Apps] [☰]  │        ┌────────────────────────────┐
└────────────────────────────┘        │  Apps                      │
 (M1)                                 │  So funktioniert's         │
                                      │  Über uns                  │
                                      │  FAQ                       │
                                      │  ─────────────             │
                                      │  DE | EN                   │
                                      │  Impressum · AGB ·         │
                                      │  Datenschutz               │
                                      │  [■ Browse available apps] │
                                      └────────────────────────────┘
```
(M1) 56px header. Compact `Apps` text button keeps the primary destination one
tap away without a full button. Sheet CTA sits in the thumb zone.

---

## Section 1 — Hero

```
┌────────────────────────────┐
│ License an app that real   │  H1 Fraunces ~34px
│ users already paid for.    │
│                            │
│ Codemenschen validates     │  Inter 17px
│ prototype apps with paying │
│ test users. You license    │
│ one exclusively — €500 to  │
│ €5,000 plus a monthly      │
│ retainer — and we build,   │
│ promote, and run it.       │
│                            │
│ [■ Browse available apps ] │  (M2) full-width, thumb zone
│ [ How it works ↓ ]         │  centered text link below
│                            │
│ Apps can fail. Licensing   │  13px, ink @70%
│ is a risk, not a           │  [COUNSEL REVIEW]
│ guaranteed income.    (M3) │
└────────────────────────────┘
```
(M2) Primary above secondary — thumb hits primary first from bottom of copy.
(M3) Risk line stays in the hero block; the decorative motif is **dropped on
mobile** (content over decoration), not the risk line. Everything above must
fit ~1.25 viewports max; risk line may sit just below the first fold but
always above Section 2.

---

## Section 2 — How it works

```
┌────────────────────────────┐
│ How it works               │
│                            │
│ ┌────────────────────────┐ │
│ │ 1 ▢ We validate        │ │   (M4) vertical steps,
│ │   Real users pay real  │ │   number + icon inline,
│ │   money in a test.     │ │   1px left rule connects
│ └───────────┬────────────┘ │   steps (quiet timeline)
│ ┌───────────┴────────────┐ │
│ │ 2 ▢ You license        │ │
│ │   Exclusive. One buyer │ │
│ │   per app. €500–5,000. │ │
│ └───────────┬────────────┘ │
│ ┌───────────┴────────────┐ │
│ │ 3 ▢ We build & operate │ │
│ └───────────┬────────────┘ │
│ ┌───────────┴────────────┐ │
│ │ 4 ▢ You track          │ │
│ │   everything           │ │
│ └────────────────────────┘ │
│                            │
│ [ See the full process → ] │
└────────────────────────────┘
```
(M4) NOT a horizontal swipe carousel — swiping hides steps; vertical scroll
shows all four with zero interaction cost.

---

## Section 3 — Division of labor

```
┌────────────────────────────┐
│ You never need developers. │
│                            │
│ ┌────────────────────────┐ │
│ │ WHAT WE DO   (ink bg)  │ │  (M5) "we" panel first —
│ │ ▢ Design & development │ │  the reassurance leads
│ │ ▢ Hosting, security,   │ │
│ │   updates              │ │
│ │ ▢ App-store submission │ │
│ │ ▢ Marketing & ads      │ │
│ │ ▢ Support & monitoring │ │
│ │ ▢ Transparent reports  │ │
│ └────────────────────────┘ │
│ ┌────────────────────────┐ │
│ │ WHAT YOU DO  (border)  │ │  visibly shorter card —
│ │ ▢ Choose an app        │ │  the asymmetry survives
│ │ ▢ Fund it              │ │  stacking (M6)
│ │ ▢ Decide roadmap       │ │
│ │   priorities           │ │
│ └────────────────────────┘ │
│                            │
│ "You never write code,     │
│  hire anyone, or manage    │
│  a team."                  │
└────────────────────────────┘
```
(M6) Do not equalize card heights — the short "you" card is the point.

---

## Section 4 — App listings preview

```
┌────────────────────────────┐
│ Apps available now         │
│ Real results from paid     │
│ validation tests — not     │
│ projections.               │
│                            │
│ ┌────────────────────────┐ │
│ │ ▢ FitPilot             │ │
│ │   Health · iOS         │ │
│ │ ────────────────────── │ │
│ │ 214 users paid during  │ │  (M7) PLACEHOLDER data;
│ │ 6-week test            │ │  figures in #1A7F5A
│ │ €1,840 test revenue    │ │
│ │ ────────────────────── │ │
│ │ License €2,400         │ │
│ │ Retainer 7%/mo         │ │
│ │ ● Available            │ │
│ └────────────────────────┘ │
│ ┌────────────────────────┐ │
│ │ ▢ RentLog   … (card 2) │ │  (M8) vertical stack of 3,
│ └────────────────────────┘ │  full cards — no horizontal
│ ┌────────────────────────┐ │  card swiper
│ │ ▢ MealSync ○ Licensed  │ │  licensed card at 60% opacity
│ └────────────────────────┘ │
│                            │
│ Each app is licensed       │
│ exclusively to one person. │
│ Resale on-platform only    │
│ (5% fee).                  │
│                            │
│ [■ Browse all available    │  (M9) full-width, thumb zone;
│    apps ]                  │  sticky bar activates after
└────────────────────────────┘  this point (see M0)
```

---

## Section 5 — Honest risk

```
┌────────────────────────────┐
│ What can go wrong          │  same H2 size as all others
│                            │
│ · The app can fail.        │
│   Validation reduces risk; │
│   it does not remove it.   │
│   You can lose your        │
│   license fee and retainer │
│   payments.                │
│ · Test results may not     │
│   continue or grow.        │
│ · You buy a license — not  │
│   ownership, IP, or        │
│   equity. [COUNSEL REVIEW] │
│                            │
│ IF PAYMENTS STOP           │
│ Suspension → relisting →   │
│ surplus returned net of    │
│ arrears and 5% fee.        │
│ [COUNSEL REVIEW]           │
│                            │
│ [ Full risk & exit terms →]│  (M10)
└────────────────────────────┘
```
(M10) Sticky Browse bar is HIDDEN while this section is in view (M0 rule).
Risk copy is never collapsed behind a "read more" on mobile.

---

## Section 6 — Pricing transparency

```
┌────────────────────────────┐
│ Every cost, on one page.   │
│                            │
│ ┌────────────────────────┐ │
│ │ LICENSE   €500–5,000   │ │  (M11) four rows, equal
│ │ one-time, per app      │ │  weight — resale fee not
│ ├────────────────────────┤ │  demoted on mobile
│ │ RETAINER  5–10%/mo     │ │
│ ├────────────────────────┤ │
│ │ AD BUDGET optional, €0 │ │
│ │ is valid               │ │
│ ├────────────────────────┤ │
│ │ RESALE FEE 5%, only if │ │
│ │ you sell               │ │
│ └────────────────────────┘ │
│                            │
│ ┌────────────────────────┐ │
│ │  MONEY FLOW diagram    │ │  (M12) vertical variant of
│ │  YOU                   │ │  the SVG — redrawn for
│ │   │ license+retainer   │ │  portrait, not squeezed;
│ │   ▼                    │ │  pinch-zoom not required
│ │  APPWERK ─▶ APP        │ │
│ │   ▲                    │ │
│ │   │ surplus − arrears  │ │
│ │   │ − 5%               │ │
│ │  MARKETPLACE           │ │
│ └────────────────────────┘ │
│ [ See pricing on each      │
│   listing → ]              │
└────────────────────────────┘
```

---

## Sections 7–9 (compact)

```
┌────────────────────────────┐   ┌────────────────────────────┐
│ ┌────────────────────────┐ │   │ Questions investors        │
│ │  TEAM PHOTO (real)     │ │   │ actually ask               │
│ └────────────────────────┘ │   │ ┌────────────────────────┐ │
│ Codemenschen, Wien.        │   │ │ What exactly am I    + │ │
│ The Vienna agency that     │   │ │ buying?                │ │
│ validates, builds, and     │   │ ├────────────────────────┤ │
│ operates every app.        │   │ │ …4 more accordions     │ │
│ — [Name], [Role]           │   │ └────────────────────────┘ │
│ [ About us → ]             │   │ [ All questions → ]        │
└────────────────────────────┘   └────────────────────────────┘

┌────────────────────────────┐
│   See what's available.    │
│                            │
│ [■ Browse available apps ] │  (M13) final CTA in thumb
│                            │  zone; sticky bar suppressed
│ Licensing an app is a      │  here (redundant)
│ risk. Apps can fail…       │
│ [COUNSEL REVIEW]  (≥14px)  │
├────────────────────────────┤
│ Footer: nav links stacked, │
│ legal links, DE|EN, ©      │
└────────────────────────────┘
```

---

## Global mobile annotations

- Type scale drops one step (see doc 19); body never below 16px; risk footnotes
  never below 14px.
- Animations halved in distance (8px rise) and honoring `prefers-reduced-motion`.
- No content parity exceptions except the hero decorative motif (dropped).
- Nothing load-bearing inside hover states — all information visible or tappable.
