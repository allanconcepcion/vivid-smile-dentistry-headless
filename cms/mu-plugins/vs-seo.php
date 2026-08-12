<?php
/**
 * Plugin Name:  Vivid Smiles — SEO bridge
 * Description:  Exposes each page's and post's Yoast SEO fields to WPGraphQL in
 *               a form that is safe to read from a LIST query.
 * Author:       Concepcion.Work
 * Version:      0.1.0
 *
 * WHY NOT JUST USE THE `seo` FIELD FROM add-wpgraphql-seo
 *
 * Two reasons, both verified against this install:
 *
 * 1. IT BLEEDS ACROSS A LIST QUERY. That plugin renders values through Yoast's
 *    frontend presenter, which resolves against global state. Query one page and
 *    the title is right; query `pages(first: 10)` and every node after the first
 *    reports the FIRST node's title and description. The Astro loader fetches
 *    pages as a list, so using it would have shipped 31 pages sharing one title
 *    — the kind of failure that looks fine in a spot check and guts organic
 *    traffic.
 *
 * 2. IT REPORTS noindex FOR EVERYTHING. The CMS host sets blog_public = 0 so it
 *    stays out of search results, and Yoast honours that globally. Reading
 *    metaRobotsNoindex from here would carry the CMS's own noindex onto the
 *    PUBLIC site and deindex the practice.
 *
 * So this reads the stored post meta directly — no presenter, no global state —
 * and resolves Yoast's template variables (%%title%%, %%sep%%, %%sitename%%)
 * per post via wpseo_replace_vars, which takes an explicit post argument.
 *
 * The per-post noindex checkbox IS honoured; the site-wide setting is not.
 */

declare( strict_types=1 );

namespace VividSmiles\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve a Yoast template string ("%%title%% %%sep%% %%sitename%%") against a
 * specific post. Returns the literal string when Yoast is unavailable.
 */
function replace_vars( string $value, \WP_Post $post ): string {
	if ( $value === '' ) {
		return '';
	}

	if ( function_exists( 'wpseo_replace_vars' ) ) {
		return (string) wpseo_replace_vars( $value, $post );
	}

	return $value;
}

/**
 * Build the SEO payload for one post.
 */
function seo_for( int $post_id ): array {
	$post = get_post( $post_id );

	if ( ! $post instanceof \WP_Post ) {
		return [];
	}

	$title = replace_vars( (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ), $post );
	$desc  = replace_vars( (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ), $post );

	// Per-post noindex only. The site-wide blog_public setting is deliberately
	// ignored — see the header note.
	$noindex = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) === '1';

	$og_image = '';
	$og_id    = (int) get_post_meta( $post_id, '_yoast_wpseo_opengraph-image-id', true );

	if ( $og_id ) {
		$og_image = (string) wp_get_attachment_url( $og_id );
	} elseif ( has_post_thumbnail( $post_id ) ) {
		$og_image = (string) get_the_post_thumbnail_url( $post_id, 'full' );
	}

	return [
		'title'       => $title,
		'description' => $desc,
		// A per-post canonical override. Empty means "let the site compute it",
		// which is what Astro already does from the route.
		'canonical'   => (string) get_post_meta( $post_id, '_yoast_wpseo_canonical', true ),
		'noindex'     => $noindex,
		'ogImage'     => $og_image,
	];
}

/**
 * Register the field on every post type the site reads.
 */
function register_fields(): void {
	if ( ! function_exists( 'register_graphql_object_type' ) ) {
		return;
	}

	register_graphql_object_type(
		'VsSeo',
		[
			'description' => 'Editor-managed SEO values, read from stored meta rather than Yoast\'s presenter so they are safe in a list query.',
			'fields'      => [
				'title'       => [ 'type' => 'String', 'description' => 'Meta title. Empty means the template should fall back to its own.' ],
				'description' => [ 'type' => 'String', 'description' => 'Meta description.' ],
				'canonical'   => [ 'type' => 'String', 'description' => 'Per-post canonical override; empty means derive from the route.' ],
				'noindex'     => [ 'type' => 'Boolean', 'description' => 'The per-post noindex checkbox. Not the site-wide setting.' ],
				'ogImage'     => [ 'type' => 'String', 'description' => 'Social share image URL.' ],
			],
		]
	);

	foreach ( [ 'Page', 'Post' ] as $type ) {
		register_graphql_field(
			$type,
			'vsSeo',
			[
				'type'        => 'VsSeo',
				'description' => 'Editor-managed SEO values for this entry.',
				'resolve'     => static fn( $node ) => seo_for( (int) $node->databaseId ),
			]
		);
	}
}
add_action( 'graphql_register_types', __NAMESPACE__ . '\\register_fields' );
