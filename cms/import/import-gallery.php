<?php
/**
 * Import the smile gallery and the brand logos into Practice Settings.
 *
 *   npm run import:gallery
 *
 * The gallery previously lived as a folder: src/assets/images/smiles/ was
 * globbed at build time, so adding a photo meant a commit and a deploy. It is
 * now an editable gallery field.
 *
 * Alt text is derived the way the old loader derived it — from the filename,
 * with the numeric ordering prefix stripped — so the wording that shipped is
 * preserved and an editor can improve it in the Media Library afterwards.
 *
 * Idempotent: attachments dedupe on _vs_import_src, and the gallery is only
 * seeded when empty so a curated order is never overwritten.
 */

// No `declare(strict_types=1)` / `namespace` — see import-reviews.php for why.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Run through WP-CLI.\n" );
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/image.php';

$assets = WP_CONTENT_DIR . '/vs-import/assets';

/**
 * Mirrors filenameToAlt() from the old src/lib/smiles.ts.
 *
 * Stems carry a numeric prefix ("01-frontal-arch-berry-lip") purely to fix
 * gallery order. That is ordering metadata, not description, so it must not
 * reach the alt text.
 */
function vs_smile_alt( string $filename ): string {
	$stem       = preg_replace( '/\.\w+$/', '', $filename );
	$descriptor = trim( str_replace( '-', ' ', (string) preg_replace( '/^\d+[-_]?/', '', (string) $stem ) ) );

	return $descriptor
		? "Close-up photograph of a Vivid Smiles patient smiling — {$descriptor}"
		: 'Close-up photograph of a Vivid Smiles patient smiling';
}

function vs_attach( string $rel, string $assets, string $alt ) {
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
		$id = (int) $existing[0]->ID;
		if ( $alt !== '' && ! get_post_meta( $id, '_wp_attachment_image_alt', true ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		return $id;
	}

	$source = $assets . '/' . ltrim( $rel, '/' );
	if ( ! file_exists( $source ) ) {
		return new WP_Error( 'missing', "not found: {$rel}" );
	}

	$name   = preg_replace( '/^images-/', '', str_replace( '/', '-', ltrim( $rel, '/' ) ) );
	$upload = wp_upload_bits( $name, null, (string) file_get_contents( $source ) );

	if ( ! empty( $upload['error'] ) ) {
		return new WP_Error( 'upload', $upload['error'] );
	}

	$type = wp_check_filetype( $upload['file'], null );
	$id   = wp_insert_attachment(
		[
			'post_mime_type' => $type['type'] ?: 'image/webp',
			'post_title'     => sanitize_text_field( pathinfo( $name, PATHINFO_FILENAME ) ),
			'post_status'    => 'inherit',
		],
		$upload['file'],
		0,
		true
	);

	if ( is_wp_error( $id ) ) {
		return $id;
	}

	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	update_post_meta( $id, '_vs_import_src', $rel );

	if ( $alt !== '' ) {
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	}

	return (int) $id;
}

/* ---- smile gallery ------------------------------------------------------ */

$dir = $assets . '/images/smiles';

if ( ! is_dir( $dir ) ) {
	WP_CLI::error( "Smile folder not found at {$dir}" );
}

// GLOB_BRACE is not compiled into every PHP build (it is absent on musl-based
// images like the one this container uses), so brace expansion is done by hand.
$files = [];
foreach ( [ 'webp', 'jpg', 'jpeg', 'png' ] as $ext ) {
	$files = array_merge( $files, glob( $dir . '/*.' . $ext ) ?: [] );
}
sort( $files ); // filename order is the curated gallery order

$ids = [];

foreach ( $files as $path ) {
	$base = basename( $path );
	$id   = vs_attach( 'images/smiles/' . $base, $assets, vs_smile_alt( $base ) );

	if ( is_wp_error( $id ) ) {
		WP_CLI::warning( "  {$base}: " . $id->get_error_message() );
		continue;
	}

	$ids[] = $id;
	WP_CLI::log( sprintf( '  gallery  %s', $base ) );
}

$current = get_field( 'field_vs_smile_gallery', 'option' );

if ( empty( $current ) ) {
	update_field( 'field_vs_smile_gallery', $ids, 'option' );
	WP_CLI::log( sprintf( '  seeded gallery with %d photos', count( $ids ) ) );
} else {
	WP_CLI::log( sprintf( '  gallery already has %d photos — left as curated', count( (array) $current ) ) );
}

/* ---- logos -------------------------------------------------------------- */

$logos = [
	'field_vs_logo_dark'  => 'images/brand/vivid-logo-green.webp',
	'field_vs_logo_light' => 'images/brand/vivid-smiles.webp',
];

foreach ( $logos as $key => $rel ) {
	if ( get_field( $key, 'option' ) ) {
		continue;
	}

	$id = vs_attach( $rel, $assets, 'Vivid Smiles Dentistry' );

	if ( is_wp_error( $id ) ) {
		WP_CLI::warning( "  {$rel}: " . $id->get_error_message() );
		continue;
	}

	update_field( $key, $id, 'option' );
	WP_CLI::log( sprintf( '  logo     %s', basename( $rel ) ) );
}

WP_CLI::success( sprintf( '%d gallery photos, logos seeded.', count( $ids ) ) );
