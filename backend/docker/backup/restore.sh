#!/bin/sh
# Disaster recovery: replace the live database and uploaded files with a
# backup. Destructive; stop the app first:
#
#   docker compose stop app queue queue-heavy scheduler
#   docker compose run --rm -e CONFIRM=yes restore [STAMP]
#   docker compose start app queue queue-heavy scheduler
#
# STAMP defaults to the newest local backup. If it is not on this server,
# it is fetched from RCLONE_REMOTE first.
. /usr/local/bin/common.sh
set -o pipefail

stamp=${1:-$(latest_stamp)}
[ -n "$stamp" ] || { log "No backup found in $BACKUP_DIR. Pass the stamp to fetch one from off-site storage."; exit 1; }
mkdir -p "$BACKUP_DIR"
cd "$BACKUP_DIR"

if [ ! -f "db-$stamp.dump.enc" ] && [ -n "${RCLONE_REMOTE:-}" ]; then
    log "Fetching backup $stamp from $RCLONE_REMOTE"
    rclone copy --config "${RCLONE_CONFIG:-/config/rclone.conf}" --include "*$stamp*" "$RCLONE_REMOTE" .
fi

sha256sum -c "$stamp.sha256"

if [ "${CONFIRM:-}" != "yes" ]; then
    log "This replaces ALL data in $DB_NAME and all uploaded files with backup $stamp."
    log "Run again with -e CONFIRM=yes to proceed."
    exit 1
fi

log "Restoring database $DB_NAME from $stamp"
# --clean drops each object before recreating it; ownership (the app role)
# and row-level security policies come back exactly as dumped.
decrypt < "db-$stamp.dump.enc" | pg_restore --clean --if-exists --exit-on-error --single-transaction --dbname="$DB_NAME"

log "Restoring uploaded files"
# Extract beside the live files first, then swap, so a failure leaves them intact.
rm -rf "$STORAGE_DIR/.restore" && mkdir -p "$STORAGE_DIR/.restore"
decrypt < "files-$stamp.tar.gz.enc" | tar -C "$STORAGE_DIR/.restore" -xzf -
find "$STORAGE_DIR" -mindepth 1 -maxdepth 1 ! -name .restore -exec rm -rf {} +
find "$STORAGE_DIR/.restore" -mindepth 1 -maxdepth 1 -exec mv {} "$STORAGE_DIR/" \;
rmdir "$STORAGE_DIR/.restore"
# PHP-FPM runs as www-data (uid 33).
chown -R 33:33 "$STORAGE_DIR"

log "Restore of $stamp complete. Start the app again."
