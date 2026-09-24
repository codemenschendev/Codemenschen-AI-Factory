# Appwerk (Codemenschen AI Factory)

## Models: who does what

- **Text, code, prototypes, ad copy: Claude only**, through OpenClaw. `openclaw/appwerk` is Sonnet 5
  on the tool-less `claude-cli-chat` backend (5k tokens of context per call; `openclaw/main`, the full agent
  runtime, was 50k and wandered into tool calls). Never point generation back at `openclaw/main`. Code stages (coding, fix, revise) go through the host relay to
  `appwerk-code` (tools, trimmed workspace), one session per run. `AI_CHAT_BACKEND_MODEL` stays empty in production; only the owner changes it.
- **Haiku 4.5 is the chat agents' model** in the OpenClaw config (Teams and the other chat
  agents). It is not a faster prototype writer: measured 2026-09-05, Sonnet 5 and Haiku 4.5 both
  write a prototype at ~30 tokens/s through claude-cli. Do not point generation at it.
- **OpenAI is for image rendering only**. Never for text. Two paths: the metered gpt-image API
  (paid ads) and the image agent `infra/imagegen`, Codex CLI on the ChatGPT (Codex) subscription,
  read-only, no shell (owner's decision 2026-09-17, a quality test before any paid use). It renders
  the scenes of an ad prototype's story and square (`AI_IMAGE_PROTOTYPE_RENDERS=2`) while Claude
  writes the page, and the business's own product picture is laid into each; the picture holds no text.
  The ad prototype has three modes, switched by the owner in the admin panel (setting `ads.mode`,
  2026-09-19): `hybrid` (default, the above), `claude` (no render) and `codex`, where Codex gets the
  customer's words and designs the whole creative, text included. `codex` is the one place OpenAI
  sets words, and only because the owner chose to test it; it never writes text for Claude's pages.
  `App\Domain\Ai\ChatBackend` refuses an OpenAI chat backend before a request is sent; keep every
  `x-openclaw-model` header going through it.
- **Paid renders on the owner's OpenAI key** (2026-09-24): with a key entered in the admin panel
  (`OpenAiImageKey`, stored encrypted, never read back), a paying customer's ad pictures
  (`RenderProjectAd` -> `ImageService::generate`) go straight to the OpenAI Images API on it; no key,
  or a failing key, falls back to the sidecar. Free prototypes stay on Codex and never use the key.
- **No model experiment without asking.** A benchmark against another provider or model spends
  real generations on the owner's accounts. Ask first, naming the provider and the number of
  calls. This rule exists because on 2026-09-05 one gpt-5.5 build and one Haiku build were run
  without asking.

## Deploy

- Deploy only through the central runner: `~/.openclaw/workspace/ops/deploy.sh ai-factory` (registry row,
  method `server`). It runs the dispatcher `server-deploy.sh ai-factory` on `manager`, which calls
  `infra/deploy-server.sh`: git sync, compose build of every service, migrate, and it refuses while a pipeline run is active
  (`--force` overrides). Never hand-run `docker compose build/up` on the server: that is a side door
  (a hand-run compose caused a 502 on manager, 2026-09-24). Server checkout `/var/www/ai-factory`,
  stack `infra/docker-compose.prod.yml`. Verify by grepping the new code inside the container, not by uptime.
- After every commit and push/deploy, post it to Teams with `~/.openclaw/workspace/ops/teams-commit.sh`.
- Mail goes out through the company's world4you SMTP (`smtp.world4you.com:587`, STARTTLS) as
  `developerweb@codemenschen.at`, DKIM-signed for codemenschen.at. Not Resend (its Tokyo IPs
  landed the sign-in mail in spam, 2026-09-07) and not the host's sendmail (generic rDNS, no DKIM).
  The password is in the server `.env` only; a mail says "reply to this e-mail" and that mailbox is read.

- **Paid renders run at `AI_IMAGE_QUALITY=low`** in production (owner's decision 2026-09-21, to save
  money). The config default stays `medium`; the server env is what decides.

## Ad spend

- Nothing spends without a person pressing start, and `SpendGuard` stands behind that (2026-09-19):
  platform limits set at publish (Meta lifetime budget + end time, spend cap from 100 EUR), the guard's
  checks before a start (kill switch, max per campaign, max per day for all running ads, settings
  `ads.*` in the admin overview), and `factory:ads-guard` every 15 minutes, which pauses any campaign
  over its total, far over its day, or past its end. A Google campaign budget is per DAY.

## Prototypes

- A `campaign` (2026-09-19) is not a fifth writer: `Campaign` writes one message, then builds an ads,
  a site (the landing page with a sign-up form) and an email prototype as its parts, side by side;
  it is ready when the last part is. The parts share the one free change.
- The campaign's landing page goes live at `api.appwerk…/l/{id}` when its owner switches it on
  (`LandingController`, 30 days). The sign-up form is taken over by a script added when the page is served;
  the waitlist is double opt-in (`landing_signups` is the consent record) and every mail has a one-click delete.
- All four kinds (site, app, ads, email) write their own CSS; `house.css` only feeds `packages/design-system`.
- Photographs come from the business's own website, then the shared library, then Pexels; the
  opening picture of an ad prototype is rendered (see Models). Each slot carries
  `data-q` (2 to 4 English nouns) for the search.
- The QA gate is `apps/api/tools/qa-page.cjs`; `PageAudit::repairable()` decides what earns a repair.
- Verify a pipeline change with one real build of the affected kind and look at it rendered
  before reporting done. The Browser pane cannot screenshot the raw URL (CSP); serve the file from
  the scratchpad `qa/` dir with the `proto-look` launch config instead.
