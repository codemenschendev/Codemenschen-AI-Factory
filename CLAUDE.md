# Appwerk (Codemenschen AI Factory)

## Models: who does what

- **Text, code, prototypes, ad copy: Claude only**, through OpenClaw. `openclaw/main` is Sonnet 5
  on claude-cli. `AI_CHAT_BACKEND_MODEL` stays empty in production; only the owner changes it.
- **Haiku 4.5 is the chat agents' model** in the OpenClaw config (Teams and the other chat
  agents). It is not a faster prototype writer: measured 2026-09-05, Sonnet 5 and Haiku 4.5 both
  write a prototype at ~30 tokens/s through claude-cli. Do not point generation at it.
- **OpenAI is for image rendering only** (the gpt-image path in the ad pipeline). Never for text.
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

## Prototypes

- All three kinds (site, app, ads) write their own CSS; `house.css` only feeds `packages/design-system`.
- Photographs come from the shared library, then Pexels, never generated. Each slot carries
  `data-q` (2 to 4 English nouns) for the search.
- The QA gate is `apps/api/tools/qa-page.cjs`; `PageAudit::repairable()` decides what earns a repair.
- Verify a pipeline change with one real build of the affected kind and look at it rendered
  before reporting done. The Browser pane cannot screenshot the raw URL (CSP); serve the file from
  the scratchpad `qa/` dir with the `proto-look` launch config instead.
