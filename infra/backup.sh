#!/usr/bin/env bash
# Nightly backup of what Appwerk cannot regenerate: the Postgres database (customers,
# orders, projects, prototypes) and the artifacts volume (source bundles, builds, previews).
# Everything else (images, code, the design library on /var/lib) is rebuilt or checked in.
#
# Runs from root's crontab on the host: 45 3 * * * /var/www/ai-factory/infra/backup.sh
# Keeps 14 nights. A dump is gzip'd SQL, restorable with: gunzip -c db-<date>.sql.gz | psql.
set -euo pipefail

COMPOSE="docker compose -f /var/www/ai-factory/infra/docker-compose.prod.yml"
DEST=/var/backups/ai-factory
KEEP_DAYS=14
STAMP=$(date +%Y%m%d-%H%M)

ALERTS=/var/lib/ai-factory/alerts

# A backup that fails at 03:45 is only worth something if somebody hears about it before the
# day they need it: the same drop folder Notify uses, posted to Buzz #appwerk-alerts.
alert() {
  [ -d "$ALERTS" ] || return 0
  local name
  name="$(date +%Y%m%d-%H%M%S)-backup.json"
  printf '{"message":%s}\n' "$(printf 'Appwerk: nightly backup failed. %s See /var/log/ai-factory-backup.log on manager.' "$1" \
    | python3 -c 'import json,sys;print(json.dumps(sys.stdin.read()))')" > "$ALERTS/.$name" && mv "$ALERTS/.$name" "$ALERTS/$name"
}
trap 'alert "Step failed at line $LINENO."' ERR

mkdir -p "$DEST"
chmod 700 "$DEST"

# The database, dumped inside the container with its own credentials (never on the command line).
$COMPOSE exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner' \
  | gzip -6 > "$DEST/db-$STAMP.sql.gz.part"
mv "$DEST/db-$STAMP.sql.gz.part" "$DEST/db-$STAMP.sql.gz"

# The artifacts volume, read straight from its mountpoint.
ART=$(docker volume inspect infra_artifacts --format '{{.Mountpoint}}')
tar -czf "$DEST/artifacts-$STAMP.tar.gz.part" -C "$ART" .
mv "$DEST/artifacts-$STAMP.tar.gz.part" "$DEST/artifacts-$STAMP.tar.gz"

# A dump that is suspiciously small is a failed dump with a happy exit code.
DB_BYTES=$(stat -c %s "$DEST/db-$STAMP.sql.gz")
if [ "$DB_BYTES" -lt 20000 ]; then
  echo "backup: database dump is only $DB_BYTES bytes" >&2
  alert "The database dump is only $DB_BYTES bytes."
  exit 1
fi

find "$DEST" -name '*.gz' -mtime +"$KEEP_DAYS" -delete
find "$DEST" -name '*.part' -mmin +120 -delete

echo "backup ok: db $DB_BYTES bytes, $(ls "$DEST" | wc -l) files kept"
