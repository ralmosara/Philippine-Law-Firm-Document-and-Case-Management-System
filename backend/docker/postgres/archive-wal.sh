#!/bin/sh
# PostgreSQL archive_command: copy a finished WAL segment where the backup
# container picks it up, encrypts it and ships it off-site (ship-wal.sh).
#
#   archive_command = '/usr/local/bin/archive-wal.sh %p %f'
#
# The copy is renamed into place only when complete. A segment already
# archived with the same content counts as done (PostgreSQL may retry after
# a crash); different content under the same name is an error, never an
# overwrite.
set -eu

src="$1"
name="$2"
dir=/var/lib/postgresql/wal

mkdir -p "$dir"
if [ -f "$dir/$name" ]; then
    cmp -s "$src" "$dir/$name"
    exit $?
fi
cp "$src" "$dir/$name.part"
mv "$dir/$name.part" "$dir/$name"
