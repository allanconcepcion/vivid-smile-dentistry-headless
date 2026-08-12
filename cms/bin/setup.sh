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
# secure-custom-fields, NOT advanced-custom-fields.
#
# SCF is WordPress.org's fork of ACF, and it ships the field types ACF charges
# for — repeater, flexible_content, gallery, clone — plus options pages, in the
# free plugin. The page content model needs repeaters, so ACF free cannot do it
# and ACF Pro is $149/yr for ten sites.
#
# It is a drop-in: it defines ACF_VERSION and the same acf_* function surface,
# stores values in the same post-meta format, and WPGraphQL for ACF resolves
# against it unchanged. Verified against this install — existing testimonial and
# post field values kept resolving with no migration.
# Versions are PINNED, and that matters more than usual here.
#
# WPGraphQL for ACF does not officially support SCF. It works because its only
# dependency check is `class_exists('ACF')`, and SCF declares `class ACF` — the
# compatibility is incidental, not contractual (wpgraphql-acf issue #264, open
# since Feb 2026, no maintainer response). A release that tightens that check or
# branches on ACF_VERSION would break the Astro build with no warning.
#
# So: pin, and upgrade deliberately after testing the build, rather than letting
# an unattended update decide when the blog stops rendering.
PLUGINS=(
  "wp-graphql:2.19.0"
  "secure-custom-fields:6.9.5"
  "wpgraphql-acf:2.7.0"
  "wordpress-seo:28.2"
  "add-wpgraphql-seo:5.1.0"
)

for entry in "${PLUGINS[@]}"; do
  slug="${entry%%:*}"
  version="${entry##*:}"
  echo "==> Installing ${slug} ${version}"
  wp plugin install "${slug}" --version="${version}" --activate --force
done

# Unattended updates would defeat the pinning above.
wp plugin auto-updates disable --all 2>/dev/null || true

# ACF and SCF are the same plugin and will fight over the same hooks if both
# run. Only one may be active.
if wp plugin is-active advanced-custom-fields 2>/dev/null; then
  echo "==> Deactivating advanced-custom-fields (superseded by secure-custom-fields)"
  wp plugin deactivate advanced-custom-fields
fi

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

# WordPress also auto-creates a draft "Privacy Policy" page and records it in
# the wp_page_for_privacy_policy option. It is a draft, so it never appears on
# the site — but it OWNS the privacy-policy slug, which silently pushes the real
# imported page to privacy-policy-2 and breaks /privacy-policy/ on the Astro
# side. Targeted by option rather than by slug so a later run cannot delete the
# real page.
privacy_id=$(wp option get wp_page_for_privacy_policy 2>/dev/null | tr -d '\r')
if [[ -n "${privacy_id}" && "${privacy_id}" != "0" ]]; then
  echo "==> Removing WordPress's stock Privacy Policy draft (id ${privacy_id})"
  wp post delete "${privacy_id}" --force 2>/dev/null || true
  wp option update wp_page_for_privacy_policy 0
fi

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
