#!/bin/sh
# One backup run: the database (a logical dump, portable across PostgreSQL
# versions, and a physical base backup, the starting point for point-in-time
# recovery with the archived WAL) and the uploaded files, each encrypted,
# with checksums; then local retention and the optional off-site copy.
. /usr/local/bin/common.sh
set -o pipefail

stamp=$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p "$BACKUP_DIR"
cd "$BACKUP_DIR"

log "Backing up database $DB_NAME"
pg_dump --format=custom --dbname="$DB_NAME" | encrypt > "db-$stamp.dump.enc.part"

log "Taking a base backup for point-in-time recovery"
# Includes the WAL needed to make it consistent (-X fetch); replay from the
# WAL archive takes it forward to any later moment.
pg_basebackup --host="$DB_HOST" --pgdata=- --format=tar --wal-method=fetch --checkpoint=fast | gzip | encrypt > "base-$stamp.tar.gz.enc.part"

log "Backing up uploaded files"
tar -C "$STORAGE_DIR" -czf - . | encrypt > "files-$stamp.tar.gz.enc.part"

# Rename only when both are complete, so a half-written file is never taken for a backup.
mv "db-$stamp.dump.enc.part" "db-$stamp.dump.enc"
mv "base-$stamp.tar.gz.enc.part" "base-$stamp.tar.gz.enc"
mv "files-$stamp.tar.gz.enc.part" "files-$stamp.tar.gz.enc"
sha256sum "db-$stamp.dump.enc" "base-$stamp.tar.gz.enc" "files-$stamp.tar.gz.enc" > "$stamp.sha256"
log "Wrote db-$stamp.dump.enc ($(du -h "db-$stamp.dump.enc" | cut -f1)) base-$stamp.tar.gz.enc ($(du -h "base-$stamp.tar.gz.enc" | cut -f1)) and files-$stamp.tar.gz.enc ($(du -h "files-$stamp.tar.gz.enc" | cut -f1))"

if [ -n "${RCLONE_REMOTE:-}" ]; then
    log "Copying to off-site storage $RCLONE_REMOTE"
    # A failed upload fails the run, so the health check reports it.
    rclone copy --config "${RCLONE_CONFIG:-/config/rclone.conf}" --include "*$stamp*" . "$RCLONE_REMOTE"
fi

log "Removing local backups older than $BACKUP_KEEP_DAYS days"
find "$BACKUP_DIR" -maxdepth 1 -type f \( -name '*.enc' -o -name '*.sha256' -o -name '*.part' \) -mtime +"$BACKUP_KEEP_DAYS" -delete
# WAL is useful only after the oldest base backup kept; one extra day of margin.
find "$BACKUP_DIR/wal" -maxdepth 1 -type f -name '*.enc' -mtime +"$((BACKUP_KEEP_DAYS + 1))" -delete 2>/dev/null || true

mkdir -p "$STATUS_DIR"
echo "$stamp" > "$STATUS_DIR/last_backup"
date -u +%Y-%m-%dT%H:%M:%SZ > "$STATUS_DIR/last_backup_at"
write_status true ""
log "Backup $stamp complete"
