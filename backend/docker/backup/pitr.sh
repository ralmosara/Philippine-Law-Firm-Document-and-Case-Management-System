#!/bin/sh
# Point-in-time recovery: put the database back as it was at a given
# moment, e.g. just before a mistaken trust posting. Replays the nightly
# base backup and every change archived since, up to TARGET_TIME (local
# time, TZ). Uploaded files are left as they are.
#
#   docker compose stop app queue queue-heavy scheduler reverb backup db
#   docker compose run --rm -e TARGET_TIME='2026-10-03 14:04:00' -e CONFIRM=yes pitr
#   docker compose start db          # replays up to TARGET_TIME, then opens
#   docker compose logs -f db        # wait for "database system is ready"
#   docker compose start app queue queue-heavy scheduler reverb backup
#
# The data replaced is kept under backups/pre-pitr-<time> until you delete
# it, so a wrong target time can be retried.
. /usr/local/bin/common.sh
set -o pipefail

PGDATA_DIR=${PGDATA_DIR:-/pgdata}
WAL_ROOT=${WAL_ROOT:-/walsrc}
WAL_DIR="$BACKUP_DIR/wal"
target=${TARGET_TIME:?Set TARGET_TIME, e.g. TARGET_TIME='2026-10-03 14:04:00' (local time)}

[ -f "$PGDATA_DIR/postmaster.pid" ] && { log "The database is running (postmaster.pid exists). Stop it first: docker compose stop db"; exit 1; }

epoch=$(date -d "$target" +%s) || { log "Could not read TARGET_TIME '$target'. Use YYYY-MM-DD HH:MM:SS."; exit 1; }
target_utc=$(date -u -d "@$epoch" '+%Y-%m-%d %H:%M:%S')
target_stamp=$(date -u -d "@$epoch" +%Y%m%dT%H%M%SZ)

if [ -n "${RCLONE_REMOTE:-}" ]; then
    log "Fetching base backups and WAL from $RCLONE_REMOTE"
    rclone copy --config "${RCLONE_CONFIG:-/config/rclone.conf}" --include "base-*" "$RCLONE_REMOTE" "$BACKUP_DIR" || log "Off-site fetch failed; using what is on this server"
    rclone copy --config "${RCLONE_CONFIG:-/config/rclone.conf}" "$RCLONE_REMOTE/wal" "$WAL_DIR" || true
fi

# The newest base backup taken before the target time.
base=$(ls -1 "$BACKUP_DIR" 2>/dev/null | sed -n 's/^base-\(.*\)\.tar\.gz\.enc$/\1/p' | sort | awk -v t="$target_stamp" '$0 <= t' | tail -n 1)
[ -n "$base" ] || { log "No base backup older than $target ($target_stamp UTC). Point-in-time recovery reaches back only to the oldest base backup kept."; exit 1; }

log "Recovering to $target ($target_utc UTC) from base backup $base"
if [ "${CONFIRM:-}" != "yes" ]; then
    log "This replaces the live database with its state at $target. Run again with -e CONFIRM=yes to proceed."
    exit 1
fi

aside="$BACKUP_DIR/pre-pitr-$(date -u +%Y%m%dT%H%M%SZ)"
log "Moving the current database files to $aside"
mkdir -p "$aside"
find "$PGDATA_DIR" -mindepth 1 -maxdepth 1 -exec mv {} "$aside/" \;

log "Unpacking base backup $base"
decrypt < "$BACKUP_DIR/base-$base.tar.gz.enc" | tar -C "$PGDATA_DIR" -xzf -

restore="$WAL_ROOT/wal-restore"
rm -rf "$restore" && mkdir -p "$restore"
log "Decrypting archived WAL"
count=0
for enc in "$WAL_DIR"/*.enc; do
    [ -f "$enc" ] || continue
    decrypt < "$enc" > "$restore/$(basename "$enc" .enc)"
    count=$((count + 1))
done
# Archived but not yet shipped, and the newest WAL, which only the replaced
# database had: without it the last few minutes before the target are lost.
for dir in "$WAL_ROOT/wal" "$aside/pg_wal"; do
    for path in "$dir"/*; do
        [ -f "$path" ] || continue
        name=$(basename "$path")
        case "$name" in *.part|*.backup) continue ;; esac
        [ -f "$restore/$name" ] || { cp "$path" "$restore/$name"; count=$((count + 1)); }
    done
done
log "$count WAL file(s) available for replay"

cat >> "$PGDATA_DIR/postgresql.auto.conf" <<EOF
# Point-in-time recovery to $target ($target_utc UTC), set up by pitr.sh.
restore_command = 'cp /var/lib/postgresql/wal-restore/%f %p'
recovery_target_time = '$target_utc+00'
recovery_target_action = 'promote'
EOF
touch "$PGDATA_DIR/recovery.signal"

# The official image runs PostgreSQL as uid 70 on Alpine.
chown -R 70:70 "$PGDATA_DIR" "$restore"
chmod 700 "$PGDATA_DIR"

log "Ready. Start the database (docker compose start db); it replays up to $target and opens."
log "Then check the data, start the app, and delete $aside and $restore when satisfied."
