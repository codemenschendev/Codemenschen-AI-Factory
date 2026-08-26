#!/usr/bin/env bash
#
# deploy-server.sh — runs ON the server in /var/www/ai-factory (git checkout).
#
#   infra/deploy-server.sh                 # sync origin/main, rebuild every service, migrate
#   infra/deploy-server.sh api horizon     # only these compose services
#   infra/deploy-server.sh --force worker  # rebuild even while a pipeline stage is running
#   infra/deploy-server.sh --skip-pull     # deploy the checked-out commit as is
#
# The host-wide `server-deploy.sh ai-factory` calls this after its own git
# sync. A worker rebuild kills the stage that is running inside it, so the
# script refuses while pipeline runs are queued/running unless --force.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

FORCE=0; SKIP_PULL=${SKIP_PULL:-false}; SERVICES=()
for arg in "$@"; do
  case "$arg" in
    --force) FORCE=1 ;;
    --skip-pull) SKIP_PULL=true ;;
    --*) echo "unknown option $arg" >&2; exit 1 ;;
    *) SERVICES+=("$arg") ;;
  esac
done

if [[ -d .git && "$SKIP_PULL" != "true" ]]; then
  git config --global --add safe.directory "$ROOT" >/dev/null 2>&1 || true
  git fetch -q --prune origin main
  git reset -q --hard origin/main
fi
echo "==> $(git rev-parse --short HEAD 2>/dev/null || echo 'no git') $(git log -1 --format=%s 2>/dev/null)"

DC="docker compose -f infra/docker-compose.prod.yml"

if [[ $FORCE -eq 0 ]]; then
  inflight=$($DC exec -T api php artisan tinker --execute 'echo App\Models\PipelineRun::whereIn("status", ["queued","running"])->count();' 2>/dev/null | tail -1 | tr -dc '0-9' || echo 0)
  if [[ "${inflight:-0}" != "0" ]]; then
    echo "!! $inflight pipeline run(s) queued/running — a rebuild would kill them. Wait, or pass --force." >&2
    exit 2
  fi
fi

echo "==> Building + starting ${SERVICES[*]:-all services} ..."
$DC up -d --build --remove-orphans "${SERVICES[@]}"
echo "==> Migrating ..."
$DC exec -T api php artisan migrate --force
$DC exec -T api php artisan optimize:clear >/dev/null
$DC ps --format 'table {{.Name}}\t{{.Status}}'
curl -fsS -m 10 http://127.0.0.1:8181/api/health && echo
