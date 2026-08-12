<?php
/**
 * Diagnostic: render the Page field group exactly as wp-admin would, and report
 * whether the inputs come back populated.
 *
 *   npx wp-env run cli wp eval-file wp-content/vs-import/bin/verify-admin-fields.php
 *
 * Checking get_field() only proves the data is retrievable. This proves the
 * EDIT SCREEN is populated, which is a different thing — a field group can hold
 * values that the admin form fails to show if the field/value wiring is off.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Run through WP-CLI.\n" );
	exit( 1 );
}

wp_set_current_user( 1 );

$pages = get_posts(
	[
		'post_type'   => 'page',
		'post_status' => 'any',
		'numberposts' => 3,
		'orderby'     => 'title',
	]
);

if ( ! $pages ) {
	WP_CLI::error( 'No pages found.' );
}

foreach ( $pages as $page ) {
	$fields = acf_get_fields( 'group_vs_page' );

	ob_start();
	acf_render_fields( $fields, $page->ID, 'div', 'label' );
	$html = ob_get_clean();

	// Text inputs that carry a real value (skip ACF's own bookkeeping fields).
	preg_match_all( '/<input[^>]+type="text"[^>]*value="([^"]*)"/', $html, $inputs );
	$values = array_values(
		array_filter(
			$inputs[1],
			static fn( $v ) => $v !== '' && ! str_starts_with( $v, 'field_' ) && ! str_starts_with( $v, 'acf' )
		)
	);

	// Textareas with content between the tags.
	preg_match_all( '/<textarea[^>]*>([^<]+)<\/textarea>/', $html, $areas );
	$filled_areas = array_values( array_filter( array_map( 'trim', $areas[1] ) ) );

	WP_CLI::log( sprintf( '%s (id %d)', $page->post_title, $page->ID ) );
	WP_CLI::log( sprintf( '   rendered html:      %d bytes', strlen( $html ) ) );
	WP_CLI::log( sprintf( '   populated inputs:   %d', count( $values ) ) );
	WP_CLI::log( sprintf( '   populated textareas: %d', count( $filled_areas ) ) );

	foreach ( array_slice( $values, 0, 4 ) as $v ) {
		WP_CLI::log( '     - ' . substr( $v, 0, 56 ) );
	}

	$editor_supported = post_type_supports( 'page', 'editor' ) ? 'yes' : 'no';
	WP_CLI::log( sprintf( '   page editor enabled: %s', $editor_supported ) );
	WP_CLI::log( '' );
}
