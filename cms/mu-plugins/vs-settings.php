<?php
/**
 * Plugin Name:  Vivid Smiles — Practice Settings
 * Description:  Site-wide practice details (phone, address, hours, booking URL)
 *               as an editable options page, exposed to WPGraphQL.
 * Author:       Concepcion.Work
 * Version:      0.1.0
 *
 * These values were constants in src/data/contact.ts and src/data/hours.ts.
 * They appear on every page — in the nav, the footer, every call-to-action, and
 * the LocalBusiness structured data — so a phone number change previously meant
 * a code change and a deploy.
 *
 * Only the DATA moves here. The derived logic stays in TypeScript: whether the
 * practice is open right now, the schema.org openingHoursSpecification, and the
 * display strings are all computed from these values at build time. Storing
 * "8:00am – 5:00pm" alongside "08:00" would let the two drift apart, and the
 * one an editor corrects would not be the one the structured data uses.
 */

declare( strict_types=1 );

namespace VividSmiles\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the options page.
 *
 * Options Pages are an ACF Pro feature that Secure Custom Fields ships free;
 * see cms/bin/setup.sh for why SCF rather than ACF.
 */
function register_options_page(): void {
	if ( ! function_exists( 'acf_add_options_page' ) ) {
		return;
	}

	acf_add_options_page(
		[
			'page_title'      => 'Practice Settings',
			'menu_title'      => 'Practice Settings',
			'menu_slug'       => 'vs-practice-settings',
			'capability'      => 'edit_posts',
			'icon_url'        => 'dashicons-store',
			'position'        => 22,
			'redirect'        => false,
			'update_button'   => 'Save settings',
			'updated_message' => 'Practice settings saved. They go live on the next site build.',

			'show_in_graphql'    => true,
			'graphql_field_name' => 'practiceSettings',
		]
	);
}
add_action( 'acf/init', __NAMESPACE__ . '\\register_options_page' );

/**
 * Field definitions.
 */
function register_fields(): void {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_local_field_group(
		[
			'key'                => 'group_vs_settings',
			'title'              => 'Practice Settings',
			'location'           => [
				[
					[
						'param'    => 'options_page',
						'operator' => '==',
						'value'    => 'vs-practice-settings',
					],
				],
			],
			'show_in_graphql'    => true,
			'graphql_field_name' => 'practiceSettingsFields',
			'fields'             => [
				[
					'key'   => 'field_vs_set_contact_tab',
					'label' => 'Contact',
					'type'  => 'tab',
				],
				[
					'key'          => 'field_vs_phone_label',
					'label'        => 'Phone number, as people should read it',
					'name'         => 'phone_label',
					'type'         => 'text',
					'required'     => 1,
					'instructions' => 'Typed exactly as it should appear on the site, e.g. (303) 841-5313. '
						. 'This is the number every page shows.',
				],
				[
					'key'          => 'field_vs_phone_e164',
					'label'        => 'The same number, in dialling form',
					'name'         => 'phone_e164',
					'type'         => 'text',
					'required'     => 1,
					'instructions' => 'The same phone number written so a mobile can dial it when someone taps '
						. 'it: a plus, the country code, then the number — +1-303-841-5313. It is never '
						. 'shown; the box above is what people read.',
				],
				[
					'key'      => 'field_vs_email',
					'label'        => 'Email address',
					'name'         => 'email_address',
					'type'         => 'email',
					'required'     => 1,
					'instructions' => 'The address the site shows and links to.',
				],
				[
					'key'          => 'field_vs_book_href',
					'label'        => 'Online booking page',
					'name'         => 'book_now_href',
					'type'         => 'url',
					'required'     => 1,
					'instructions' => 'The web address of your booking system. Every “Book Online” button on '
						. 'the site sends people here, so changing it here changes all of them.',
				],
				[
					'key'   => 'field_vs_addr_tab',
					'label' => 'Address',
					'type'  => 'tab',
				],
				[
					'key'      => 'field_vs_addr_street',
					'label'    => 'Street',
					'name'     => 'address_street',
					'type'     => 'text',
					'required' => 1,
				],
				[
					'key'      => 'field_vs_addr_city',
					'label'    => 'City',
					'name'     => 'address_city',
					'type'     => 'text',
					'required' => 1,
				],
				[
					'key'      => 'field_vs_addr_state',
					'label'    => 'State',
					'name'     => 'address_state',
					'type'     => 'text',
					'required' => 1,
				],
				[
					'key'      => 'field_vs_addr_zip',
					'label'    => 'ZIP',
					'name'     => 'address_zip',
					'type'     => 'text',
					'required' => 1,
				],
				[
					'key'          => 'field_vs_directions',
					'label'        => 'Directions link',
					'placeholder'  => 'https://maps.google.com/…',
					'name'         => 'directions_href',
					'type'         => 'url',
					'instructions' => 'Where every “Get Directions” button goes — normally the practice’s '
						. 'Google Maps link.',
				],
				[
					'key'   => 'field_vs_hours_tab',
					'label' => 'Opening hours',
					'type'  => 'tab',
				],
				[
					'key'          => 'field_vs_hours',
					'label'        => 'Your opening hours',
					'name'         => 'office_hours',
					'type'         => 'repeater',
					'layout'       => 'row',
					'button_label' => 'Add a schedule',
					'instructions' => 'One row per group of days that share the same hours — Mon–Wed together, '
						. 'Thursday on its own, and so on. You only set the times; the site writes them out '
						. '("8a–5p") for you, keeps the “Open now” badge right, and tells Google when you '
						. 'are open.',
					'sub_fields'   => [
						[
							'key'          => 'field_vs_hours_label',
							'label'        => 'Which days, as people should read it',
							'name'         => 'label',
							'type'         => 'text',
							'required'     => 1,
							'instructions' => 'Shown on the site exactly as typed — Mon–Wed, Saturday, and so on.',
						],
						[
							'key'      => 'field_vs_hours_days',
							'label'    => 'Tick the days this row covers',
							'name'     => 'days',
							'type'     => 'checkbox',
							'required' => 1,
							'choices'  => [
								'Monday'    => 'Monday',
								'Tuesday'   => 'Tuesday',
								'Wednesday' => 'Wednesday',
								'Thursday'  => 'Thursday',
								'Friday'    => 'Friday',
								'Saturday'  => 'Saturday',
								'Sunday'    => 'Sunday',
							],
							'return_format' => 'value',
						],
						[
							'key'           => 'field_vs_hours_closed',
							'label'         => 'Closed on these days',
							'name'          => 'closed',
							'type'          => 'true_false',
							'ui'            => 1,
							'default_value' => 0,
						],
						[
							'key'               => 'field_vs_hours_opens',
							'label'             => 'Opens',
							'name'              => 'opens',
							'type'              => 'time_picker',
							'display_format'    => 'g:i a',
							'return_format'     => 'H:i',
							'conditional_logic' => [
								[
									[ 'field' => 'field_vs_hours_closed', 'operator' => '!=', 'value' => '1' ],
								],
							],
						],
						[
							'key'               => 'field_vs_hours_closes',
							'label'             => 'Closes',
							'name'              => 'closes',
							'type'              => 'time_picker',
							'display_format'    => 'g:i a',
							'return_format'     => 'H:i',
							'conditional_logic' => [
								[
									[ 'field' => 'field_vs_hours_closed', 'operator' => '!=', 'value' => '1' ],
								],
							],
						],
					],
				],
				[
					'key'   => 'field_vs_gallery_tab',
					'label' => 'Smile gallery',
					'type'  => 'tab',
				],
				[
					'key'          => 'field_vs_smile_gallery',
					'label'        => 'Your smile gallery photos',
					'name'         => 'smile_gallery',
					'type'         => 'gallery',
					'instructions' => 'Finished-smile photos. These appear on the gallery page and in the '
						. 'rotating strip on the home page and every service page — add one here and it '
						. 'shows up everywhere. Drag to reorder.'
						. '<br><br><strong>Alt text matters here.</strong> These are real, identifiable '
						. 'patients, so describe only what the PHOTO shows. Never state or imply what '
						. 'treatment produced it — the surrounding page copy does that job, and a '
						. 'treatment claim attached to a real person is a claims problem.',
					'return_format' => 'array',
					'preview_size'   => 'thumbnail',
					'library'        => 'all',
					'mime_types'     => 'webp,jpg,jpeg,png',
				],
				[
					'key'   => 'field_vs_brand_tab',
					'label' => 'Logos',
					'type'  => 'tab',
				],
				[
					'key'           => 'field_vs_logo_dark',
					'label'         => 'Logo for the top of the page',
					'name'          => 'logo_light_bg',
					'type'          => 'image',
					'return_format' => 'array',
					'preview_size'  => 'thumbnail',
					'instructions'  => 'The logo shown in the bar across the top of every page, which has a '
						. 'pale background — so this one needs dark lettering.',
				],
				[
					'key'           => 'field_vs_logo_light',
					'label'         => 'Logo for the bottom of the page',
					'name'          => 'logo_dark_bg',
					'type'          => 'image',
					'return_format' => 'array',
					'preview_size'  => 'thumbnail',
					'instructions'  => 'The logo shown in the dark strip at the foot of every page, and on any '
						. 'dark section — so this one needs pale lettering.',
				],
				[
					'key'   => 'field_vs_forms_tab',
					'label' => 'Forms',
					'type'  => 'tab',
				],
				[
					'key'          => 'field_vs_typeform',
					'label'        => 'Contact form',
					'name'         => 'contact_typeform_id',
					'type'         => 'text',
					'instructions' => 'The form on the Contact and Emergency Dentistry pages. Paste the ID from '
						. 'the end of the form’s Typeform address — the part after the last slash. Ask us '
						. 'if you are not sure which one that is.',
				],
				[
					'key'          => 'field_vs_consult_typeform',
					'label'        => 'Virtual consult form',
					'name'         => 'consult_typeform_id',
					'type'         => 'text',
					/*
					 * The site's PRIMARY lead form, and until now the only one that was not
					 * a setting: VirtualConsult.astro:37 carried the id as a literal default
					 * while its sibling above has been editable since the settings page was
					 * built. Swapping the main consult form meant a developer and a deploy.
					 *
					 * Blank is safe. VirtualConsult keeps the id it has always shipped as a
					 * last-resort fallback, so an empty box changes nothing — see the
					 * comment on `typeformId` in that file.
					 */
					'instructions' => 'The free virtual-consult form in the closing band on the service pages. '
						. 'Leave it alone unless Typeform has given you a new form ID.',
				],
			],
		]
	);
}
add_action( 'acf/include_fields', __NAMESPACE__ . '\\register_fields' );
