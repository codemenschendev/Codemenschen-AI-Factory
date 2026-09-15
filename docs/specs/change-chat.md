# Spec: Change chat in the customer portal

Status: draft for Patrick, 2026-09-15. Step 2 of "customers work with Appwerk the way the team works
in Buzz". Step 1 (team-internal Buzz) is running. Step 3 (paid tier with more rounds) follows later.

## 1. Why

Today a customer who wants a change to their app gets one text box on the project page
(`ProjectDetail.tsx`), an optional "refine" button, and then a wait with a status that polls every
20 seconds. What they wanted and what the agent built only meet again in a one-line summary.

Three things go wrong with that:

- A vague request spends a whole round. The customer has 3 free rounds, and a paid round is €39.
- Nobody talks back. The refine questions are one step, not a conversation, and a declined
  request (`out_of_scope`) shows as a short line in a history list.
- The customer cannot see the work. Minutes pass with "FIXING" and nothing else.

The chat keeps the pipeline we already have (revise, test, release, preview, approval) and puts a
conversation around it: talk until the change is clear, confirm what gets built, watch it happen,
answer the result in the same place.

## 2. Principles

1. **A round is one confirmed change package, not a message.** Talking is free. Only pressing
   "Umsetzen" on a summary card starts a round.
2. **The customer confirms the exact text the agent gets.** The assistant writes the brief, the
   customer sees it and says yes. No hidden reinterpretation between chat and build.
3. **The gates stay.** Tests must pass, the customer approves the preview, a store release still
   goes through the existing approval. The chat adds no shortcut to production.
4. **Customer text is data, never an instruction.** It never reaches a model that has tools
   without the scope rules around it.
5. **Scope is the paid spec.** Bug fixes, text, layout and small UX changes are in. New features
   and integrations are out and get an honest answer plus a way to ask for a quote.

## 3. Not in this step

- No live agent on the customer's code. The build still runs as the revise stage in the worker.
- No streaming of code or terminal output to the customer.
- No Buzz for customers, no chat outside the portal, no voice.
- No new features through chat. No change to prices or round counts (see open questions).
- Screenshots in the chat are phase 2b, not 2a.

## 4. The flow

States of one conversation thread per project:

```
drafting ──confirm──▶ awaiting_payment ──paid──▶ building ──▶ result
    ▲                    (paid mode only)            │          │
    └───────────── "weitere Änderung" ◀─────────────┴──────────┘
                                         declined / failed also end here
```

Example (German customer, preview in REVIEW, round 1 of 3 used):

> **Kunde:** Die Buchung soll anders aussehen und der Button ist zu klein
>
> **Appwerk:** Gern. Zwei kurze Fragen, damit die Runde sitzt:
> [Nur der Button "Termin buchen"] [Die ganze Buchungsseite]
> Wie soll es aussehen? Zum Beispiel größer, andere Farbe, andere Position.
>
> **Kunde:** Nur der Button, größer und in unserem Grün, ganz unten fixiert
>
> **Appwerk:** *Zusammenfassung für Runde 2 von 3 (kostenlos)*
> 1. Button "Termin buchen" auf der Buchungsseite größer, mindestens 48 px hoch
> 2. Farbe Markengrün #0A5C2B
> 3. Button bleibt beim Scrollen unten am Bildschirm sichtbar
> [Umsetzen] [Noch etwas ändern]
>
> **Kunde:** *drückt Umsetzen*
>
> **Appwerk:** Wird umgesetzt. Dauert meist 3 bis 6 Minuten.
> ● Änderung umsetzen ○ Tests ○ Vorschau bauen
>
> **Appwerk:** Fertig. Das wurde geändert:
> ✓ Button größer (52 px) ✓ Markengrün ✓ Unten fixiert
> [Vorschau öffnen] [Freigeben] [Weitere Änderung]

Other endings in the same thread:

- **Declined** (`out_of_scope`): "Das ist eine neue Funktion und nicht Teil Ihres Pakets. Diese
  Runde wurde nicht verbraucht." plus [Angebot anfragen].
- **Failed** (revise retries exhausted): "Das hat technisch nicht geklappt. Die Runde wurde nicht
  verbraucht, unser Team schaut sich das an." Operators get an alert in #appwerk-alerts.
- **Paid mode**: the summary card shows "Runde 4, 39 €" or "inklusive mit Care", with the FAGG
  waiver checkbox on the card. [Bezahlen und umsetzen] opens Stripe Checkout; after the webhook
  the thread says "Zahlung erhalten, wird umgesetzt."

While a round builds, the customer can keep writing. Those messages start the next draft, which
can be confirmed only once the running round has ended (one build at a time, as today).

## 5. What changes, by part

### 5.1 Data

New table `change_messages`:

| column | notes |
|---|---|
| id, project_id | project owns the thread |
| change_request_id | null while drafting; set when the draft is confirmed |
| role | `customer`, `assistant`, `system` (progress, payment), `operator` |
| body | text, max 2000 chars for customers |
| meta | json: questions with options, summary card items, stage step, links |
| created_at | |

`change_requests` gains:

- `items` json: the confirmed checklist, `[{text}]`.
- `result_items` json: what the agent reports per item, `[{text, done, note}]`.

`change_requests.text` stays the confirmed brief (numbered items), so the revise stage and admin
keep working unchanged.

### 5.2 Assistant (clarifying, before a round)

- Extends `Refiner` mode `change` into a multi-turn call. Same worker endpoint family, same
  `ChatBackend` rules: Claude through OpenClaw, no tools, no repo access.
- Input: the last 20 messages of the draft, the paid features (`quote.features`), SPEC.md
  headings, the last 3 change requests with their results, project status and round mode.
- Output JSON: `{reply, questions: [{q, options[]}], brief: null | {items[]}, scope: in|borderline|out, reason}`.
- The assistant proposes a summary card only when every item is concrete enough to test. It
  asks at most 2 questions per turn, answers in the customer's language (de or en), and writes no
  em dashes.
- `scope: out` gives the declined answer before any round starts, so most out-of-scope requests
  never reach the worker.
- Prompt lives in `apps/api/resources/prompts/change/assistant.md`, like the other prompts.

Limits (replace the refine limits for signed-in customers):

- 40 assistant replies per customer per day, 20 per draft. At the limit: "Für heute ist das
  Kontingent erreicht. Ihre Nachricht ist gespeichert, unser Team meldet sich."
- Global breaker stays at 500 per day.

### 5.3 Build (unchanged pipeline, two additions)

- `requestChanges` is called with the confirmed brief. Modes, rounds, payment, FAGG and Care
  logic stay as in `PipelineOrchestrator`.
- The revise prompt asks for `{done, summary, items: [{text, done, note}]}`. Items the agent did
  not do are shown as not done, never hidden. The summary goes through the dash check before it
  is stored (the en dash seen on 2026-09-14).

### 5.4 Progress in the thread

- From `stage_runs`: revise → "Änderung umsetzen", test and fix → "Tests", release → "Vorschau
  bauen". A fix attempt shows as "Tests (zweiter Versuch)".
- The portal polls every 5 seconds while the project is FIXING or TESTING, 20 seconds otherwise.
- Time estimate from the median of the last 20 revise rounds, shown as a range.

### 5.5 Portal UI

- `ProjectDetail.tsx`: the change form is replaced by a "Änderungen" chat panel. Desktop: right
  column next to the phone preview. Mobile: a tab, full screen.
- Old change requests appear as closed threads, read-only.
- Summary card, questions as tap targets, progress steps and result checklist are message types
  rendered from `meta`, not free text.
- Approve stays the existing `approve-review` call, offered as a button on the result message.

### 5.6 Operators

- Admin panel, project view: the full thread, with a field to reply as "Codemenschen Team".
- "Assistent pausieren" per project: customer messages are stored but get no AI reply until an
  operator resumes it. Use for angry customers, legal questions, anything unclear.
- #appwerk-alerts gets: declined rounds, failed rounds, customers hitting the daily limit, and any
  draft where the assistant marked `borderline` twice.
- First 20 confirmed rounds after launch: an operator sees each brief in Buzz before the build
  starts, with a 10 minute window to hold it. After that, alerts only.

### 5.7 Mail

- Existing REVIEW mail covers "result ready".
- New: a mail when a round ends declined or failed and the project does not return to REVIEW
  (today those send nothing).
- New: a mail when an operator replies and the customer has not opened the portal for 15 minutes.
  Mails link to the thread and say to answer in the portal.

## 6. API

| method and path | purpose |
|---|---|
| `GET /me/projects/{p}/messages?after=` | thread since a message id |
| `POST /me/projects/{p}/messages` | customer message; returns the assistant reply synchronously (max 60 s) |
| `POST /me/projects/{p}/messages/confirm` | confirm the open summary card; body `fagg_waiver` in paid mode; creates the change request |
| `GET /admin/projects/{p}/messages` | operator view |
| `POST /admin/projects/{p}/messages` | operator reply |
| `POST /admin/projects/{p}/assistant` | pause or resume the assistant |

The existing `change-requests` and `change-requests/refine` endpoints stay for one release, then go.

## 7. Security and privacy

- The clarifying model has no tools. The revise agent gets the brief inside the scope prompt and
  must treat it as a description of a change, not as commands. To verify before build: the revise
  agent's working directory holds no secrets and cannot reach other customers' repos.
- A customer can read and write only their own project's thread (same `customer_id` check as today).
- Messages are personal data. Retention: kept while the project exists, deleted with the account.
  [LEGAL REVIEW] Datenschutzerklärung needs a line on chat messages and AI processing.
- No customer message is sent to OpenAI (image service only renders pictures).

## 8. Acceptance criteria

1. A customer in REVIEW with free rounds left can write, answer a question by tap, confirm a
   summary card and see the round start, without leaving the project page.
2. Talking without confirming never changes `revision_rounds` and never creates a change request.
3. The change request text equals the confirmed card items, verbatim.
4. In paid mode the card shows €39 and the FAGG checkbox; confirm without the checkbox is refused.
5. While building, progress steps update within 10 seconds of a stage run changing.
6. The result message lists every confirmed item with done or not done.
7. An out-of-scope request answered by the assistant creates no change request.
8. A failed round posts to #appwerk-alerts and tells the customer the round was not used.
9. A customer cannot read another project's thread (403/404 test).
10. At 40 assistant replies in a day the customer gets the limit message and the message is stored.
11. An operator reply shows in the customer's thread within one poll.
12. No customer-facing text produced by the chat contains an em dash (test on stored messages).

## 9. Metrics

Time from first message to confirmed round, rounds per project, declined share, failed share,
assistant replies per round, preview approvals after the first round, tokens per round.

## 10. Phases and estimate

- **2a** (about 6 to 8 dev days): table and API, assistant prompt with limits, summary card and
  confirm, progress in thread, result checklist, operator read and reply, alerts, tests.
- **2b** (about 3 to 4 days): screenshots in messages (sent to the assistant and to revise),
  assistant pause, first-20 hold window, the two new mails, removal of the old form.

Estimates, not promises. They assume the revise stage and Stripe flow stay as they are.

## 11. Open questions for Patrick

1. **Claude account for customer traffic.** The pipeline and the assistant run on the Max
   subscription through OpenClaw. A chat that paying customers use all day is customer-facing
   product traffic. Check the Anthropic terms and decide whether this moves to an API key with
   usage billing before launch.
2. **Do declined and failed rounds count?** Today a price-0 change request counts against the 3
   free rounds even when it ended `out_of_scope` or `failed`. Proposal: they do not count.
3. **Refunds.** A paid round that fails is flagged `refund_needed` and refunded by hand. Keep it
   manual, or refund automatically through Stripe?
4. **Hours.** Should the assistant say when a human is available (for example weekdays 8 to 17),
   or answer around the clock with operators on alerts only?
5. **The hold window** for the first 20 rounds: who watches it, Patrick or the dev?
