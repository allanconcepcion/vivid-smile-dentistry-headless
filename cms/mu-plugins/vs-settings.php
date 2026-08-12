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
					'label'        => 'Phone (display)',
					'name'         => 'phone_label',
					'type'         => 'text',
					'required'     => 1,
					'instructions' => 'Exactly as it should read on the page, e.g. (303) 841-5313.',
				],
				[
					'key'          => 'field_vs_phone_e164',
					'label'        => 'Phone (dialable)',
					'name'         => 'phone_e164',
					'type'         => 'text',
					'required'     => 1,
					'instructions' => 'International format for tel: links and structured data, e.g. +1-303-841-5313.',
				],
				[
					'key'      => 'field_vs_email',
					'label'    => 'Email address',
					'name'     => 'email_address',
					'type'     => 'email',
					'required' => 1,
				],
				[
					'key'          => 'field_vs_book_href',
					'label'        => 'Online booking URL',
					'name'         => 'book_now_href',
					'type'         => 'url',
					'required'     => 1,
					'instructions' => 'Where every "Book Online" button goes.',
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
					'name'         => 'directions_href',
					'type'         => 'url',
					'instructions' => 'Where "Get Directions" goes. Usually a Google Maps link.',
				],
				[
					'key'   => 'field_vs_hours_tab',
					'label' => 'Opening hours',
					'type'  => 'tab',
				],
				[
					'key'          => 'field_vs_hours',
					'label'        => 'Hours',
					'name'         => 'office_hours',
					'type'         => 'repeater',
					'layout'       => 'row',
					'button_label' => 'Add a schedule',
					'instructions' => 'One row per distinct schedule. Display strings like "8a–5p" are generated '
						. 'from the times below, so there is nothing to keep in sync by hand. '
						. 'These also drive the “Open now” indicator and the structured data Google reads.',
					'sub_fields'   => [
						[
							'key'          => 'field_vs_hours_label',
							'label'        => 'Label',
							'name'         => 'label',
							'type'         => 'text',
							'required'     => 1,
							'instructions' => 'e.g. Mon–Wed',
						],
						[
							'key'      => 'field_vs_hours_days',
							'label'    => 'Days',
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
							'label'         => 'Closed these days',
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
					'label'        => 'Smile gallery photos',
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
					'label'         => 'Logo — light background',
					'name'          => 'logo_light_bg',
					'type'          => 'image',
					'return_format' => 'array',
					'preview_size'  => 'thumbnail',
					'instructions'  => 'Used in the header on light pages.',
				],
				[
					'key'           => 'field_vs_logo_light',
					'label'         => 'Logo — dark background',
					'name'          => 'logo_dark_bg',
					'type'          => 'image',
					'return_format' => 'array',
					'preview_size'  => 'thumbnail',
					'instructions'  => 'Used in the footer and on dark sections.',
				],
				[
					'key'   => 'field_vs_forms_tab',
					'label' => 'Forms',
					'type'  => 'tab',
				],
				[
					'key'          => 'field_vs_typeform',
					'label'        => 'Contact Typeform ID',
					'name'         => 'contact_typeform_id',
					'type'         => 'text',
					'instructions' => 'The front-desk contact form embedded on /contact/ and /emergency-dentistry/.',
				],
			],
		]
	);
}
add_action( 'acf/include_fields', __NAMESPACE__ . '\\register_fields' );
