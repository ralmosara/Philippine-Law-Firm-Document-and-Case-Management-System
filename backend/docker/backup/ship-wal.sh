#!/bin/sh
# Encrypts each WAL segment PostgreSQL has archived (archive-wal.sh) and
# copies it off-site, then removes the plain copy. Runs every few seconds
# from backup-loop.sh, so at most a few minutes of changes (archive_timeout)
# are ever only on this server.
. /usr/local/bin/common.sh
set -o pipefail

WAL_SRC=${WAL_SRC:-/walsrc/wal}
WAL_DIR="$BACKUP_DIR/wal"
mkdir -p "$WAL_DIR"
[ -d "$WAL_SRC" ] || exit 0

shipped=0
for path in "$WAL_SRC"/*; do
    [ -f "$path" ] || continue
    name=$(basename "$path")
    case "$name" in *.part) continue ;; esac

    encrypt < "$path" > "$WAL_DIR/$name.enc.part"
    mv "$WAL_DIR/$name.enc.part" "$WAL_DIR/$name.enc"
    if [ -n "${RCLONE_REMOTE:-}" ]; then
        rclone copyto --config "${RCLONE_CONFIG:-/config/rclone.conf}" "$WAL_DIR/$name.enc" "$RCLONE_REMOTE/wal/$name.enc"
    fi
    rm -f "$path"
    shipped=$((shipped + 1))
done

if [ "$shipped" -gt 0 ]; then
    date -u +%Y-%m-%dT%H:%M:%SZ > "$STATUS_DIR/wal_shipped_at"
fi
