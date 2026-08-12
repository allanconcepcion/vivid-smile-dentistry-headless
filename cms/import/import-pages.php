<?php
/**
 * Stage 2 of the page migration: pages-payload.json → WordPress pages.
 *
 * Run with:
 *   npm run import:pages
 *
 * Creates one WordPress page per Astro route, nested to match the URL structure
 * so wp-admin mirrors the site, and populates the repeater fields registered by
 * vs-content-model.php.
 *
 * Idempotent. Pages are matched on the _vs_route meta key — the full route, not
 * the slug, because slugs repeat across branches (several sections have their
 * own "index"), and matching on slug alone would collapse them onto each other.
 */

// No `declare(strict_types=1)` / `namespace` — see import-reviews.php for why.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "This script must be run through WP-CLI.\n" );
	exit( 1 );
}

$payload_path = WP_CONTENT_DIR . '/vs-import/bin/pages-payload.json';

if ( ! file_exists( $payload_path ) ) {
	WP_CLI::error( "Payload not found. Run: node cms/import/build-pages-payload.mjs" );
}

$payload = json_decode( (string) file_get_contents( $payload_path ), true );

if ( ! is_array( $payload ) || empty( $payload['pages'] ) ) {
	WP_CLI::error( 'Payload is empty or malformed.' );
}

$pages = $payload['pages'];

// Shallow routes first, so a parent always exists before its children.
usort(
	$pages,
	static fn( $a, $b ) =>
		substr_count( trim( $a['route'], '/' ), '/' ) <=> substr_count( trim( $b['route'], '/' ), '/' )
			?: strcmp( $a['route'], $b['route'] )
);

/** route => post ID, for resolving post_parent. */
$by_route = [];

function vs_find_page_by_route( string $route ) {
	$found = get_posts(
		[
			'post_type'        => 'page',
			'post_status'      => 'any',
			'numberposts'      => 1,
			'meta_key'         => '_vs_route',
			'meta_value'       => $route,
			'suppress_filters' => false,
		]
	);

	return $found ? (int) $found[0]->ID : 0;
}

$created = 0;
$updated = 0;
$failed  = 0;
$fields_written = 0;

foreach ( $pages as $page ) {
	$route = (string) $page['route'];

	try {
		$segments = array_values( array_filter( explode( '/', $route ) ) );
		$slug     = $segments ? end( $segments ) : 'home';

		// Resolve the parent from the route one level up.
		$parent_id = 0;
		if ( count( $segments ) > 1 ) {
			$parent_route = '/' . implode( '/', array_slice( $segments, 0, -1 ) ) . '/';
			$parent_id    = $by_route[ $parent_route ] ?? vs_find_page_by_route( $parent_route );
		}

		$postarr = [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => (string) $page['title'],
			'post_name'   => $slug,
			'post_parent' => $parent_id,
			// Body copy stays in the Astro templates; these pages exist to hold
			// the structured fields, not prose. An editor typing into the main
			// editor here would see nothing change on the site, so say so.
			'post_content' => '',
		];

		$existing = vs_find_page_by_route( $route );

		if ( $existing ) {
			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( $postarr, true );
			$was_update    = true;
		} else {
			$post_id    = wp_insert_post( $postarr, true );
			$was_update = false;
		}

		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( $post_id->get_error_message() );
		}

		update_post_meta( $post_id, '_vs_route', $route );
		$by_route[ $route ] = (int) $post_id;

		// Yoast fields, so SEO is editable in WordPress rather than in markup.
		if ( ! empty( $page['seoTitle'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', (string) $page['seoTitle'] );
		}
		if ( ! empty( $page['seoDescription'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', (string) $page['seoDescription'] );
		}

		$fields = (array) ( $page['fields'] ?? [] );

		// Repeaters are written by field KEY so SCF records its companion _field
		// reference meta; writing by name leaves the rows invisible to both the
		// admin UI and WPGraphQL.
		$map = [
			'toc_links'     => [
				'key'  => 'field_vs_toc_links',
				'rows' => static fn( $row ) => [
					'label'  => (string) ( $row['label'] ?? '' ),
					// Frontmatter stores "#process"; the field holds a bare anchor.
					'anchor' => ltrim( (string) ( $row['href'] ?? $row['anchor'] ?? '' ), '#' ),
				],
			],
			// Two shapes exist in the source pages. Most use
			// { tag, num, title, body }; /cosmetic-dentistry/ and
			// /implant-dentistry/ use the abbreviated { lbl, n, t, p }.
			// Reading only the first shape imported those two pages as empty
			// strings — the step count still matched, so it looked fine.
			'process_steps' => [
				'key'  => 'field_vs_process_steps',
				'rows' => static fn( $row ) => [
					'tag'   => (string) ( $row['tag'] ?? $row['lbl'] ?? '' ),
					'title' => (string) ( $row['title'] ?? $row['t'] ?? '' ),
					'body'  => (string) ( $row['body'] ?? $row['p'] ?? '' ),
				],
			],
			'faqs'          => [
				'key'  => 'field_vs_faqs',
				'rows' => static fn( $row ) => [
					'question' => (string) ( $row['q'] ?? $row['question'] ?? '' ),
					'answer'   => (string) ( $row['a'] ?? $row['answer'] ?? '' ),
					'open'     => ! empty( $row['open'] ),
				],
			],
		];

		foreach ( $map as $name => $spec ) {
			if ( empty( $fields[ $name ] ) ) {
				continue;
			}

			$rows = array_values( array_map( $spec['rows'], (array) $fields[ $name ] ) );
			update_field( $spec['key'], $rows, $post_id );
			$fields_written += count( $rows );
		}

		$summary = [];
		foreach ( (array) ( $page['counts'] ?? [] ) as $k => $v ) {
			$summary[] = "{$k}:{$v}";
		}

		$was_update ? $updated++ : $created++;
		WP_CLI::log(
			sprintf(
				'  %-8s %-46s %s',
				$was_update ? 'updated' : 'created',
				substr( $route, 0, 46 ),
				implode( ' ', $summary ) ?: '(no structured fields)'
			)
		);
	} catch ( Throwable $e ) {
		$failed++;
		WP_CLI::warning( sprintf( '  failed  %s — %s', $route, $e->getMessage() ) );
	}
}

// Make the "/" page WordPress's actual front page.
//
// Without this its permalink is /home/, which is what Yoast puts in the sitemap
// — a URL the Astro site has no route for. Setting it here also makes
// WordPress's own idea of the site structure match the front end's.
$home_id = $by_route['/'] ?? 0;

if ( $home_id ) {
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $home_id );
	WP_CLI::log( sprintf( 'Front page set to "%s" (id %d)', get_the_title( $home_id ), $home_id ) );
}

WP_CLI::log( '' );
WP_CLI::log( sprintf( 'Repeater rows written: %d', $fields_written ) );

if ( $failed > 0 ) {
	WP_CLI::error( sprintf( '%d created, %d updated, %d FAILED.', $created, $updated, $failed ) );
}

WP_CLI::success( sprintf( '%d created, %d updated, 0 failed.', $created, $updated ) );
