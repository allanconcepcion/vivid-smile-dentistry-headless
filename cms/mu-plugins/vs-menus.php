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
					'label'        => 'Eyebrow',
					'name'         => 'eyebrow',
					'type'         => 'text',
					'instructions' => 'Small label above the title, e.g. "Our practice". Used by the About panel and as the sub-label on Services columns.',
				],
				[
					'key'          => 'field_vs_menu_icon',
					'label'        => 'Icon',
					'name'         => 'icon',
					'type'         => 'text',
					'instructions' => 'Font Awesome class without the style prefix, e.g. <code>fa-tooth</code>. Browse names at fontawesome.com/icons.',
				],
				[
					'key'           => 'field_vs_menu_image',
					'label'         => 'Image',
					'name'          => 'image',
					'type'          => 'image',
					'return_format' => 'array',
					'preview_size'  => 'thumbnail',
					'instructions'  => 'Used by the About panel cards. Ignored elsewhere.',
				],
				[
					'key'          => 'field_vs_menu_image_position',
					'label'        => 'Image focal point',
					'name'         => 'image_position',
					'type'         => 'text',
					'instructions' => 'CSS object-position, e.g. <code>center 37%</code>. Leave blank to centre. '
						. 'Use this when a face sits near the top of a photo and the default crop cuts it off.',
				],
				[
					'key'           => 'field_vs_menu_layout',
					'label'         => 'Panel layout',
					'name'          => 'layout',
					'type'          => 'select',
					'instructions'  => 'How this item renders inside its panel. Only applies to second-level items.',
					'choices'       => [
						''                     => 'Default for the panel',
						'column'               => 'Breakout column (shows its own child links)',
						'standalone'           => 'Standalone card (no child links)',
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
