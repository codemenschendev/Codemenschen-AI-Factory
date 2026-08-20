# AI Factory

"Choose an app. Pay. We build, publish and market it." — see [PLAN.md](PLAN.md)
for the full architecture and roadmap.

## Layout

| Path | What |
|---|---|
| `apps/web` | Next.js storefront + customer portal (appwerk.codemenschen.at) |
| `apps/api` | Laravel Factory API — orders, projects, pipeline orchestrator (api.appwerk.codemenschen.at) |
| `packages/pricing` | Shared deterministic pricing engine (ported from the appwerk prototype) |
| `workers/pipeline` | Claude Agent SDK stage worker (product / uiux / coding / test / fix / release) |
| `templates/expo-app` | Golden template forked per customer project |
| `infra/` | docker-compose for the production server + Apache vhosts |
| `appwerk/` | Original static prototype + strategy docs (reference; being ported) |

## Dev quickstart

```bash
npm install                 # workspaces: web, pricing, pipeline worker
npm run test:pricing
npm run dev:web             # http://localhost:3000

cd apps/api
composer install && cp .env.example .env && php artisan key:generate
php artisan serve           # http://localhost:8000
```

## Deploy

See [infra/DEPLOY.md](infra/DEPLOY.md). Production host: codemenschen.at server
(Apache reverse-proxy → Docker services on loopback ports).
