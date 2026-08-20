# 19 — Visual & Motion System

**Project:** Appwerk — codemenschen.at
**Positioning:** Restrained, fintech-premium, editorial. The visual system's
job is to make honesty look confident — every choice below is justified by
trust, not taste.

---

## 1. Color

### Palette (fixed)

| Token | Hex | Role |
|---|---|---|
| `ink` | `#0B0E14` | Primary text; the single dark surface (division-of-labor "we" panel; optionally footer) |
| `paper` | `#FAF9F6` | Page background; text on dark surfaces |
| `accent` | `#2B5BE3` | CTAs, links, focus rings, interactive states — nothing else |
| `success` | `#1A7F5A` | Validation data ONLY (paid-user counts, test revenue, retention) |
| `border` | `#E6E3DC` | Warm-gray hairlines, card borders, dividers, disabled fills |

Derived tints (only these): ink @70% (secondary text/risk notes), ink @40%
(disabled text), accent hover `#2450C9`, success @10% tint (data-chip
backgrounds if needed).

### Usage rules
1. **Accent is a scarcity budget.** If a viewport contains more than one solid
   accent element, one of them is wrong. Accent never decorates — no accent
   section backgrounds, no accent icons for flavor.
2. **Green means "this actually happened."** #1A7F5A is reserved for
   historical validation actuals. It never colors marketing copy, checkmark
   lists, or CTAs. The moment green appears on non-data content, the semantic
   is dead. (Corollary: no green on projections — there are no projections.)
3. **No gradients for decoration.** Flat fills and hairlines. A ≤2% tonal
   ramp on the ink panel is the permitted maximum, and only if flat looks
   banded on cheap displays.
4. **No additional reds/ambers for the risk section.** Risk copy is set in
   ink like everything else — using alarm colors would either dramatize or
   ghetto-ize it. Equal typography IS the disclosure design.
5. Warm gray does the separating; whitespace does the grouping. Prefer space
   over lines; prefer lines over boxes; prefer boxes over shadows. Shadows:
   one level max (cards on hover, 0 2px 8px ink @6%).

---

## 2. Typography

### Faces
- **Fraunces** (variable, optical size on) — display/headlines. Confident,
  editorial, human; the serif carries warmth so the copy can stay dry.
- **Inter** — UI, body, data. Tabular numerals (`tnum`) mandatory for all
  metrics, prices, and diagram labels.

### Scale (desktop / mobile, 1.25 modular-ish, px)

| Token | Use | Desktop | Mobile | Face / weight |
|---|---|---|---|---|
| `display` | H1 hero only | 56/1.1 | 34/1.15 | Fraunces 560, opsz auto |
| `h2` | Section headings | 36/1.2 | 26/1.25 | Fraunces 500 |
| `h3` | Card titles, step labels | 22/1.3 | 19/1.3 | Inter 600 |
| `body-lg` | Hero subline, closing lines | 19/1.6 | 17/1.6 | Inter 400 |
| `body` | Default | 17/1.65 | 16/1.65 | Inter 400 |
| `small` | Captions, chips, footnote risk | 14/1.5 | 14/1.5 | Inter 400 |
| `micro` | Hero risk line, legal refs | 13/1.5 | 13/1.5 | Inter 400, ink @70% |
| `data` | Validation figures | 22/1.2 | 19/1.2 | Inter 600 tnum, #1A7F5A |

Rules: body measure ≤68ch; headings ≤ ~24 words; no font weights above 600
except Fraunces display; no italics for emphasis in body (use plain restatement
instead); ALL-CAPS only for ≤2-word labels (`WHAT WE DO`) with +6% tracking.
Whitespace: 120–160px between desktop sections, 80–96px mobile — generosity is
part of the premium signal; never compress to fit a fold.

---

## 3. Iconography

**Direction: thin-stroke geometric. No clip-art, no filled emoji-style icons,
no 3D renders.**

- 1.5px stroke at 24px grid (scales to 1.75px at 32px), round caps/joins,
  ink color; accent only when the icon *is* the interactive element.
- Built from primitives — circles, right angles, straight connectors — echoing
  the money-flow diagram language so icons and diagrams read as one system.
- Metaphors stay literal and calm: validation = checkline within a circle,
  license = document + key outline, operations = layered rectangles, tracking
  = simple line-chart glyph. Never: rockets, unicorns, moneybags, handshakes,
  lightbulbs.
- Source: single custom set or a disciplined subset of one library (e.g.
  Lucide at fixed stroke), never mixed libraries.

---

## 4. Illustration & imagery

**Direction: abstract data/structure motifs. No stock people, no laptop-and-
coffee photography, no device mockups with invented dashboards.**

- Motifs: grids resolving into order, node-and-edge structures, layered planes
  suggesting "prototype → validated → operated", flow lines echoing the pricing
  diagram. Ink + border-gray strokes on paper; accent used for at most one
  focal line per illustration.
- Everything drawn as if from the same engineering pen: same stroke weights as
  iconography, same geometry.
- **One photograph on the homepage:** the real Codemenschen team/founder
  (Section 7). Natural light, honest environment, no stock. This scarcity makes
  the single human anchor land harder.
- App icons in listings: geometric placeholder marks until real app icons
  exist; placeholders visually tagged in design files.
- Charts of validation data (listing detail, later dashboard): follow the same
  restraint — single-series ink/success lines, no gradient area fills, labeled
  axes, real numbers only.

---

## 5. Motion

**Doctrine: subtle, engineered. Motion demonstrates precision; it never
performs excitement. If an animation would still make sense on a casino site,
it's banned here.**

### Global spec
- Entrance pattern: fade 0→1 + rise 16px (desktop) / 8px (mobile), 300–500ms,
  `cubic-bezier(0.16, 1, 0.3, 1)` ease-out, triggered at ~20% viewport entry,
  plays **once** per page load.
- Micro-interactions: 150–200ms; hovers move ≤2px; nothing loops, nothing
  autoplays, nothing bounces or springs past its target.
- **Banned:** parallax, autoplaying counters/odometers, scroll-jacking,
  marquees, typewriter effects, confetti, background video.
- `prefers-reduced-motion: reduce` → all entrances render instantly, all
  micro-interactions become opacity-only. Non-negotiable.

### Per-section concepts

| Section | Motion | Why it fits |
|---|---|---|
| 1 Hero | H1, subline, CTAs rise in one group (400ms); motif draws its strokes once (SVG line-draw, ~600ms) then holds | One confident entrance, then stillness = composure |
| 2 How it works | 4 cards rise with 60ms stagger; connector strokes draw left→right after cards settle | Sequence mirrors the process — motion as explanation |
| 3 Division of labor | Ink panel fades in first, "you" card follows 100ms later | "We carry it first" enacted temporally |
| 4 Listings | Cards rise together (no stagger — a marketplace, not a reveal); hover: border→accent + 2px lift | Data presents itself flatly; interaction confirms clickability |
| 5 Risk | **Reduced motion by design:** simple 300ms fade, no rise, no stagger | Stillness reads as seriousness; risk is never choreographed |
| 6 Pricing | Cost cards fade; flow diagram strokes draw once (800ms total) on first view — no looping pulses along the paths | Watching the money path draw itself once aids comprehension; looping would feel like a slot machine |
| 7 Who we are | Photo + text simple fade | Humans don't need effects |
| 8 FAQ | Accordion height 200ms ease-out, chevron 200ms rotate | Utility motion only |
| 9 Final CTA | Group fade/rise, same as hero | Bookend symmetry |

---

## 6. Reference sites — principles, never imitation

| Reference | What it does | WHY it earns trust (the principle we take) |
|---|---|---|
| **Acquire.com** | Marketplace listings led by verified metrics (revenue, profit) rather than adjectives | Data-forward cards let buyers self-qualify; the platform's confidence in its numbers substitutes for sales pressure. → Our listing cards lead with validation actuals and state scarcity as inventory fact. |
| **Stripe** | Dense, precise documentation-grade copy; restrained palette; diagrams that actually explain money flow | Treating the reader as intelligent signals the company has nothing to obscure; the famous payment-flow diagrams prove complexity can be made legible. → Our one-diagram pricing section and plain-sentence mechanics. |
| **Linear** | Extreme reduction: one accent, engineered micro-motion, typography does the persuading | Craft discipline implies operational discipline — visitors infer the product is built like the site. → Our motion doctrine and accent-scarcity rule. |

**Principle extraction only.** We do not copy layouts, components, easing
signatures, or copy voice. The shared underlying law: *restraint reads as
having nothing to hide.* A site selling risk-bearing licenses to cautious
ETF/real-estate investors must look like the prospectus, not the pitch deck.

---

## 7. System governance

- Tokens above are the single source of truth; name them exactly (`ink`,
  `paper`, `accent`, `success`, `border`) in code and design files.
- Any new color, weight, or motion pattern requires a written trust
  justification appended to this doc — "it looks nice" is not one.
- Placeholder assets (metrics, app icons, photos) carry a `PLACEHOLDER` tag in
  Figma and CMS until replaced by verified reality.
