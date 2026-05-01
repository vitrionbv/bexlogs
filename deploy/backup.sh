#!/usr/bin/env bash
# BexLogs — daily backup of Postgres and the storage volume.
#
# Designed to run from cron on the production host:
#
#   0 3 * * * /opt/bexlogs/deploy/backup.sh >> /var/log/bexlogs-backup.log 2>&1
#
# Outputs:
#   /var/backups/bexlogs/bexlogs-pg-YYYY-MM-DD.sql.gz
#   /var/backups/bexlogs/bexlogs-storage-YYYY-MM-DD.tar.gz
#
# Retains the last 14 backups by default (override with KEEP=N).

set -euo pipefail

APP_DIR="${APP_DIR:-/opt/bexlogs}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/bexlogs}"
KEEP="${KEEP:-14}"
COMPOSE_FILE="${APP_DIR}/docker-compose.production.yml"
ENV_FILE="${APP_DIR}/laravel/.env"

[ ! -f "$COMPOSE_FILE" ] && { echo "no compose file at $COMPOSE_FILE" >&2; exit 1; }
[ ! -f "$ENV_FILE" ]     && { echo "no env file at $ENV_FILE" >&2; exit 1; }

mkdir -p "$BACKUP_DIR"
DATE="$(date -u +%Y-%m-%d)"

# ─── 1. Postgres logical dump ──────────────────────────────────────────────
PG_DUMP="${BACKUP_DIR}/bexlogs-pg-${DATE}.sql.gz"
echo "[$(date -u +%FT%TZ)] dumping postgres → ${PG_DUMP}"

# Read DB credentials directly from the env file so we don't have to repeat
# them.
# shellcheck disable=SC1090
. <(grep -E '^(DB_USERNAME|DB_DATABASE)=' "$ENV_FILE")

docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" exec -T postgres \
    pg_dump --clean --if-exists --no-owner \
        -U "$DB_USERNAME" -d "$DB_DATABASE" \
    | gzip -9 > "${PG_DUMP}.tmp"
mv "${PG_DUMP}.tmp" "$PG_DUMP"

# ─── 2. Storage volume tarball ─────────────────────────────────────────────
ST_TAR="${BACKUP_DIR}/bexlogs-storage-${DATE}.tar.gz"
echo "[$(date -u +%FT%TZ)] archiving storage volume → ${ST_TAR}"

docker run --rm \
    -v bexlogs_storage:/storage:ro \
    -v "${BACKUP_DIR}:/out" \
    alpine:3.20 \
    tar -czf "/out/bexlogs-storage-${DATE}.tar.gz" -C /storage .

# ─── 3. Prune ──────────────────────────────────────────────────────────────
echo "[$(date -u +%FT%TZ)] pruning to last ${KEEP} of each kind"
ls -1t "${BACKUP_DIR}"/bexlogs-pg-*.sql.gz      2>/dev/null | tail -n +$((KEEP + 1)) | xargs -r rm -f
ls -1t "${BACKUP_DIR}"/bexlogs-storage-*.tar.gz 2>/dev/null | tail -n +$((KEEP + 1)) | xargs -r rm -f

echo "[$(date -u +%FT%TZ)] backup complete"
echo "  pg:      $(du -h "$PG_DUMP" | awk '{print $1}')"
echo "  storage: $(du -h "$ST_TAR" | awk '{print $1}')"
