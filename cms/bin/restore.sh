#!/usr/bin/env bash
#
# Restore the local WordPress content from backup/database.sql.
#
#   npm run restore
#
# Use this to rebuild after `wp-env destroy`, to set up a teammate's machine,
# or to seed the production install when the site goes online.
#
# Media needs no restore step: wp-content/uploads is mapped to cms/uploads/ on
# the host, so those files are already in place.
#
# Pass a target URL to rewrite the hardcoded site URL during restore — this is
# what makes the same dump usable against a real hostname:
#
#   bash bin/restore.sh https://cms.vividsmilesdentistry.com

set -euo pipefail

cd "$(dirname "$0")/.."

DUMP="backup/database.sql"
TARGET_URL="${1:-}"

if [[ ! -s "$DUMP" ]]; then
  echo "ERROR: $DUMP not found. Run 'npm run backup' first." >&2
  exit 1
fi

wp() {
  npx --no-install wp-env run cli wp "$@"
}

echo "==> Waiting for WordPress"
until curl -sS -o /dev/null -m 5 "http://localhost:8888/wp-admin/install.php"; do
  sleep 3
done

echo "==> Importing $DUMP"
wp db import "wp-content/vs-backup/$(basename "$DUMP")"

# The dump carries the URL of whatever environment produced it. Without this
# rewrite, every internal link, attachment GUID and serialized option still
# points at the old host — and serialized PHP means a plain SQL find/replace
# corrupts the string lengths. wp search-replace handles serialization.
if [[ -n "$TARGET_URL" ]]; then
  OLD_URL="$(cat backup/SITEURL 2>/dev/null || echo 'http://localhost:8888')"

  if [[ "$OLD_URL" != "$TARGET_URL" ]]; then
    echo "==> Rewriting $OLD_URL -> $TARGET_URL"
    wp search-replace "$OLD_URL" "$TARGET_URL" --all-tables --precise --report-changed-only
  fi
fi

echo "==> Flushing rewrite rules"
wp rewrite flush --hard

echo
echo "Restored."
wp post list --post_type=vs_testimonial --format=count | tr -d '\r' | tail -1 | xargs -I{} echo "  testimonials: {}"
wp post list --post_type=post --format=count | tr -d '\r' | tail -1 | xargs -I{} echo "  posts:        {}"
