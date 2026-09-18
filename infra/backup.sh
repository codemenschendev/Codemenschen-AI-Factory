#!/usr/bin/env bash
# Nightly backup of what Appwerk cannot regenerate:
#   db-<stamp>.sql.gz        the Postgres database (customers, orders, projects, prototypes)
#   artifacts-<stamp>.tar.gz the artifacts volume (source bundles, builds, previews)
#   repos-<stamp>.tar.gz     the repos volume (each project's git working copy)
#   media-<stamp>.tar.gz     customer uploads, the picture library, videos, design references
#                            and the design library on /var/lib (2026-09-18: 185 MB in all)
#   env-<stamp>.tar.gz.enc   the API and infra .env files plus /var/lib/ai-factory/secrets,
#                            encrypted (see below): APP_KEY, Stripe, SMTP, gateway tokens. A
#                            database dump without APP_KEY has unreadable encrypted columns.
#
# Runs from root's crontab on the host: 45 3 * * * /var/www/ai-factory/infra/backup.sh
# Keeps 14 nights locally and uploads every file to S3, because a backup on the same disk as
# the database covers a mistaken delete and nothing else. The S3 key may only PutObject into its
# prefix: it cannot list or delete, so a compromised server cannot destroy what is already up
# there. Retention up there is the bucket's lifecycle rule (90 days on appwerk/), not this script.
#
# Not backed up, on purpose: the Docker images (rebuilt from git by the deploy), the raw
# pgdata volume (pg_dump is the consistent copy of it) and redis (queue and cache, transient).
#
# Restore: gunzip -c db-<stamp>.sql.gz | psql;  tar -xzf artifacts-|repos-… -C <volume mountpoint>;
#          tar -xzf media-… -C /;  openssl enc -d -aes-256-cbc -pbkdf2 -pass file:$ENV_KEY
#          -in env-<stamp>.tar.gz.enc | tar -xz -C /   (paths inside are absolute, minus the /)
set -euo pipefail

COMPOSE="docker compose -f /var/www/ai-factory/infra/docker-compose.prod.yml"
DEST=/var/backups/ai-factory
KEEP_DAYS=14
STAMP=$(date +%Y%m%d-%H%M)

# Off-site. Same bucket as the wp-sofa dump (/usr/local/bin/mongo-backup.sh), own IAM user
# `appwerk-backup` (created 2026-09-18) whose only right is PutObject on appwerk/*; its key is the
# `appwerk-backup` profile in /root/.aws on the host. The bucket's lifecycle rule
# `appwerk-nightly-backups` expires objects under appwerk/ after 90 days.
AWS=/usr/local/bin/aws
BUCKET=codemenschenbackup
PREFIX=appwerk
PROFILE=appwerk-backup

# Passphrase for the env archive. Generated once on the host (openssl rand -base64 48), mode 600,
# and kept OFF the server too (password manager): the S3 copy is useless without it.
ENV_KEY=/root/.appwerk-backup.key

MEDIA_DIRS=(/var/appwerk-media /var/lib/ai-factory/design-library)
ENV_FILES=(/var/www/ai-factory/apps/api/.env /var/www/ai-factory/infra/.env /var/lib/ai-factory/secrets)

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

# The repos volume: each project's git working copy, the one the revise stage edits. The source
# bundle in artifacts is a snapshot of a release; this is the history and the current state.
REPOS=$(docker volume inspect infra_repos --format '{{.Mountpoint}}')
tar -czf "$DEST/repos-$STAMP.tar.gz.part" -C "$REPOS" .
mv "$DEST/repos-$STAMP.tar.gz.part" "$DEST/repos-$STAMP.tar.gz"

# Media: absolute paths, stored without the leading slash (tar's default), so a restore is
# `tar -xzf … -C /`. A directory that does not exist yet is skipped, not fatal.
MEDIA_PRESENT=()
for d in "${MEDIA_DIRS[@]}"; do [ -d "$d" ] && MEDIA_PRESENT+=("$d"); done
tar -czf "$DEST/media-$STAMP.tar.gz.part" "${MEDIA_PRESENT[@]}" 2> >(grep -v 'Removing leading' >&2 || true)
mv "$DEST/media-$STAMP.tar.gz.part" "$DEST/media-$STAMP.tar.gz"

# Secrets: only ever on disk encrypted. Without the key file there is no env backup, and that is
# an alert, not a silent skip.
if [ -s "$ENV_KEY" ]; then
  ENV_PRESENT=()
  for f in "${ENV_FILES[@]}"; do [ -e "$f" ] && ENV_PRESENT+=("$f"); done
  umask 077
  tar -cz "${ENV_PRESENT[@]}" 2> >(grep -v 'Removing leading' >&2 || true) \
    | openssl enc -aes-256-cbc -pbkdf2 -salt -pass "file:$ENV_KEY" -out "$DEST/env-$STAMP.tar.gz.enc.part"
  mv "$DEST/env-$STAMP.tar.gz.enc.part" "$DEST/env-$STAMP.tar.gz.enc"
  umask 022
else
  echo "backup: $ENV_KEY missing, env files NOT backed up" >&2
  alert "The env archive was skipped: $ENV_KEY is missing on the host."
fi

# A dump that is suspiciously small is a failed dump with a happy exit code.
DB_BYTES=$(stat -c %s "$DEST/db-$STAMP.sql.gz")
if [ "$DB_BYTES" -lt 20000 ]; then
  echo "backup: database dump is only $DB_BYTES bytes" >&2
  alert "The database dump is only $DB_BYTES bytes."
  exit 1
fi

# Off the machine. The local copies are good and stay either way; a failed upload is still
# shouted about, because the off-site copy is the one that survives losing the disk.
UPLOADED=0
UPLOAD_FAILED=""
for f in "$DEST"/*-"$STAMP".*; do
  case "$f" in *.part) continue ;; esac
  if "$AWS" s3 cp "$f" "s3://$BUCKET/$PREFIX/$(basename "$f")" --profile "$PROFILE" --only-show-errors; then
    UPLOADED=$((UPLOADED + 1))
  else
    UPLOAD_FAILED="$UPLOAD_FAILED $(basename "$f")"
  fi
done
if [ -n "$UPLOAD_FAILED" ]; then
  echo "backup: upload to s3://$BUCKET/$PREFIX/ failed for$UPLOAD_FAILED" >&2
  alert "The S3 upload failed for$UPLOAD_FAILED (local copies are fine)."
fi

find "$DEST" \( -name '*.gz' -o -name '*.enc' \) -mtime +"$KEEP_DAYS" -delete
find "$DEST" -name '*.part' -mmin +120 -delete

echo "backup ok: db $DB_BYTES bytes, media $(stat -c %s "$DEST/media-$STAMP.tar.gz") bytes, $UPLOADED files uploaded to s3://$BUCKET/$PREFIX/, $(ls "$DEST" | wc -l) files kept"
