#!/usr/bin/env bash
# Sync the working tree to the codemenschen.at host and rebuild the given
# compose services (default: all). Secrets (infra/.env, apps/api/.env),
# customer repos, the worker home and artifacts on the host are never touched.
#
#   infra/deploy.sh                 # everything
#   infra/deploy.sh worker web      # only these compose services
set -euo pipefail
HOST=${DEPLOY_HOST:-root@65.108.206.249}
PORT=${DEPLOY_PORT:-7172}
DEST=${DEPLOY_PATH:-/var/www/ai-factory}
cd "$(dirname "$0")/.."

rsync -az -e "ssh -p $PORT" \
  --exclude .git --exclude node_modules --exclude vendor --exclude .next \
  --include '.env.example' --exclude '.env' --exclude '.env.*' \
  --exclude infra/repos --exclude infra/worker-home --exclude infra/artifacts \
  --exclude 'apps/api/storage/logs' --exclude 'apps/api/storage/framework' \
  --exclude appwerk --exclude '.DS_Store' \
  ./ "$HOST:$DEST/"

ssh -p "$PORT" "$HOST" "cd $DEST/infra \
  && docker compose -f docker-compose.prod.yml up -d --build $* \
  && docker compose -f docker-compose.prod.yml ps --format 'table {{.Name}}\t{{.Status}}'"
