<?php
/**
 * Stage 2 of the blog migration: blog-payload.json → WordPress posts.
 *
 * Run with:
 *   node cms/import/build-blog-payload.mjs      # stage 1, writes the payload
 *   npm run import:blog                          # this file
 *
 * Sideloads every referenced image into the media library, rewrites the image
 * URLs inside each post body, and creates the posts with their category,
 * publish date, excerpt, and the ACF hero-alt field.
 *
 * Idempotent. Posts are matched on the _vs_import_slug meta key and attachments
 * on _vs_import_src, so re-running updates in place instead of duplicating.
 */

// No `declare(strict_types=1)` / `namespace` — see import-reviews.php for why.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "This script must be run through WP-CLI.\n" );
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/image.php';

$payload_path = WP_CONTENT_DIR . '/vs-import/bin/blog-payload.json';
$assets_root  = WP_CONTENT_DIR . '/vs-import/assets';

if ( ! file_exists( $payload_path ) ) {
	WP_CLI::error( "Payload not found at {$payload_path}. Run: node cms/import/build-blog-payload.mjs" );
}

$payload = json_decode( (string) file_get_contents( $payload_path ), true );

if ( ! is_array( $payload ) || empty( $payload['posts'] ) ) {
	WP_CLI::error( 'Payload is empty or malformed.' );
}

/**
 * Sideload one image into the media library, or return the existing attachment
 * if this source file was imported before.
 *
 * Returns an attachment ID, or a WP_Error.
 */
function vs_import_attachment( string $rel, string $assets_root, string $alt = '' ) {
	$existing = get_posts(
		[
			'post_type'        => 'attachment',
			'post_status'      => 'any',
			'numberposts'      => 1,
			'meta_key'         => '_vs_import_src',
			'meta_value'       => $rel,
			'suppress_filters' => false,
		]
	);

	if ( $existing ) {
		return (int) $existing[0]->ID;
	}

	$source = $assets_root . '/' . ltrim( $rel, '/' );

	if ( ! file_exists( $source ) ) {
		return new WP_Error( 'missing_file', "source image not found in container: {$rel}" );
	}

	$contents = file_get_contents( $source );
	if ( $contents === false ) {
		return new WP_Error( 'unreadable', "could not read {$rel}" );
	}

	// Prefix the filename with its directory so heroes from different posts do
	// not all collide as "hero.webp" and get suffixed -1, -2, -3 by WordPress.
	$name = str_replace( '/', '-', ltrim( $rel, '/' ) );
	$name = preg_replace( '/^images-blog-/', '', $name ) ?? $name;

	$upload = wp_upload_bits( $name, null, $contents );

	if ( ! empty( $upload['error'] ) ) {
		return new WP_Error( 'upload_failed', $upload['error'] );
	}

	$filetype = wp_check_filetype( $upload['file'], null );

	$attachment_id = wp_insert_attachment(
		[
			'post_mime_type' => $filetype['type'] ?: 'image/webp',
			'post_title'     => sanitize_text_field( pathinfo( $name, PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		],
		$upload['file'],
		0,
		true
	);

	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	wp_update_attachment_metadata(
		$attachment_id,
		wp_generate_attachment_metadata( $attachment_id, $upload['file'] )
	);

	update_post_meta( $attachment_id, '_vs_import_src', $rel );

	if ( $alt !== '' ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
	}

	return (int) $attachment_id;
}

$created = 0;
$updated = 0;
$failed  = 0;
$media   = 0;

foreach ( $payload['posts'] as $post_data ) {
	$slug = (string) ( $post_data['slug'] ?? '' );

	try {
		if ( $slug === '' ) {
			throw new RuntimeException( 'payload entry has no slug' );
		}

		$html = (string) $post_data['html'];

		// Upload every image this post needs, then rewrite its src in the body.
		// The hero also becomes the featured image.
		$featured_id = 0;

		foreach ( (array) ( $post_data['images'] ?? [] ) as $image ) {
			$is_hero = ( $image['role'] ?? '' ) === 'hero';
			$alt     = $is_hero ? (string) ( $post_data['heroAlt'] ?? '' ) : '';

			$attachment_id = vs_import_attachment( (string) $image['rel'], $assets_root, $alt );

			if ( is_wp_error( $attachment_id ) ) {
				throw new RuntimeException( $attachment_id->get_error_message() );
			}

			$media++;

			if ( $is_hero ) {
				$featured_id = $attachment_id;
				continue;
			}

			$url = wp_get_attachment_url( $attachment_id );
			if ( $url ) {
				// The body still carries the original relative path from the
				// markdown source; swap it for the media-library URL.
				$html = str_replace( '"' . $image['ref'] . '"', '"' . esc_url( $url ) . '"', $html );
			}
		}

		$date_utc = gmdate( 'Y-m-d H:i:s', strtotime( $post_data['date'] . ' 12:00:00 UTC' ) ?: time() );

		$existing = get_posts(
			[
				'post_type'        => 'post',
				'post_status'      => 'any',
				'numberposts'      => 1,
				'meta_key'         => '_vs_import_slug',
				'meta_value'       => $slug,
				'suppress_filters' => false,
			]
		);

		$postarr = [
			'post_type'     => 'post',
			'post_status'   => ! empty( $post_data['draft'] ) ? 'draft' : 'publish',
			'post_title'    => (string) $post_data['title'],
			'post_name'     => $slug,
			'post_content'  => $html,
			'post_excerpt'  => (string) $post_data['description'],
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
			throw new RuntimeException( $post_id->get_error_message() );
		}

		update_post_meta( $post_id, '_vs_import_slug', $slug );

		// The Astro schema's optional `updated` field, surfaced to GraphQL by
		// vs-content-model.php as contentUpdatedAt.
		if ( ! empty( $post_data['updated'] ) ) {
			update_post_meta( $post_id, 'vs_content_updated_at', (string) $post_data['updated'] );
		} else {
			delete_post_meta( $post_id, 'vs_content_updated_at' );
		}

		$category = get_term_by( 'name', (string) $post_data['category'], 'category' );
		if ( ! $category instanceof WP_Term ) {
			throw new RuntimeException( "category not found in WordPress: {$post_data['category']}" );
		}
		wp_set_post_terms( $post_id, [ (int) $category->term_id ], 'category', false );

		if ( $featured_id ) {
			set_post_thumbnail( $post_id, $featured_id );
		}

		if ( function_exists( 'update_field' ) ) {
			update_field( 'field_vs_hero_alt', (string) $post_data['heroAlt'], $post_id );
			update_field( 'field_vs_author_name', (string) ( $post_data['author'] ?? '' ), $post_id );
		}

		$was_update ? $updated++ : $created++;
		WP_CLI::log(
			sprintf(
				'  %-8s %-62s %s',
				$was_update ? 'updated' : 'created',
				substr( $slug, 0, 62 ),
				$post_data['category']
			)
		);
	} catch ( Throwable $e ) {
		$failed++;
		WP_CLI::warning( sprintf( '  failed  %s — %s', $slug, $e->getMessage() ) );
	}
}

WP_CLI::log( '' );
WP_CLI::log( sprintf( 'Media library: %d image references resolved.', $media ) );

if ( $failed > 0 ) {
	WP_CLI::error( sprintf( '%d created, %d updated, %d FAILED.', $created, $updated, $failed ) );
}

WP_CLI::success( sprintf( '%d created, %d updated, 0 failed.', $created, $updated ) );
