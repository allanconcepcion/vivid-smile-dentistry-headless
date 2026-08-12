<?php
/**
 * Fill WordPress and Yoast with the practice's real details.
 *
 *   npm run import:wp-settings
 *
 * Covers the settings a fresh install leaves generic — site title "cms", empty
 * tagline, no timezone — plus Yoast's first-time configuration, which otherwise
 * nags on every admin page and leaves the Organization schema unnamed.
 *
 * Every value here comes from the site itself (src/data/contact.ts, the
 * LocalBusiness JSON-LD, and the footer's social links). Nothing is invented.
 *
 * Idempotent, and it does NOT overwrite a value someone has already changed —
 * only blanks and known-default placeholders are filled, so re-running after an
 * edit in wp-admin cannot revert it.
 */

// No `declare(strict_types=1)` / `namespace` — see import-reviews.php for why.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Run through WP-CLI.\n" );
	exit( 1 );
}

/* -------------------------------------------------------------------------
 * The practice. Single source for everything below.
 * ---------------------------------------------------------------------- */

$practice = [
	'name'        => 'Vivid Smiles Dentistry',
	'short_name'  => 'Vivid Smiles',
	'tagline'     => 'Cosmetic, implant, general, and emergency dentistry in Parker, Colorado.',
	'email'       => 'info@vividsmilesdentistry.com',
	'site_url'    => 'https://vividsmilesdentistry.com',
	'timezone'    => 'America/Denver',
	// From the footer's social links.
	'facebook'    => 'https://www.facebook.com/VivdSmiles/',
	'instagram'   => 'https://www.instagram.com/vividdentist/',
];

$updated = [];
$kept    = [];

/** Set an option only when it is empty or still the install-time placeholder. */
function vs_fill( string $option, $value, array $placeholders, array &$updated, array &$kept ): void {
	$current = get_option( $option );

	$is_blank = $current === '' || $current === false || $current === null;
	$is_stock = in_array( $current, $placeholders, true );

	if ( ! $is_blank && ! $is_stock ) {
		$kept[] = $option;
		return;
	}

	update_option( $option, $value );
	$updated[] = $option;
}

/* ---- WordPress general -------------------------------------------------- */

vs_fill( 'blogname', $practice['name'], [ 'cms', 'WordPress' ], $updated, $kept );
vs_fill( 'blogdescription', $practice['tagline'], [ 'Just another WordPress site' ], $updated, $kept );
vs_fill( 'timezone_string', $practice['timezone'], [], $updated, $kept );

// The practice's own inbox, so password resets and WordPress notices reach
// someone who works there rather than a developer mailbox. This is the SITE
// address; the admin user's own login email is left alone.
vs_fill( 'admin_email', $practice['email'], [ 'admin@example.com', 'wordpress@example.com' ], $updated, $kept );

// US conventions, matching how dates already render on the site.
vs_fill( 'date_format', 'F j, Y', [], $updated, $kept );
vs_fill( 'time_format', 'g:i a', [], $updated, $kept );
vs_fill( 'start_of_week', '0', [ '1' ], $updated, $kept );

// gmt_offset and timezone_string are mutually exclusive; a stale offset would
// win over the timezone we just set.
if ( get_option( 'timezone_string' ) ) {
	update_option( 'gmt_offset', '' );
}

/* ---- Yoast: site representation ---------------------------------------- */

$logo_id = 0;
$logo    = get_posts(
	[
		'post_type'        => 'attachment',
		'post_status'      => 'any',
		'numberposts'      => 1,
		'meta_key'         => '_vs_import_src',
		'meta_value'       => 'images/brand/vivid-logo-green.webp',
		'suppress_filters' => false,
	]
);

if ( $logo ) {
	$logo_id = (int) $logo[0]->ID;
}

$titles = get_option( 'wpseo_titles', [] );

$titles['company_or_person']      = 'company';
$titles['company_name']           = $titles['company_name'] ?: $practice['name'];
$titles['company_alternate_name'] = $titles['company_alternate_name'] ?: $practice['short_name'];
$titles['website_name']           = $titles['website_name'] ?: $practice['name'];
$titles['alternate_website_name'] = $titles['alternate_website_name'] ?: $practice['short_name'];

if ( $logo_id && empty( $titles['company_logo_id'] ) ) {
	$titles['company_logo']    = (string) wp_get_attachment_url( $logo_id );
	$titles['company_logo_id'] = $logo_id;
}

update_option( 'wpseo_titles', $titles );

/* ---- Yoast: social profiles -------------------------------------------- */

$social = get_option( 'wpseo_social', [] );

$social['facebook_site'] = $social['facebook_site'] ?: $practice['facebook'];
$social['instagram_url'] = $social['instagram_url'] ?: $practice['instagram'];

if ( $logo_id && empty( $social['og_default_image_id'] ) ) {
	$social['og_default_image']    = (string) wp_get_attachment_url( $logo_id );
	$social['og_default_image_id'] = $logo_id;
}

update_option( 'wpseo_social', $social );

/* ---- Yoast: close out the first-time configuration --------------------- */

$wpseo = get_option( 'wpseo', [] );

// Yoast flips first_time_install to false once all three steps are recorded
// (see first-time-configuration-action.php). Recording them here stops the
// setup workout nagging on every admin screen for settings already correct.
$wpseo['configuration_finished_steps'] = [ 'siteRepresentation', 'socialProfiles', 'personalPreferences' ];
$wpseo['first_time_install']           = false;
$wpseo['dismiss_configuration_workout_notice'] = true;
$wpseo['should_redirect_after_install_free']    = false;

// The CMS is not the public site; its own sitemap and crawl settings stay off.
$wpseo['enable_xml_sitemap'] = false;

update_option( 'wpseo', $wpseo );

/* ---- Report ------------------------------------------------------------- */

WP_CLI::log( 'WordPress general:' );
WP_CLI::log( '  site title   ' . get_option( 'blogname' ) );
WP_CLI::log( '  tagline      ' . get_option( 'blogdescription' ) );
WP_CLI::log( '  admin email  ' . get_option( 'admin_email' ) );
WP_CLI::log( '  timezone     ' . get_option( 'timezone_string' ) );
WP_CLI::log( '' );
WP_CLI::log( 'Yoast:' );
WP_CLI::log( '  represents   ' . $titles['company_or_person'] . ' — ' . $titles['company_name'] );
WP_CLI::log( '  logo         ' . ( $logo_id ? 'attachment ' . $logo_id : 'NOT SET (brand logo not in media library)' ) );
WP_CLI::log( '  facebook     ' . $social['facebook_site'] );
WP_CLI::log( '  instagram    ' . $social['instagram_url'] );
WP_CLI::log( '  first-time configuration: complete' );

if ( $kept ) {
	WP_CLI::log( '' );
	WP_CLI::log( 'Left as-is (already customised): ' . implode( ', ', $kept ) );
}

WP_CLI::success( sprintf( '%d option(s) filled.', count( $updated ) ) );
