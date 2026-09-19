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
- **No model experiment without asking.** A benchmark against another provider or model spends
  real generations on the owner's accounts. Ask first, naming the provider and the number of
  calls. This rule exists because on 2026-09-05 one gpt-5.5 build and one Haiku build were run
  without asking.

## Deploy

- Server checkout `/var/www/ai-factory` on `manager` (ssh port 7172), stack `infra/docker-compose.prod.yml`,
  code baked into the images. Deploy: `git pull --ff-only origin main`, then in `infra/`
  `docker compose -f docker-compose.prod.yml build --pull api horizon [web]` and
  `up -d --remove-orphans api horizon scheduler [web]`, then `exec -T api php artisan migrate --force`
  when a migration was added. Verify by grepping the new code inside the container, not by uptime.
- After every commit and push/deploy, post it to Teams with `~/.openclaw/workspace/ops/teams-commit.sh`.
- Mail goes out through the company's world4you SMTP (`smtp.world4you.com:587`, STARTTLS) as
  `developerweb@codemenschen.at`, DKIM-signed for codemenschen.at. Not Resend (its Tokyo IPs
  landed the sign-in mail in spam, 2026-09-07) and not the host's sendmail (generic rDNS, no DKIM).
  The password is in the server `.env` only; a mail says "reply to this e-mail" and that mailbox is read.

## Prototypes

- A `campaign` (2026-09-19) is not a fifth writer: `Campaign` writes one message, then builds an ads,
  a site (the landing page with a sign-up form) and an email prototype as its parts, side by side;
  it is ready when the last part is. The parts share the one free change.
- All four kinds (site, app, ads, email) write their own CSS; `house.css` only feeds `packages/design-system`.
- Photographs come from the business's own website, then the shared library, then Pexels; the
  opening picture of an ad prototype is rendered (see Models). Each slot carries
  `data-q` (2 to 4 English nouns) for the search.
- The QA gate is `apps/api/tools/qa-page.cjs`; `PageAudit::repairable()` decides what earns a repair.
- Verify a pipeline change with one real build of the affected kind and look at it rendered
  before reporting done. The Browser pane cannot screenshot the raw URL (CSP); serve the file from
  the scratchpad `qa/` dir with the `proto-look` launch config instead.
