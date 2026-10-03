#!/bin/sh
# Runs a backup every day at BACKUP_TIME (local time, TZ) and a restore
# test once a week on BACKUP_VERIFY_WEEKDAY (1 = Monday ... 7 = Sunday).
# On first start it backs up and verifies straight away, so the health
# check has something to report.
. /usr/local/bin/common.sh

BACKUP_TIME=${BACKUP_TIME:-02:30}
BACKUP_VERIFY_WEEKDAY=${BACKUP_VERIFY_WEEKDAY:-7}

# The line that says what went wrong, for the health check.
first_error() {
    printf '%s\n' "$1" | grep -m 1 -iE 'error|failed|fatal|denied|No such' || printf '%s\n' "$1" | tail -n 1
}

run_backup() {
    if ! out=$(backup.sh 2>&1); then
        echo "$out"
        write_status false "Backup failed: $(first_error "$out")"
        return 1
    fi
    echo "$out"
}

# Ship archived WAL every pass. A failure is recorded for the health check
# (wal_error in the status file) and cleared once shipping works again; the
# backup's own ok/error is kept as it was.
run_ship_wal() {
    if out=$(ship-wal.sh 2>&1); then
        if [ -s "$STATUS_DIR/wal_error" ]; then
            rm -f "$STATUS_DIR/wal_error"
            rewrite_status
        fi
    else
        echo "$out"
        first_error "$out" > "$STATUS_DIR/wal_error"
        rewrite_status
    fi
}

rewrite_status() {
    ok=$(grep -q '"ok":true' "$STATUS_DIR/backup.json" 2>/dev/null && echo true || echo false)
    previous=$(sed -n 's/.*"error":"\([^"]*\)".*/\1/p' "$STATUS_DIR/backup.json" 2>/dev/null || true)
    write_status "$ok" "$previous"
}

run_verify() {
    if ! out=$(verify.sh 2>&1); then
        echo "$out"
        write_status false "Restore test failed: $(first_error "$out")"
        return 1
    fi
    echo "$out"
}

log "Backups daily at $BACKUP_TIME (${TZ:-UTC}), restore test weekly on day $BACKUP_VERIFY_WEEKDAY, keeping $BACKUP_KEEP_DAYS days${RCLONE_REMOTE:+, off-site to $RCLONE_REMOTE}"

until pg_isready -q -d "$DB_NAME"; do sleep 2; done

# With no backup yet, take one as soon as the schema exists (on a fresh
# install that is after "php artisan migrate").
catch_up=$([ -z "$(latest_stamp)" ] && echo 1 || echo 0)
schema_ready() { [ "$(psql -d "$DB_NAME" -tAc "select to_regclass('public.migrations') is not null" 2>/dev/null)" = "t" ]; }

while true; do
    run_ship_wal
    if [ "$catch_up" = 1 ] && schema_ready; then
        catch_up=0
        run_backup && run_verify || true
    fi
    if [ "$(date +%H:%M)" = "$BACKUP_TIME" ]; then
        if run_backup && [ "$(date +%u)" = "$BACKUP_VERIFY_WEEKDAY" ]; then
            run_verify || true
        fi
        sleep 61 # past this minute
    fi
    sleep 20
done
