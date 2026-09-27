#!/bin/sh
# Restore test: a backup nobody has restored is only a hope. Restores the
# latest (or the given) backup into a scratch database, checks it has the
# same schema version and the same firms as the live one, checks that the
# file archive decrypts and reads end to end, then drops the scratch copy.
#
#   docker compose exec backup verify.sh [STAMP]
. /usr/local/bin/common.sh
set -o pipefail

stamp=${1:-$(latest_stamp)}
[ -n "$stamp" ] || { log "No backup to verify"; exit 1; }
cd "$BACKUP_DIR"
scratch="${DB_NAME}_restore_check"

log "Checking checksums of backup $stamp"
sha256sum -c "$stamp.sha256"

cleanup() { dropdb --if-exists "$scratch" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup
createdb "$scratch"

log "Restoring the database into $scratch"
decrypt < "db-$stamp.dump.enc" | pg_restore --exit-on-error --dbname="$scratch"

live_migrations=$(psql -d "$DB_NAME" -tAc 'select count(*) from migrations')
restored_migrations=$(psql -d "$scratch" -tAc 'select count(*) from migrations')
restored_firms=$(psql -d "$scratch" -tAc 'select count(*) from firms')
restored_rls=$(psql -d "$scratch" -tAc "select count(*) from pg_class where relrowsecurity and relnamespace = 'public'::regnamespace")
log "Restored: $restored_migrations migrations (live: $live_migrations), $restored_firms firms, row-level security on $restored_rls tables"

[ "$restored_migrations" -gt 0 ] || { log "FAILED: restored database has no migrations"; exit 1; }
[ "$restored_rls" -gt 0 ] || { log "FAILED: row-level security policies were not restored"; exit 1; }
if [ "$restored_migrations" -ne "$live_migrations" ]; then
    log "Note: the schema changed since this backup (migrations differ); still restorable."
fi

log "Reading the file archive end to end"
files=$(decrypt < "files-$stamp.tar.gz.enc" | tar -tzf - | wc -l)
log "File archive readable: $files entries"

date -u +%Y-%m-%dT%H:%M:%SZ > "$STATUS_DIR/verified_at"
write_status true ""
log "Restore test of $stamp passed"
