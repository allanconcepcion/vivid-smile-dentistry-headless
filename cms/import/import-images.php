<?php
/**
 * Upload page images into the media library and attach them to their pages.
 *
 *   npm run import:images
 *
 * Every image a page imports becomes a row in that page's Images repeater,
 * keyed by the slot name (the original import identifier). An editor swaps a
 * photo by picking a different file in that row.
 *
 * Idempotent. Files are deduplicated on the _vs_import_src meta key, so a photo
 * used by several pages is uploaded once and shared — re-running does not
 * produce four copies of the same team portrait.
 */

// No `declare(strict_types=1)` / `namespace` — see import-reviews.php for why.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Run through WP-CLI.\n" );
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/image.php';

$payload_path = WP_CONTENT_DIR . '/vs-import/bin/images-payload.json';
$assets_root  = WP_CONTENT_DIR . '/vs-import/assets';

if ( ! file_exists( $payload_path ) ) {
	WP_CLI::error( 'images-payload.json not found. Run build-images-payload.mjs first.' );
}

$payload = json_decode( (string) file_get_contents( $payload_path ), true );

if ( empty( $payload['pages'] ) ) {
	WP_CLI::error( 'Payload is empty or malformed.' );
}

/**
 * Upload one asset, or return the attachment already created for it.
 */
function vs_media_for( string $rel, string $assets_root, string $alt = '' ) {
	static $cache = [];

	if ( isset( $cache[ $rel ] ) ) {
		return $cache[ $rel ];
	}

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
		$cache[ $rel ] = (int) $existing[0]->ID;

		// Backfill alt text if the file was uploaded without any.
		if ( $alt !== '' && ! get_post_meta( $cache[ $rel ], '_wp_attachment_image_alt', true ) ) {
			update_post_meta( $cache[ $rel ], '_wp_attachment_image_alt', $alt );
		}

		return $cache[ $rel ];
	}

	$source = $assets_root . '/' . ltrim( $rel, '/' );

	if ( ! file_exists( $source ) ) {
		return new WP_Error( 'missing', "not found in container: {$rel}" );
	}

	$contents = file_get_contents( $source );
	if ( $contents === false ) {
		return new WP_Error( 'unreadable', "could not read {$rel}" );
	}

	// Flatten the directory into the filename so images from different folders
	// cannot collide on a common basename like "hero.webp" and get -1 suffixes.
	$name = preg_replace( '/^images-/', '', str_replace( '/', '-', ltrim( $rel, '/' ) ) );

	$upload = wp_upload_bits( $name, null, $contents );
	if ( ! empty( $upload['error'] ) ) {
		return new WP_Error( 'upload_failed', $upload['error'] );
	}

	$filetype = wp_check_filetype( $upload['file'], null );

	$attachment_id = wp_insert_attachment(
		[
			'post_mime_type' => $filetype['type'] ?: 'image/webp',
			'post_title'     => sanitize_text_field( pathinfo( $name, PATHINFO_FILENAME ) ),
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

	$cache[ $rel ] = (int) $attachment_id;

	return $cache[ $rel ];
}

$uploaded = 0;
$attached = 0;
$failed   = 0;
$pages    = 0;

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
		WP_CLI::warning( "  no WordPress page for {$route}" );
		$failed++;
		continue;
	}

	$post_id = (int) $found[0]->ID;
	$rows    = [];

	foreach ( (array) $page['images'] as $image ) {
		$before = did_action( 'add_attachment' );
		$id     = vs_media_for( (string) $image['rel'], $assets_root, (string) ( $image['alt'] ?? '' ) );

		if ( is_wp_error( $id ) ) {
			WP_CLI::warning( sprintf( '  %s / %s — %s', $route, $image['slot'], $id->get_error_message() ) );
			$failed++;
			continue;
		}

		if ( did_action( 'add_attachment' ) > $before ) {
			$uploaded++;
		}

		$rows[] = [
			'slot'  => (string) $image['slot'],
			'image' => $id,
			'alt'   => (string) ( $image['alt'] ?? '' ),
		];
		$attached++;
	}

	if ( $rows ) {
		update_field( 'field_vs_images', $rows, $post_id );
		$pages++;
		WP_CLI::log( sprintf( '  %-48s %d images', substr( $route, 0, 48 ), count( $rows ) ) );
	}
}

WP_CLI::log( '' );

if ( $failed > 0 ) {
	WP_CLI::error( sprintf( '%d pages, %d slots attached, %d uploaded, %d FAILED.', $pages, $attached, $uploaded, $failed ) );
}

WP_CLI::success( sprintf( '%d pages, %d slots attached, %d files uploaded.', $pages, $attached, $uploaded ) );
