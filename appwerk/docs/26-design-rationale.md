# 26 — Design Rationale (Homepage, Section by Section)

**Project:** Appwerk — codemenschen.at
**Thesis:** The audience — non-technical ETF and real-estate investors — has
been trained by prospectuses and property exposés to equate *disclosure quality*
with *counterparty quality*. They are also saturated with "passive income"
hype and pattern-match it instantly as a scam signal. Therefore every design
decision on this page optimizes trust first and conversion second, on the bet
that for this audience trust IS the conversion mechanism. Hype would not just
be off-brand; it would be the single fastest way to lose exactly the people we
want.

The page is engineered as an objection-removal sequence along the fixed arc:
*didn't know this was possible → makes sense → they solved the hard part →
I don't need developers → I could license my own app → browse.*

---

## Section 1 — Hero

**Psychological purpose:** Category creation without hype. The visitor has a
mental slot for "buy an ETF" and "buy a flat" but none for "license a
validated app." The hero's job is to open that slot in one sentence and
simultaneously pass the visitor's scam-filter.

**Arc step advanced:** "I didn't know this was possible."

**Why this design serves trust first:**
- *Plain-language H1 in a serif (Fraunces):* editorial type says "publication,"
  not "landing page." Serif display is the visual register of journalism and
  annual reports — the registers this audience already trusts.
- *The risk line in the first viewport* is the strongest single trust move on
  the page. Every scam hides risk; putting "apps can fail" under the hero CTA
  is a costly signal — only an honest counterparty can afford it. It also
  pre-frames the whole page: everything after is read with lowered defenses.
- *Numbers in the subline (€500–5,000, retainer):* naming prices immediately
  signals self-serve and no-surprises; hiding prices is a sales-call pattern
  and this audience knows it.
- *One primary CTA, literally labeled:* "Browse available apps" promises only
  what the click does. No "Get started" mystery — mystery is friction for
  skeptics.
- *No hero counters, badges, or logo walls:* absence of manufactured proof is
  itself a proof for pattern-savvy visitors.

---

## Section 2 — How it works

**Psychological purpose:** Convert novelty into coherence. After the hero, the
visitor's active question is "…how would that even work?" Unanswered, that
question curdles into suspicion.

**Arc step advanced:** "OK, this actually makes sense."

**Why this design serves trust first:**
- *Exactly four steps:* a model that fits in four boxes feels legible and
  auditable — the same reason ETF fact sheets compress mechanics to a diagram.
  More steps would feel bureaucratic; fewer would feel hand-wavy.
- *Step 1 is "We validate," not "You choose":* leading with our work and our
  risk-reduction method answers "why should any of this be believable" before
  asking anything of the visitor.
- *Staggered entrance animation mirroring the sequence:* motion used as
  explanation (order, causality), not decoration — reinforcing the
  "engineered, precise" brand trait defined in doc 19.
- *Quiet text-link CTA only:* interrupting comprehension with a conversion
  button is a salesy tell; restraint here purchases credibility for the CTA
  that comes later.

---

## Section 3 — Division of labor

**Psychological purpose:** Kill the competence objection. The audience's
deepest private fear is not losing money — they already tolerate market risk —
it is *being out of their depth* ("I don't understand software; I'd be the fool
at the table").

**Arc steps advanced:** "They solved the hard part" and "I don't need
developers."

**Why this design serves trust first:**
- *Asymmetric two-column layout (long "we" list on the page's only dark ink
  panel; short "you" list on paper):* the visual weight literally enacts the
  promise — we carry the heavy side. The visitor absorbs the division of labor
  before reading a word.
- *The "you" column contains judgment tasks only (choose, fund, prioritize):*
  these are exactly the competencies an ETF/real-estate investor already
  believes they have. The design reframes them from "software novice" to
  "capital allocator" — truthfully, without ever saying "you'll be a business
  owner" (a banned claim, and rightly: they own a license, not a business).
- *No CTA:* this section is a gift of clarity, not an ask. Alternating ask/
  give builds the reciprocity rhythm that makes the Section 4 CTA feel earned.

---

## Section 4 — App listings preview

**Psychological purpose:** The pivot from abstract comprehension to concrete
desire — triggered by evidence, not adjectives. This is where "interesting
model" becomes "that one, maybe."

**Arc steps advanced:** "I don't need developers" → "I could license my own
app."

**Why this design serves trust first:**
- *Cards led by validation actuals ("214 users paid during 6-week test") in
  the reserved data-green:* the Acquire.com principle — verified numbers let
  buyers self-qualify, replacing persuasion with information. Past-tense,
  historical framing ("paid," "test revenue") keeps every figure a fact and
  keeps us structurally clear of earnings projections.
- *Prices and retainer on the card face:* nothing gated, nothing "enquire
  within" — consistent with self-serve, and a respect signal to people used to
  reading listings.
- *"One buyer per app" stated flatly, and a `Licensed` card shown at reduced
  opacity rather than hidden:* scarcity presented as inventory fact, verified
  by visible evidence. This is the entire permitted scarcity budget — factual,
  checkable, un-dramatized. A greyed sold listing is more persuasive than any
  countdown because it proves the rule exists.
- *Primary CTA reappears here:* the one place mid-page where intent genuinely
  peaks; placing the button after the evidence means clicks are informed.

---

## Section 5 — Honest risk ("What can go wrong")

**Psychological purpose:** Pass the skeptic's audit. Experienced investors
scan any offer for the risk section; its quality (or absence) decides whether
everything above gets reclassified as marketing. This section also inoculates:
a visitor who reads "apps can fail" *here* will not feel deceived later — the
foundation of long-term platform trust and low-regret buyers.

**Arc step advanced:** It defends "I could license my own app" against
collapse — the arc's load-bearing wall rather than a forward step.

**Why this design serves trust first:**
- *Full section status — same Fraunces heading scale, same whitespace, no
  fine-print styling, no warning-triangle iconography, no alarm colors:*
  typographic equality is the disclosure design. Shrinking risk type is the
  universal pattern of companies that hope you won't read it; matching the
  hero's dignity says "we want you to read this."
- *Mechanics in plain declarative sentences (suspension → relist → surplus net
  of arrears and 5% fee):* naming the ugliest scenario — *your* non-payment —
  unprompted demonstrates the system was designed for bad days, which is what
  an investor actually needs to know.
- *Visible [COUNSEL REVIEW] placeholders in staging:* even internally, the
  design treats legal accuracy as a first-class content type, not a footer
  afterthought.
- *No primary CTA, and the mobile sticky Browse bar hides while this section
  is on screen:* selling against a disclosure would convert the disclosure
  into a sales device and void it. The absence of the button is the message.

---

## Section 6 — Pricing transparency

**Psychological purpose:** Eliminate the fear of the unstated. After accepting
the risk, the last rational blocker is "what will this *actually* cost me, and
where does the money go?" Any residual ambiguity here reads as a trap.

**Arc step advanced:** Completes "I could license my own app" — the visitor
can now model the commitment end-to-end.

**Why this design serves trust first:**
- *All four money items at equal visual weight — including the 5% resale fee:*
  fee-burying is the pattern this audience has learned to hunt for (TERs,
  Maklergebühren, exit loads). Promoting the least flattering fee to equal
  billing is another costly honesty signal.
- *One static diagram containing every flow, including the suspension/resale
  return path:* Stripe's lesson — a system that can be drawn on one screen is
  a system its operator fully understands and isn't hiding. Including the
  failure path in the same calm diagram normalizes it as mechanics, not
  catastrophe.
- *"€0 is a valid choice" on ad budget:* explicitly legitimizing the
  no-upsell option proves the optional item is genuinely optional.
- *Static, drawn-once diagram (no looping pulses):* animated money loops read
  as casino; a single explanatory draw reads as engineering (doc 19 doctrine).

---

## Section 7 — Who we are

**Psychological purpose:** Attach every abstract promise to an accountable
counterparty. For Austrian and German-speaking investors especially,
"Wer steckt dahinter?" plus a findable Impressum is a threshold trust test;
anonymity is disqualifying.

**Arc step advanced:** Underwrites the whole arc — no single step forward, but
without it the "browse" decision lacks a counterparty to trust.

**Why this design serves trust first:**
- *The page's only photograph is a real team/founder photo:* by banning stock
  humans everywhere else (doc 19), the one genuine face carries concentrated
  authenticity. Stock imagery anywhere would contaminate it.
- *Vienna, named people, link out to codemenschen.at:* verifiable, local,
  jurisdictionally graspable — an Austrian GmbH with an address is something
  this audience knows how to check, unlike an offshore landing page.
- *Facts-only copy (founding year, team size), no "world-class team" adjectives:*
  the section's restraint matches the listings' data-first ethic — the brand
  never grades itself.

---

## Section 8 — FAQ preview

**Psychological purpose:** Sweep residual objections and — equally important —
demonstrate that we know what the skeptical reader is thinking. An FAQ whose
first question is "What exactly am I buying?" tells the visitor they've been
accurately modeled, which is itself reassuring.

**Arc step advanced:** Clears the final hesitations before "Let me browse."

**Why this design serves trust first:**
- *The five questions are the five hardest ones* (license-not-ownership,
  failure, non-payment, resale, no-tech-needed) — not softballs like "How do I
  sign up?" Choosing adversarial questions signals confidence in the answers.
- *≤3-sentence answers in the same plain register as the risk section:* the
  voice never changes between selling and disclosing — consistency of register
  across contexts is how a brand proves the honest voice isn't a costume.
- *Accordion with utility-only motion:* by this point the visitor is
  task-oriented; the design gets out of the way.

---

## Section 9 — Final CTA + risk footnote

**Psychological purpose:** Provide resolution. The arc ends in an action that
now feels like the visitor's own informed conclusion rather than a persuasion
outcome — the only kind of customer a retainer-based, long-relationship
business model can afford to acquire.

**Arc step advanced:** "Let me browse."

**Why this design serves trust first:**
- *Identical CTA label and destination as the hero:* third appearance, zero
  variation. One page, one action, no last-second alternative funnels — the
  consistency itself communicates that nothing was being steered.
- *Risk footnote directly under the final button, ≥14px:* the page ends the
  way it began — claim and caveat together. Bookending honesty makes it read
  as identity, not compliance. "One buyer per app is a marketplace rule, not a
  promise of value" preempts even the misreading of our own factual scarcity.
- *No email fallback, no "not ready? join the newsletter":* a self-serve
  marketplace trusts the visitor to come back on their own terms; grasping at
  the exit would contradict 8 sections of composure.

---

## Cross-cutting rationale (why the system holds together)

1. **Costly signaling as strategy.** Risk in the hero, fees at equal weight, a
   greyed sold listing, no urgency anywhere: each is expensive for a dishonest
   actor to fake and cheap for an honest one to show. The page accumulates
   these signals deliberately.
2. **Register consistency.** Selling copy, risk copy, and legal placeholders
   share one plain voice and one type system. The moment disclosure looks
   different from marketing, both become suspect.
3. **Conversion philosophy.** The funnel optimizes *informed* clicks (scroll
   depth past §5 before first CTA click is a success metric, not a failure) —
   because the product is an ongoing licensing relationship, a misled buyer is
   a guaranteed churn, arrears, and reputational cost. Trust-first is not
   ethics decoration; it is the unit economics.
4. **The arc is an objection queue.** Each section removes exactly one
   objection in the order a skeptical, non-technical investor actually raises
   them: Is this real? → Does it work? → Can I do it? → Is there proof? →
   What's the catch? → What does it cost? → Who are you? → Anything else? → OK.
