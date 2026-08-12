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
#
# wp_users and wp_usermeta are excluded, for two reasons.
#
# They are not content. Everything this dump exists to carry — posts, pages,
# testimonials, options, ACF rows — lives in other tables, and post_author=1
# resolves against whatever user 1 is on the target install.
#
# And they are a liability. This file is committed to a public repository, so
# exporting them publishes a password hash for every account in the install.
# Excluding them at the table level rather than stripping the rows afterwards
# matters: with --add-drop-table, a dump that CONTAINS the tables would drop
# and recreate them on import, so a stripped-but-present wp_users would wipe
# the target's accounts and lock everyone out. Omitting them entirely leaves
# the target's users untouched.
npx --no-install wp-env run cli wp db export "wp-content/vs-backup/$(basename "$OUT")" \
  --add-drop-table \
  --exclude_tables=wp_users,wp_usermeta \
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
