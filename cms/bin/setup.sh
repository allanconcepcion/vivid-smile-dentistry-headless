#!/usr/bin/env bash
#
# Provision the local headless WordPress instance.
#
# MUST be re-run after every `wp-env start`. Because .wp-env.json sources core
# from a zip URL, wp-env re-extracts WordPress on each start and resets
# wp-content/plugins — anything installed here is wiped. The database survives,
# so content is safe; only the plugin set needs rebuilding. `npm start` chains
# this automatically.
#
# Installs plugins from the wordpress.org repository BY SLUG rather than by zip
# URL. wp-env derives a plugin's directory name from the zip filename, so a URL
# source lands in e.g. wp-content/plugins/wp-graphql.latest-stable/ — and
# WordPress 6.5+ "Requires Plugins:" dependency resolution matches on the
# directory slug, so WPGraphQL for ACF refuses to activate against it. Slug
# installs produce wp-content/plugins/wp-graphql/ and resolve correctly.
#
# Safe to re-run: every step is idempotent.

set -euo pipefail

cd "$(dirname "$0")/.."

wp() {
  npx --no-install wp-env run cli wp "$@"
}

echo "==> Waiting for WordPress to respond"
until curl -sS -o /dev/null -m 5 "http://localhost:8888/wp-admin/install.php"; do
  sleep 3
done

echo "==> WordPress $(wp core version 2>/dev/null | tr -d '\r')"

# Dependency order matters: wp-graphql must exist and be active before
# wpgraphql-acf activates, and wordpress-seo before add-wpgraphql-seo.
PLUGINS=(
  wp-graphql
  advanced-custom-fields
  wpgraphql-acf
  wordpress-seo
  add-wpgraphql-seo
)

for slug in "${PLUGINS[@]}"; do
  echo "==> Installing ${slug}"
  wp plugin install "${slug}" --activate --force
done

# WordPress ships a "Hello world!" post and a "Sample Page". The post sits in
# the Uncategorized category, which is NOT one of the five values the Astro
# schema accepts — so leaving it in place fails the build the moment the blog
# collection is sourced from WordPress. Remove the stock content outright.
echo "==> Removing WordPress sample content"
for stock_slug in hello-world sample-page; do
  ids=$(wp post list --post_type=post,page --name="${stock_slug}" --format=ids 2>/dev/null | tr -d '\r')
  if [[ -n "${ids}" ]]; then
    # shellcheck disable=SC2086
    wp post delete ${ids} --force
  fi
done

# Uncategorized cannot be deleted while it is the default category; seed_blog_
# categories() in vs-content-model.php repoints the default to "Dental Tips"
# first, so by the time this runs the term is safe to remove.
uncat=$(wp term list category --name=Uncategorized --field=term_id 2>/dev/null | tr -d '\r')
if [[ -n "${uncat}" ]]; then
  wp term delete category "${uncat}" || true
fi

echo "==> Permalinks (WPGraphQL and the REST API both need pretty permalinks)"
wp rewrite structure '/%postname%/' --hard
wp rewrite flush --hard

echo "==> Discouraging search engines on the CMS host"
wp option update blog_public 0

# The Astro site owns the real sitemap (astro.config.mjs). Yoast's sitemap here
# would only list CMS-host URLs. The wpseo_enable_xml_sitemap filter alone does
# not suppress it — Yoast reads this stored option.
echo "==> Disabling Yoast XML sitemaps on the CMS host"
wp option patch update wpseo enable_xml_sitemap false || true

echo "==> Plugin status"
wp plugin list --format=table

echo
echo "Done."
echo "  Admin:    http://localhost:8888/wp-admin  (admin / password)"
echo "  GraphQL:  http://localhost:8888/graphql"
