<?php
/**
 * Plugin Name:  Vivid Smiles — Menus
 * Description:  Registers the site's menu locations and the extra fields the
 *               mega-menu panels need, exposed to WPGraphQL.
 * Author:       Concepcion.Work
 * Version:      0.1.0
 *
 * The navigation was hardcoded across six components — Nav, MobileMenu, the
 * three mega panels, and Footer — with the service links duplicated between
 * MegaServices and MobileMenu and a comment warning that both had to be edited
 * together, by hand, in the same change. That is the failure this fixes: one
 * menu in WordPress, every component reading it.
 *
 * WordPress menus are hierarchical, which maps to the existing panels:
 *
 *   level 1  the top-level bar               Home, About Us, Services, …
 *   level 2  a panel's cards / columns / rows  Cosmetic Dentistry, Blog, …
 *   level 3  links inside a column            Porcelain Veneers, …
 *
 * Title, URL and description are native menu-item fields. The rest — eyebrow,
 * icon, image, layout — are custom fields below, so the panels keep the design
 * they have rather than collapsing into generic link lists.
 */

declare( strict_types=1 );

namespace VividSmiles\Menus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu locations.
 *
 * `show_in_graphql` on the location is what lets the Astro build query by
 * location rather than by menu ID, so renaming a menu in wp-admin cannot break
 * the build.
 */
function register_locations(): void {
	register_nav_menus(
		[
			'primary' => 'Primary navigation (header + mobile)',
			'footer'  => 'Footer links',
		]
	);
}
add_action( 'after_setup_theme', __NAMESPACE__ . '\\register_locations' );

/**
 * Extra fields on menu items.
 *
 * `layout` is the load-bearing one: it tells the Services panel whether a
 * second-level item is a breakout column with its own child links, or a
 * standalone card. Getting that wrong rearranges the panel, so it is a closed
 * select rather than free text.
 */
function register_fields(): void {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_local_field_group(
		[
			'key'                                   => 'group_vs_menu_item',
			'title'                                 => 'Menu item — appearance',
			'location'                              => [
				[
					[
						'param'    => 'nav_menu_item',
						'operator' => '==',
						'value'    => 'all',
					],
				],
			],
			'show_in_graphql'                       => true,
			'graphql_field_name'                    => 'menuFields',
			'map_graphql_types_from_location_rules' => true,
			'graphql_types'                         => [ 'MenuItem' ],
			'fields'                                => [
				[
					'key'          => 'field_vs_menu_eyebrow',
					'label'        => 'Small line above the title',
					'name'         => 'eyebrow',
					'type'         => 'text',
					'instructions' => 'A few words above this item, e.g. "Our practice". Only the About menu and '
					. 'the Services columns show it; leave it blank elsewhere.',
				],
				[
					'key'          => 'field_vs_menu_icon',
					'label'        => 'Little picture beside it',
					'name'         => 'icon',
					'type'         => 'text',
					'instructions' => 'Pick an icon at fontawesome.com/icons and type its name here, e.g. '
					. '<code>fa-tooth</code>. Leave blank for no icon.',
				],
				[
					'key'           => 'field_vs_menu_image',
					'label'         => 'Photo (About menu only)',
					'name'          => 'image',
					'type'          => 'image',
					'return_format' => 'array',
					'preview_size'  => 'thumbnail',
					'instructions'  => 'Only the About menu shows a photo. Every other menu ignores this box.',
				],
				[
					'key'          => 'field_vs_menu_image_position',
					'label'        => 'Which part of the photo to keep',
					'name'         => 'image_position',
					'type'         => 'text',
					'instructions' => 'Leave this blank and the middle of the photo is kept. Fill it in only when '
						. 'the crop cuts someone’s face off — ask us and we will set it.',
				],
				[
					'key'           => 'field_vs_menu_layout',
					'label'         => 'How this item is shown',
					'name'          => 'layout',
					'type'          => 'select',
					'instructions'  => 'Only affects items one level in — the ones inside a drop-down. Leave it on '
						. 'the default unless we have asked you to change it.',
					'choices'       => [
						''                     => 'Default for the panel',
						'column'               => 'Its own column, listing the pages under it',
						'standalone'           => 'A single card on its own, with no list under it',
						// A separate value rather than inferring "is this the
						// emergency one?" from the URL — inferring breaks the
						// moment someone renames the page.
						'standalone_emergency' => 'Standalone card — urgent styling',
					],
					'default_value' => '',
					'return_format' => 'value',
					'allow_null'    => 1,
				],
			],
		]
	);
}
add_action( 'acf/include_fields', __NAMESPACE__ . '\\register_fields' );
