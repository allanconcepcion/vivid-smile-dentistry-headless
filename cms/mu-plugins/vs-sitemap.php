<?php
/**
 * Plugin Name:  Vivid Smiles — Sitemap scope
 * Description:  Restricts Yoast's XML sitemap to content that actually exists as
 *               a route on the Astro front end.
 * Author:       Concepcion.Work
 * Version:      0.1.0
 *
 * WordPress publishes more than the site does. Left alone, Yoast's sitemap
 * lists every public post type and taxonomy it can find, which here means:
 *
 *   vs_testimonial   20 URLs that are DATA, not pages — the reviews render
 *                    inside other pages and have no route of their own
 *   category         /category/… archives the Astro site does not generate
 *   author, attachment, post-format archives — likewise
 *
 * Every one of those would 404 for a crawler. A sitemap full of dead URLs is
 * worse than no sitemap: it spends crawl budget and signals a broken site.
 *
 * So the sitemap is narrowed to posts and pages, and the pages that are
 * deliberately kept out of search (404, thank-you, the paid landing pages)
 * carry a per-post noindex, which Yoast already honours.
 *
 * The sitemap is still SERVED from the front end, not from here — see
 * src/integrations/yoast-sitemap.ts. This host stays noindexed and
 * Disallow: /, and a sitemap must live on the same domain as its URLs.
 */

declare( strict_types=1 );

namespace VividSmiles\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Post types that have a real route on the front end. */
const ROUTED_POST_TYPES = [ 'post', 'page' ];

/**
 * Drop post types with no front-end route.
 */
function exclude_post_types( bool $excluded, string $post_type ): bool {
	return in_array( $post_type, ROUTED_POST_TYPES, true ) ? $excluded : true;
}
add_filter( 'wpseo_sitemap_exclude_post_type', __NAMESPACE__ . '\\exclude_post_types', 10, 2 );

/**
 * Drop every taxonomy archive.
 *
 * Blog categories are real, but the Astro site filters the hub client-side at
 * /blog/?category=… rather than generating /category/<slug>/ pages, so those
 * URLs do not exist.
 */
function exclude_taxonomies( bool $excluded, string $taxonomy ): bool {
	return true;
}
add_filter( 'wpseo_sitemap_exclude_taxonomy', __NAMESPACE__ . '\\exclude_taxonomies', 10, 2 );

/**
 * Drop author archives — the front end has none.
 */
function exclude_authors( array $users ): array {
	return [];
}
add_filter( 'wpseo_sitemap_exclude_author', '__return_true' );
add_filter( 'wpseo_sitemap_exclude_authors', __NAMESPACE__ . '\\exclude_authors' );

/**
 * URLs are NOT rewritten here, and that is deliberate.
 *
 * The obvious move is a wpseo_sitemap_entry filter swapping this host for the
 * front-end one. It does not work: Yoast validates that each entry belongs to
 * the site's own host and silently DROPS anything foreign, so the filter
 * produced sitemaps with zero URLs — a valid-looking file with nothing in it.
 *
 * Changing WP_HOME would make Yoast emit front-end URLs natively, but it would
 * also move permalinks, GraphQL uris and the media library's base URL, none of
 * which should change.
 *
 * So the URLs stay on this host and the Astro build rewrites them as it writes
 * the files out — see src/integrations/yoast-sitemap.ts, which also verifies
 * every URL against a page that actually got built.
 */

/**
 * The index links to the child sitemaps, which are also fetched from this host
 * at build time — those stay pointing here so the build can retrieve them, and
 * the Astro integration rewrites them when it writes the files out.
 */
