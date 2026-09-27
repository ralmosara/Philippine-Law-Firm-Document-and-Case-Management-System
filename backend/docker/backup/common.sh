#!/bin/sh
# Shared settings and helpers for backup.sh, verify.sh and restore.sh.
set -eu

BACKUP_DIR=${BACKUP_DIR:-/backups}
STATUS_DIR=${STATUS_DIR:-/status}
STORAGE_DIR=${STORAGE_DIR:-/storage}
DB_HOST=${DB_HOST:-db}
DB_NAME=${DB_NAME:-ph_legal}
BACKUP_KEEP_DAYS=${BACKUP_KEEP_DAYS:-14}

# The superuser: row-level security never hides rows from it, so the dump
# contains every firm's data. (The app's own role would dump only rows the
# current tenant setting allows.)
export PGHOST="$DB_HOST" PGUSER="${DB_ADMIN_USER:-postgres}" PGPASSWORD="${DB_ADMIN_PASSWORD:?Set DB_ADMIN_PASSWORD}"

: "${BACKUP_PASSPHRASE:?Set BACKUP_PASSPHRASE. Without it a backup cannot be restored: keep a copy somewhere safe, away from the server.}"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

# AES-256 with a key derived from the passphrase (PBKDF2, 200k rounds).
encrypt() { openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass env:BACKUP_PASSPHRASE; }
decrypt() { openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:BACKUP_PASSPHRASE; }

# Read by the app's health check (BACKUP_STATUS_FILE). Keeps the date of the
# last successful restore test across backups.
write_status() { # ok(true|false) error-message
    verified=null
    [ -s "$STATUS_DIR/verified_at" ] && verified="\"$(cat "$STATUS_DIR/verified_at")\""
    last=null backed_up=null
    [ -s "$STATUS_DIR/last_backup" ] && last="\"$(cat "$STATUS_DIR/last_backup")\""
    [ -s "$STATUS_DIR/last_backup_at" ] && backed_up="\"$(cat "$STATUS_DIR/last_backup_at")\""
    error=$(printf '%s' "${2:-}" | tr -d '"\\' | tr '\n' ' ')
    mkdir -p "$STATUS_DIR"
    printf '{"ok":%s,"finished_at":"%s","last_backup":%s,"backed_up_at":%s,"verified_at":%s,"error":"%s"}\n' \
        "$1" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$last" "$backed_up" "$verified" "$error" > "$STATUS_DIR/backup.json.tmp"
    mv "$STATUS_DIR/backup.json.tmp" "$STATUS_DIR/backup.json"
}

latest_stamp() {
    ls -1 "$BACKUP_DIR" 2>/dev/null | sed -n 's/^db-\(.*\)\.dump\.enc$/\1/p' | sort | tail -n 1
}
