<?php
/**
 * Seed the primary and footer menus from the previously hardcoded navigation.
 *
 *   npm run import:menus
 *
 * Only runs when a location has no menu assigned — once an editor has
 * rearranged the navigation, re-running must not stamp the original structure
 * back over their work.
 *
 * Images are matched to media already in the library by their import path, so
 * this must run after import-images.php.
 */

// No `declare(strict_types=1)` / `namespace` — see import-reviews.php for why.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Run through WP-CLI.\n" );
	exit( 1 );
}

/**
 * Attachment ID for an asset, uploading it if the library does not have it yet.
 *
 * The mega-menu images are imported by COMPONENTS rather than pages, so
 * import-images.php — which walks src/pages — never saw them. Uploading here
 * keeps the menu self-contained instead of depending on an import that has no
 * reason to know about them.
 */
function vs_media_id( string $rel ): int {
	$found = get_posts(
		[
			'post_type'        => 'attachment',
			'post_status'      => 'any',
			'numberposts'      => 1,
			'meta_key'         => '_vs_import_src',
			'meta_value'       => $rel,
			'suppress_filters' => false,
		]
	);

	if ( $found ) {
		return (int) $found[0]->ID;
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';

	$source = WP_CONTENT_DIR . '/vs-import/assets/' . ltrim( $rel, '/' );
	if ( ! file_exists( $source ) ) {
		return 0;
	}

	$name   = preg_replace( '/^images-/', '', str_replace( '/', '-', ltrim( $rel, '/' ) ) );
	$upload = wp_upload_bits( $name, null, (string) file_get_contents( $source ) );
	if ( ! empty( $upload['error'] ) ) {
		return 0;
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
		return 0;
	}

	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	update_post_meta( $id, '_vs_import_src', $rel );

	return (int) $id;
}

/**
 * Add one menu item and its descendants.
 *
 * @param array $item  title, url, description, eyebrow, icon, image, image_position, layout, children
 */
function vs_add_item( int $menu_id, array $item, int $parent = 0, int $order = 0 ): int {
	$id = wp_update_nav_menu_item(
		$menu_id,
		0,
		[
			'menu-item-title'       => $item['title'],
			'menu-item-url'         => $item['url'],
			'menu-item-description' => $item['description'] ?? '',
			'menu-item-status'      => 'publish',
			'menu-item-type'        => 'custom',
			'menu-item-parent-id'   => $parent,
			'menu-item-position'    => $order,
		]
	);

	if ( is_wp_error( $id ) ) {
		WP_CLI::warning( '  ' . $item['title'] . ': ' . $id->get_error_message() );
		return 0;
	}

	foreach ( [ 'eyebrow', 'icon', 'image_position', 'layout' ] as $field ) {
		if ( ! empty( $item[ $field ] ) ) {
			update_field( 'field_vs_menu_' . $field, $item[ $field ], $id );
		}
	}

	if ( ! empty( $item['image'] ) ) {
		$attachment = vs_media_id( $item['image'] );
		if ( $attachment ) {
			update_field( 'field_vs_menu_image', $attachment, $id );

			// ACF "attaches" an unattached image to whatever post the field is
			// on, so the attachment's post_parent becomes this MENU ITEM. A
			// nav_menu_item is not publicly queryable, and WPGraphQL will not
			// resolve an attachment whose parent it cannot expose — the image
			// silently returns null while looking perfectly fine in wp-admin.
			// Detach so it resolves as a plain media-library item.
			wp_update_post( [ 'ID' => $attachment, 'post_parent' => 0 ] );
		} else {
			WP_CLI::warning( sprintf( '  %s: image not in media library (%s)', $item['title'], $item['image'] ) );
		}
	}

	$child_order = 0;
	foreach ( (array) ( $item['children'] ?? [] ) as $child ) {
		vs_add_item( $menu_id, $child, (int) $id, ++$child_order );
	}

	return (int) $id;
}

/* -------------------------------------------------------------------------
 * The structure, lifted from the components it replaces.
 * ---------------------------------------------------------------------- */

$primary = [
	[ 'title' => 'Home', 'url' => '/' ],
	[
		'title'    => 'About Us',
		'url'      => '/about-us/',
		'children' => [
			[
				'title'          => 'About Us',
				'url'            => '/about-us/',
				'eyebrow'        => 'Our practice',
				'description'    => 'The story behind Vivid Smiles — our calm, premium, patient-centered approach to cosmetic and implant dentistry in Parker, CO.',
				'image'          => 'images/team/annie-and-bryce.webp',
				'image_position' => 'center 37%',
			],
			[
				'title'       => 'Meet the Team',
				'url'         => '/about-us/#team',
				'eyebrow'     => 'Doctors & team',
				'description' => 'The dentists and hygienists who built Vivid Smiles. Decades of training, a single calm chair-side voice.',
				'image'       => 'images/team/vivid-team-photo.webp',
			],
			[
				'title'       => 'Our Office',
				'url'         => '/our-office/',
				'eyebrow'     => 'Visit us',
				'description' => 'A bright, low-stress space with the technology of a specialty clinic and the warmth of a neighborhood practice.',
				'image'       => 'images/facility/operating-room.webp',
			],
			[
				'title'       => 'Smile Gallery',
				'url'         => '/smile-gallery/',
				'eyebrow'     => 'Our work',
				'description' => 'Real patients, real smiles. See the finished cosmetic and implant work that comes through our chairs.',
				'image'       => 'images/procedures/smile-three-quarter-dark-backdrop.webp',
			],
		],
	],
	[
		'title'    => 'Services',
		'url'      => '/services/',
		'children' => [
			[
				'title'    => 'Cosmetic Dentistry',
				'url'      => '/cosmetic-dentistry/',
				'eyebrow'  => 'Smile design',
				'icon'     => 'fa-wand-magic-sparkles',
				'layout'   => 'column',
				'children' => [
					[ 'title' => 'Smile Makeover', 'url' => '/cosmetic-dentistry/smile-makeover/' ],
					[ 'title' => 'Porcelain Veneers', 'url' => '/cosmetic-dentistry/porcelain-veneers/' ],
					[ 'title' => 'Clear Aligners', 'url' => '/cosmetic-dentistry/clear-aligners/' ],
					[ 'title' => 'Teeth Whitening', 'url' => '/cosmetic-dentistry/teeth-whitening/' ],
					[ 'title' => 'Gum Contouring', 'url' => '/cosmetic-dentistry/gum-contouring/' ],
					[ 'title' => 'Full Mouth Rehabilitation', 'url' => '/cosmetic-dentistry/full-mouth-rehabilitation/' ],
				],
			],
			[
				'title'    => 'Implant Dentistry',
				'url'      => '/implant-dentistry/',
				'eyebrow'  => 'Replace missing teeth',
				'icon'     => 'fa-tooth',
				'layout'   => 'column',
				'children' => [
					[ 'title' => 'Single Tooth Dental Implants', 'url' => '/implant-dentistry/single-tooth-dental-implants/' ],
					[ 'title' => 'Full Mouth Dental Implants', 'url' => '/implant-dentistry/full-mouth-dental-implants/' ],
					[ 'title' => 'All-on-4 Single Arch', 'url' => '/implant-dentistry/all-on-4-single-arch/' ],
					[ 'title' => 'Bone Grafting', 'url' => '/implant-dentistry/bone-grafting/' ],
					[ 'title' => 'Sinus Lift', 'url' => '/implant-dentistry/sinus-lift/' ],
				],
			],
			[
				'title'       => 'General Dentistry',
				'url'         => '/general-dentistry/',
				'eyebrow'     => 'Everyday care',
				'icon'        => 'fa-shield-heart',
				'layout'      => 'standalone',
				'description' => 'Cleanings, fillings, crowns and preventive care for every member of the family.',
			],
			[
				'title'       => 'Emergency Dentistry',
				'url'         => '/emergency-dentistry/',
				'eyebrow'     => 'Same-day',
				'icon'        => 'fa-truck-medical',
				'layout'      => 'standalone_emergency',
				'description' => 'Toothache, broken tooth, lost crown? Call now — we hold same-day spots open.',
			],
		],
	],
	[
		'title'    => 'Patient Resources',
		'url'      => '/new-patients/',
		'children' => [
			[
				'title'       => 'New Patients',
				'url'         => '/new-patients/',
				'icon'        => 'fa-clipboard-check',
				'description' => 'Your first visit, the paperwork, what to expect, and how to prepare.',
			],
			[
				'title'       => 'Blog',
				'url'         => '/blog/',
				'icon'        => 'fa-book-open',
				'description' => 'Plain-language writing on cosmetic dentistry, implants, and everyday oral health.',
			],
			[
				'title'       => 'Referral Program',
				'url'         => '/referral-program/',
				'icon'        => 'fa-user-plus',
				'description' => "Send a friend, and we'll thank you both with a credit toward your next visit.",
			],
			[
				'title'       => 'Patient Testimonials',
				'url'         => '/patient-testimonials/',
				'icon'        => 'fa-comment-dots',
				'description' => 'Stories from real patients in their own words, plus our 5-star Google reviews.',
			],
		],
	],
	[ 'title' => 'Contact', 'url' => '/contact/' ],
];

$footer = [
	[
		'title'    => 'Treatments',
		'url'      => '/services/',
		'children' => [
			[ 'title' => 'Cosmetic Dentistry', 'url' => '/cosmetic-dentistry/' ],
			[ 'title' => 'Implant Dentistry', 'url' => '/implant-dentistry/' ],
			[ 'title' => 'General Dentistry', 'url' => '/general-dentistry/' ],
			[ 'title' => 'Emergency Dentistry', 'url' => '/emergency-dentistry/' ],
		],
	],
	[
		'title'    => 'Practice',
		'url'      => '/about-us/',
		'children' => [
			[ 'title' => 'About Us', 'url' => '/about-us/' ],
			[ 'title' => 'Our Office', 'url' => '/our-office/' ],
			[ 'title' => 'Smile Gallery', 'url' => '/smile-gallery/' ],
			[ 'title' => 'Patient Testimonials', 'url' => '/patient-testimonials/' ],
		],
	],
	[
		'title'    => 'Patients',
		'url'      => '/new-patients/',
		'children' => [
			[ 'title' => 'New Patients', 'url' => '/new-patients/' ],
			[ 'title' => 'Membership Plan', 'url' => '/dental-membership-plan/' ],
			[ 'title' => 'Referral Program', 'url' => '/referral-program/' ],
			[ 'title' => 'Blog', 'url' => '/blog/' ],
		],
	],
	[
		'title'    => 'Legal',
		'url'      => '/privacy-policy/',
		'children' => [
			[ 'title' => 'Privacy Policy', 'url' => '/privacy-policy/' ],
			[ 'title' => 'Terms & Conditions', 'url' => '/terms-conditions/' ],
		],
	],
];

$locations = get_nav_menu_locations();
$created   = 0;

foreach ( [ 'primary' => $primary, 'footer' => $footer ] as $location => $tree ) {
	if ( ! empty( $locations[ $location ] ) && wp_get_nav_menu_object( $locations[ $location ] ) ) {
		WP_CLI::log( sprintf( '  %s: already assigned — left alone', $location ) );
		continue;
	}

	$name    = $location === 'primary' ? 'Primary Navigation' : 'Footer Links';
	$menu    = wp_get_nav_menu_object( $name );
	$menu_id = $menu ? (int) $menu->term_id : (int) wp_create_nav_menu( $name );

	if ( is_wp_error( $menu_id ) || ! $menu_id ) {
		WP_CLI::error( "Could not create the {$name} menu." );
	}

	$order = 0;
	foreach ( $tree as $item ) {
		vs_add_item( $menu_id, $item, 0, ++$order );
	}

	$locations[ $location ] = $menu_id;
	$created++;

	$count = count( wp_get_nav_menu_items( $menu_id ) ?: [] );
	WP_CLI::log( sprintf( '  %-8s %s — %d items', $location, $name, $count ) );
}

set_theme_mod( 'nav_menu_locations', $locations );

WP_CLI::success( sprintf( '%d menu(s) created and assigned.', $created ) );
