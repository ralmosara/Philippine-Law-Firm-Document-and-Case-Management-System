# Backups: encrypted pg_dump of the database plus an encrypted archive of
# uploaded files, kept locally for BACKUP_KEEP_DAYS and optionally copied
# off-site with rclone (S3, Backblaze B2, Google Drive, SFTP, ...).
# Same PostgreSQL major version as the db service, so pg_dump matches.
FROM postgres:16-alpine

RUN apk add --no-cache openssl rclone tar gzip tzdata

COPY common.sh backup.sh verify.sh restore.sh backup-loop.sh ship-wal.sh pitr.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/*.sh

ENTRYPOINT ["/usr/local/bin/backup-loop.sh"]
