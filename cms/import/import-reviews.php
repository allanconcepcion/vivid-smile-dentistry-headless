<?php
/**
 * One-time import: src/content/reviews/*.md  →  vs_testimonial posts.
 *
 * Run with:
 *   npx wp-env run cli wp eval-file wp-content/vs-import/bin/import-reviews.php
 *
 * Idempotent. Each created post stores its source filename in the
 * _vs_import_slug meta key; re-running updates the existing post rather than
 * creating a duplicate, so the script is safe to iterate on.
 *
 * The Astro `reviews` schema (src/content.config.ts L28-37) requires:
 *   reviewer  string        → ACF field `reviewer`
 *   rating    int 1..5      → ACF field `rating`
 *   source    string        → ACF field `source`
 *   date      coerce.date   → post_date (NOT a custom field — the loader reads
 *                             the node's own date, matching how WordPress sorts)
 *   tags      string[]      → vs_testimonial_tag terms
 */

// NOTE: no `declare(strict_types=1)` and no `namespace` in this file.
// `wp eval-file` runs the script through eval(), and PHP requires both of those
// to be the first statement in a compilation unit — which they cannot be inside
// an eval. The library below is a normal include and does declare them.

require_once WP_CONTENT_DIR . '/vs-import/bin/lib-frontmatter.php';

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "This script must be run through WP-CLI.\n" );
	exit( 1 );
}

$source_dir = WP_CONTENT_DIR . '/vs-import/content/reviews';

$files = glob( $source_dir . '/*.md' );

if ( ! $files ) {
	WP_CLI::error( 'No review markdown found at ' . $source_dir );
}

sort( $files );

$created = 0;
$updated = 0;
$failed  = 0;

foreach ( $files as $path ) {
	$slug = basename( $path, '.md' );

	try {
		[ $fm, $body ] = \VividSmiles\Import\read_markdown( $path );

		foreach ( [ 'reviewer', 'rating', 'source', 'date' ] as $required ) {
			if ( ! isset( $fm[ $required ] ) || $fm[ $required ] === '' ) {
				throw new \RuntimeException( "missing required front-matter key '{$required}'" );
			}
		}

		$rating = (int) $fm['rating'];
		if ( $rating < 1 || $rating > 5 ) {
			throw new \RuntimeException( "rating {$rating} is outside the schema's 1–5 range" );
		}

		if ( $body === '' ) {
			throw new \RuntimeException( 'empty review body' );
		}

		// Front matter carries a date-only string ("2026-03-27"). Anchor it to
		// midday UTC: a midnight timestamp can slide to the previous calendar
		// day once WordPress renders it in a negative-offset site timezone,
		// which would reorder the marquee against the markdown source.
		$date_utc = gmdate( 'Y-m-d H:i:s', strtotime( $fm['date'] . ' 12:00:00 UTC' ) ?: time() );

		$existing = get_posts(
			[
				'post_type'        => 'vs_testimonial',
				'post_status'      => 'any',
				'numberposts'      => 1,
				'meta_key'         => '_vs_import_slug',
				'meta_value'       => $slug,
				'suppress_filters' => false,
			]
		);

		$postarr = [
			'post_type'     => 'vs_testimonial',
			'post_status'   => 'publish',
			// The reviewer's name is the most useful admin-list label; the
			// canonical value still lives in the ACF `reviewer` field.
			'post_title'    => $fm['reviewer'],
			'post_name'     => $slug,
			'post_content'  => $body,
			'post_date_gmt' => $date_utc,
			'post_date'     => get_date_from_gmt( $date_utc ),
		];

		if ( $existing ) {
			$postarr['ID'] = $existing[0]->ID;
			$post_id       = wp_update_post( $postarr, true );
			$was_update    = true;
		} else {
			$post_id    = wp_insert_post( $postarr, true );
			$was_update = false;
		}

		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( $post_id->get_error_message() );
		}

		update_post_meta( $post_id, '_vs_import_slug', $slug );

		// Write ACF values by field KEY so ACF stores its companion _field
		// reference meta. Writing by name alone leaves the value invisible to
		// the ACF admin UI and to WPGraphQL for ACF.
		update_field( 'field_vs_reviewer', $fm['reviewer'], $post_id );
		update_field( 'field_vs_rating', $rating, $post_id );
		update_field( 'field_vs_source', $fm['source'], $post_id );

		$tags = isset( $fm['tags'] ) && is_array( $fm['tags'] ) ? $fm['tags'] : [];
		wp_set_object_terms( $post_id, $tags, 'vs_testimonial_tag', false );

		$was_update ? $updated++ : $created++;
		WP_CLI::log( sprintf( '  %-8s %s (%s, %d★)', $was_update ? 'updated' : 'created', $slug, $fm['source'], $rating ) );
	} catch ( \Throwable $e ) {
		$failed++;
		WP_CLI::warning( sprintf( '  failed  %s — %s', $slug, $e->getMessage() ) );
	}
}

WP_CLI::log( '' );

if ( $failed > 0 ) {
	WP_CLI::error( sprintf( '%d created, %d updated, %d FAILED of %d files.', $created, $updated, $failed, count( $files ) ) );
}

WP_CLI::success( sprintf( '%d created, %d updated, 0 failed of %d files.', $created, $updated, count( $files ) ) );
