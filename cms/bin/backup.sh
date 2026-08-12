#!/usr/bin/env bash
#
# Export the local WordPress content so it survives the Docker volume.
#
#   npm run backup
#
# Writes backup/database.sql. Media is not exported here because
# wp-content/uploads is mapped straight to cms/uploads/ on the host — those
# files already live outside Docker and are committed with the repo.
#
# Together, backup/database.sql + uploads/ are everything needed to recreate
# this site, whether that is a teammate's laptop or the production host when
# the site goes online.

set -euo pipefail

cd "$(dirname "$0")/.."

OUT="backup/database.sql"

mkdir -p backup uploads

echo "==> Exporting database"

# --add-drop-table so importing over an existing install replaces cleanly
# rather than colliding on every CREATE TABLE.
npx --no-install wp-env run cli wp db export "wp-content/vs-backup/$(basename "$OUT")" \
  --add-drop-table \
  --default-character-set=utf8mb4

if [[ ! -s "$OUT" ]]; then
  echo "ERROR: $OUT is missing or empty." >&2
  exit 1
fi

# The dump hardcodes the site URL. Record it so restore.sh knows what to
# search-replace when the target environment differs.
npx --no-install wp-env run cli wp option get siteurl 2>/dev/null \
  | tr -d '\r' | tail -1 > backup/SITEURL

POSTS=$(npx --no-install wp-env run cli wp post list --post_type=post --format=count 2>/dev/null | tr -d '\r' | tail -1)
REVIEWS=$(npx --no-install wp-env run cli wp post list --post_type=vs_testimonial --format=count 2>/dev/null | tr -d '\r' | tail -1)
MEDIA=$(find uploads -type f 2>/dev/null | wc -l | tr -d ' ')

echo
echo "Backed up to $OUT ($(du -h "$OUT" | cut -f1))"
echo "  posts:        ${POSTS:-?}"
echo "  testimonials: ${REVIEWS:-?}"
echo "  media files:  ${MEDIA}"
echo "  site url:     $(cat backup/SITEURL)"
