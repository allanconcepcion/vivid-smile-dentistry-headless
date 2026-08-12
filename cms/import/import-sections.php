<?php
/**
 * Import section prose and card lists into the existing WordPress pages.
 *
 *   npm run import:sections
 *
 * Runs after import-pages.php, which creates the pages themselves. Matches on
 * the _vs_route meta key that importer records.
 *
 * Idempotent — repeaters are replaced wholesale on each run.
 */

// No `declare(strict_types=1)` / `namespace` — see import-reviews.php for why.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Run through WP-CLI.\n" );
	exit( 1 );
}

$payload_path = WP_CONTENT_DIR . '/vs-import/bin/sections-payload.json';

if ( ! file_exists( $payload_path ) ) {
	WP_CLI::error( 'sections-payload.json not found. Run build-sections-payload.mjs first.' );
}

$payload = json_decode( (string) file_get_contents( $payload_path ), true );

if ( empty( $payload['pages'] ) ) {
	WP_CLI::error( 'Payload is empty or malformed.' );
}

$sections_written = 0;
$cards_written    = 0;
$missing          = [];
$touched          = 0;

foreach ( $payload['pages'] as $page ) {
	$route = (string) $page['route'];

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

	if ( ! $found ) {
		$missing[] = $route;
		continue;
	}

	$post_id = (int) $found[0]->ID;

	$sections = array_map(
		static fn( $s ) => [
			'section_id' => (string) $s['section_id'],
			'eyebrow'    => (string) $s['eyebrow'],
			'heading'    => (string) $s['heading'],
			'body'       => (string) $s['body'],
			'cta_label'  => (string) ( $s['cta_label'] ?? '' ),
			'cta_href'   => (string) ( $s['cta_href'] ?? '' ),
		],
		(array) ( $page['sections'] ?? [] )
	);

	$cards = array_map(
		static fn( $c ) => [
			'group' => (string) $c['group'],
			'title' => (string) $c['title'],
			'body'  => (string) $c['body'],
			'meta'  => (string) $c['meta'],
			'href'  => (string) $c['href'],
		],
		(array) ( $page['cards'] ?? [] )
	);

	if ( $sections ) {
		update_field( 'field_vs_sections', $sections, $post_id );
		$sections_written += count( $sections );
	}

	if ( $cards ) {
		update_field( 'field_vs_cards', $cards, $post_id );
		$cards_written += count( $cards );
	}

	$touched++;
	WP_CLI::log(
		sprintf( '  %-48s sections:%-3d cards:%d', substr( $route, 0, 48 ), count( $sections ), count( $cards ) )
	);
}

WP_CLI::log( '' );

if ( $missing ) {
	WP_CLI::warning( 'No WordPress page for: ' . implode( ', ', $missing ) );
}

WP_CLI::success(
	sprintf( '%d pages updated — %d sections, %d cards.', $touched, $sections_written, $cards_written )
);
