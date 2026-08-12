<?php
/**
 * Plugin Name:  Vivid Smiles — Content Model
 * Description:  Registers the post types, taxonomies, and ACF field groups that
 *               back the Astro site's content collections.
 * Author:       Concepcion.Work
 * Version:      0.1.0
 *
 * Field groups are declared in code (acf_add_local_field_group) rather than
 * created through wp-admin so the content model is version-controlled and
 * travels with the repo. Editing these groups in the ACF UI will not persist.
 *
 * The field names here are load-bearing: they are the contract consumed by the
 * Astro loader in src/loaders/wordpress.ts, which in turn must satisfy the Zod
 * schemas in src/content.config.ts. Changing a field name is a breaking change
 * on both sides.
 */

declare( strict_types=1 );

namespace VividSmiles\ContentModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blog categories.
 *
 * src/content.config.ts declares `category` as a CLOSED z.enum of these five
 * strings, and src/lib/blog.ts hardcodes the same list for display ordering.
 * They are also the runtime filter keys: src/scripts/blog-filter.ts compares
 * a card's data-category against a chip's data-filter, and shared
 * /blog/?category=<name> URLs embed the string verbatim.
 *
 * So these names are a public contract, not display text. Renaming one breaks
 * previously-shared URLs and silently drops posts out of the hub filter.
 */
const BLOG_CATEGORIES = [
	'Dental Tips',
	'Cosmetic Dentistry',
	'Implant Dentistry',
	'General Dentistry',
	'Emergency Dentistry',
];

/**
 * Seed the five canonical blog categories, and remove WordPress's default
 * "Uncategorized" as a selectable option so an editor cannot publish a post
 * into a category the Astro enum will reject at build time.
 */
function seed_blog_categories(): void {
	if ( get_option( 'vs_categories_seeded' ) ) {
		return;
	}

	foreach ( BLOG_CATEGORIES as $name ) {
		if ( ! term_exists( $name, 'category' ) ) {
			wp_insert_term( $name, 'category' );
		}
	}

	// Point the default category at a real one so new posts never land on
	// "Uncategorized" — which would fail the Astro z.enum on the next build.
	$default = get_term_by( 'name', 'Dental Tips', 'category' );
	if ( $default instanceof \WP_Term ) {
		update_option( 'default_category', $default->term_id );
	}

	update_option( 'vs_categories_seeded', 1 );
}
add_action( 'init', __NAMESPACE__ . '\\seed_blog_categories', 20 );

/**
 * Remove the content editor from Pages.
 *
 * Nothing renders a page's post_content — the Astro templates own the layout
 * and prose, and these pages exist only to hold the structured fields below.
 * Leaving the editor in place presents an empty canvas that looks like the page
 * has lost its content, and invites an editor to type copy that will never
 * appear on the site.
 *
 * Posts and testimonials keep their editors; their bodies ARE rendered.
 */
function remove_page_editor(): void {
	remove_post_type_support( 'page', 'editor' );
}
add_action( 'init', __NAMESPACE__ . '\\remove_page_editor', 30 );

/**
 * The block editor ignores remove_post_type_support('editor') in some flows,
 * so pages are pinned to the classic screen where the field group renders
 * directly under the title instead of behind a collapsed meta-box panel.
 */
function pages_use_classic_editor( bool $use_block_editor, string $post_type ): bool {
	return $post_type === 'page' ? false : $use_block_editor;
}
add_filter( 'use_block_editor_for_post_type', __NAMESPACE__ . '\\pages_use_classic_editor', 10, 2 );

/**
 * Testimonials.
 *
 * Backs the `reviews` collection (src/content.config.ts L28-37), consumed by
 * ReviewMarquee.astro, cards/ReviewCard.astro and the patient-testimonials page.
 *
 * Deliberately NOT a native post: reviews have no body-vs-excerpt distinction,
 * never appear in the blog hub, and need their own admin listing.
 */
function register_testimonial_post_type(): void {
	register_post_type(
		'vs_testimonial',
		[
			'labels'              => [
				'name'          => 'Testimonials',
				'singular_name' => 'Testimonial',
				'add_new_item'  => 'Add New Testimonial',
				'edit_item'     => 'Edit Testimonial',
				'search_items'  => 'Search Testimonials',
				'not_found'     => 'No testimonials yet',
			],
			// Must be public for WPGraphQL to serve it to UNAUTHENTICATED
			// requests — WPGraphQL gates a post type's public visibility on this
			// flag, and the Astro build fetches anonymously. With public=false
			// the testimonials query silently returns an empty list rather than
			// an error, which is a genuinely nasty way to lose content.
			//
			// Nothing is actually exposed on this host: rewrite is off, and
			// vs-headless.php redirects every front-end request to the Astro
			// site, so there is no browsable testimonial URL here.
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => true,
			'menu_icon'           => 'dashicons-format-quote',
			'menu_position'       => 21,
			'supports'            => [ 'title', 'editor', 'page-attributes' ],
			'has_archive'         => false,
			'rewrite'             => false,
			'exclude_from_search' => true,

			'show_in_graphql'     => true,
			'graphql_single_name' => 'testimonial',
			'graphql_plural_name' => 'testimonials',
		]
	);
}
add_action( 'init', __NAMESPACE__ . '\\register_testimonial_post_type', 10 );

/**
 * Free-form review tags ("Dental Procedure", "Patient Comfort", …).
 *
 * The Astro schema types these as z.array(z.string()).default([]) — no closed
 * list — so a flat, editor-extensible taxonomy is the right shape.
 */
function register_testimonial_tag_taxonomy(): void {
	register_taxonomy(
		'vs_testimonial_tag',
		[ 'vs_testimonial' ],
		[
			'labels'             => [
				'name'          => 'Review Tags',
				'singular_name' => 'Review Tag',
			],
			// Public for the same WPGraphQL visibility reason as the post type
			// above; term archives are unreachable here (rewrite => false).
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_rest'       => true,
			'show_admin_column'  => true,
			'hierarchical'       => false,
			'rewrite'            => false,

			'show_in_graphql'     => true,
			'graphql_single_name' => 'testimonialTag',
			'graphql_plural_name' => 'testimonialTags',
		]
	);
}
add_action( 'init', __NAMESPACE__ . '\\register_testimonial_tag_taxonomy', 10 );

/**
 * Field groups.
 *
 * Requires Secure Custom Fields (WordPress.org's ACF fork), not ACF free — the
 * page group below uses `repeater`, which ACF charges for and SCF ships free.
 * See cms/bin/setup.sh.
 */
function register_field_groups(): void {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	/**
	 * Testimonial fields → the `reviews` collection.
	 *
	 * `reviewer` is a separate field rather than reusing post_title so the
	 * admin list can show a meaningful title while the reviewer name stays an
	 * explicit, queryable value. `date` uses the post's own publish date
	 * instead of a custom field — see the loader, which reads `date` from the
	 * node rather than from ACF.
	 */
	acf_add_local_field_group(
		[
			'key'                                   => 'group_vs_testimonial',
			'title'                                 => 'Testimonial',
			'location'                              => [
				[
					[
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'vs_testimonial',
					],
				],
			],
			'position'                              => 'acf_after_title',
			'show_in_graphql'                       => true,
			'graphql_field_name'                    => 'testimonialFields',
			'map_graphql_types_from_location_rules' => true,
			'graphql_types'                         => [ 'Testimonial' ],
			'fields'                                => [
				[
					'key'          => 'field_vs_reviewer',
					'label'        => 'Reviewer name',
					'name'         => 'reviewer',
					'type'         => 'text',
					'required'     => 1,
					'instructions' => 'As it should appear on the site, e.g. "Steve Olds".',
				],
				[
					'key'           => 'field_vs_rating',
					'label'         => 'Rating',
					'name'          => 'rating',
					'type'          => 'number',
					'required'      => 1,
					'default_value' => 5,
					'min'           => 1,
					'max'           => 5,
					'instructions'  => 'Whole number 1–5. The Astro schema rejects anything outside this range at build time.',
				],
				[
					'key'           => 'field_vs_source',
					'label'         => 'Source',
					'name'          => 'source',
					'type'          => 'select',
					'required'      => 1,
					'choices'       => [
						'Google'    => 'Google',
						'Yelp'      => 'Yelp',
						'Facebook'  => 'Facebook',
						'Healthgrades' => 'Healthgrades',
						'In-office' => 'In-office',
					],
					'default_value' => 'Google',
					'return_format' => 'value',
				],
			],
		]
	);

	/**
	 * Post fields → the `blog` collection.
	 *
	 * heroAlt is required by the Astro schema with no default (content.config.ts
	 * L86), so a post published without it fails the build. It lives here rather
	 * than relying on the media library's alt text because the same image can be
	 * reused across posts with different surrounding copy.
	 */
	acf_add_local_field_group(
		[
			'key'                                   => 'group_vs_post',
			'title'                                 => 'Post — Astro fields',
			'location'                              => [
				[
					[
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'post',
					],
				],
			],
			'position'                              => 'side',
			'show_in_graphql'                       => true,
			'graphql_field_name'                    => 'postFields',
			'map_graphql_types_from_location_rules' => true,
			'graphql_types'                         => [ 'Post' ],
			'fields'                                => [
				[
					'key'          => 'field_vs_hero_alt',
					'label'        => 'Hero image alt text',
					'name'         => 'hero_alt',
					'type'         => 'text',
					'required'     => 1,
					'instructions' => 'Describes the featured image for screen readers. Required — the build fails without it.',
				],
				[
					'key'          => 'field_vs_author_name',
					'label'        => 'Byline override',
					'name'         => 'author_name',
					'type'         => 'text',
					'required'     => 0,
					'instructions' => 'Leave blank to use the WordPress author. Defaults to "Slate" on the site.',
				],
			],
		]
	);
	/**
	 * Page content.
	 *
	 * Modelled directly on how the Astro pages already store their editable
	 * content — see src/pages/cosmetic-dentistry/porcelain-veneers.astro, where
	 * `tocLinks`, `processSteps` and `faqs` are arrays in frontmatter. Each maps
	 * to a repeater here, one sub-field per object key, so the Astro template
	 * keeps its markup and only changes where the array comes from.
	 *
	 * Deliberately NOT a free-form page builder. The layouts are bespoke and the
	 * design system is the product; giving an editor arbitrary section ordering
	 * would let them build pages the CSS was never written for. These fields
	 * change the words, not the design.
	 *
	 * There are deliberately NO hero fields here. An earlier version had them,
	 * but nothing populated or rendered them — so an editor could type a new
	 * headline, save, and see no change on the site. A field that silently does
	 * nothing is worse than an absent one. Hero copy lives in the templates
	 * along with the rest of the per-page prose; if it should become editable,
	 * the template has to read it in the same change that adds the field.
	 */
	acf_add_local_field_group(
		[
			'key'                                   => 'group_vs_page',
			'title'                                 => 'Page content',
			'location'                              => [
				[
					[
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'page',
					],
				],
			],
			'show_in_graphql'                       => true,
			'graphql_field_name'                    => 'pageFields',
			'map_graphql_types_from_location_rules' => true,
			'graphql_types'                         => [ 'Page' ],
			'fields'                                => [
				// Explains the model up front. Without it the first impression
				// of this screen is an empty editor canvas above an unfamiliar
				// box, which reads as broken.
				[
					'key'     => 'field_vs_page_intro',
					'label'   => '',
					'name'    => '',
					'type'    => 'message',
					'message' => "<strong>This page's layout and body copy live in the site templates.</strong><br>\n"
						. "What you edit here is the repeating content: the “On this page” rail, the process steps, and the FAQ.\n"
						. "Changes go live on the next site build.",
					'esc_html' => 0,
					'new_lines' => 'wpautop',
				],
				[
					'key'   => 'field_vs_toc_tab',
					'label' => 'On this page',
					'type'  => 'tab',
				],
				[
					'key'          => 'field_vs_toc_links',
					'label'        => 'Table of contents',
					'name'         => 'toc_links',
					'type'         => 'repeater',
					'layout'       => 'table',
					'button_label' => 'Add link',
					'instructions' => 'The sticky rail down the side of the page. Each anchor must match a section id in the layout.',
					'sub_fields'   => [
						[
							'key'   => 'field_vs_toc_label',
							'label' => 'Label',
							'name'  => 'label',
							'type'  => 'text',
						],
						[
							'key'          => 'field_vs_toc_anchor',
							'label'        => 'Anchor',
							'name'         => 'anchor',
							'type'         => 'text',
							'instructions' => 'Without the #, e.g. "process".',
						],
					],
				],
				[
					'key'   => 'field_vs_process_tab',
					'label' => 'Process',
					'type'  => 'tab',
				],
				[
					'key'          => 'field_vs_process_steps',
					'label'        => 'Process steps',
					'name'         => 'process_steps',
					'type'         => 'repeater',
					'layout'       => 'row',
					'button_label' => 'Add step',
					'sub_fields'   => [
						[
							'key'          => 'field_vs_step_tag',
							'label'        => 'Tag',
							'name'         => 'tag',
							'type'         => 'text',
							'instructions' => 'e.g. "Step One".',
						],
						[
							'key'   => 'field_vs_step_title',
							'label' => 'Title',
							'name'  => 'title',
							'type'  => 'text',
						],
						[
							'key'   => 'field_vs_step_body',
							'label' => 'Body',
							'name'  => 'body',
							'type'  => 'textarea',
							'rows'  => 3,
						],
					],
				],
				[
					'key'   => 'field_vs_sections_tab',
					'label' => 'Section copy',
					'type'  => 'tab',
				],
				[
					'key'          => 'field_vs_sections',
					'label'        => 'Sections',
					'name'         => 'sections',
					'type'         => 'repeater',
					'layout'       => 'row',
					'button_label' => 'Add section',
					'instructions' => 'The headline and intro copy for each band of the page. '
						. 'The section ID ties a row to a place in the layout — changing it detaches the copy, '
						. 'so edit the words, not the ID.',
					'sub_fields'   => [
						[
							'key'          => 'field_vs_section_id',
							'label'        => 'Section ID',
							'name'         => 'section_id',
							'type'         => 'text',
							'required'     => 1,
							'instructions' => 'Set by the migration. Do not change.',
							'readonly'     => 1,
						],
						[
							'key'   => 'field_vs_section_eyebrow',
							'label' => 'Eyebrow',
							'name'  => 'eyebrow',
							'type'  => 'text',
						],
						[
							'key'          => 'field_vs_section_heading',
							'label'        => 'Heading',
							'name'         => 'heading',
							'type'         => 'textarea',
							'rows'         => 2,
							'instructions' => 'May contain <em>…</em> for the accent styling.',
						],
						[
							'key'   => 'field_vs_section_body',
							'label' => 'Body',
							'name'  => 'body',
							'type'  => 'textarea',
							'rows'  => 4,
						],
						[
							'key'   => 'field_vs_section_cta_label',
							'label' => 'Button label',
							'name'  => 'cta_label',
							'type'  => 'text',
						],
						[
							'key'   => 'field_vs_section_cta_href',
							'label' => 'Button link',
							'name'  => 'cta_href',
							'type'  => 'text',
						],
					],
				],
				[
					'key'   => 'field_vs_cards_tab',
					'label' => 'Cards & lists',
					'type'  => 'tab',
				],
				[
					'key'          => 'field_vs_cards',
					'label'        => 'Cards',
					'name'         => 'cards',
					'type'         => 'repeater',
					'layout'       => 'row',
					'button_label' => 'Add card',
					'instructions' => 'Repeating tiles on the page — service cards, stats, comparison rows and similar. '
						. 'The group name decides where a card appears; rows sharing a group render together, in order.',
					'sub_fields'   => [
						[
							'key'          => 'field_vs_card_group',
							'label'        => 'Group',
							'name'         => 'group',
							'type'         => 'text',
							'required'     => 1,
							'instructions' => 'Set by the migration. Do not change.',
							'readonly'     => 1,
						],
						[
							'key'   => 'field_vs_card_title',
							'label' => 'Title',
							'name'  => 'title',
							'type'  => 'text',
						],
						[
							'key'   => 'field_vs_card_body',
							'label' => 'Body',
							'name'  => 'body',
							'type'  => 'textarea',
							'rows'  => 3,
						],
						[
							'key'          => 'field_vs_card_meta',
							'label'        => 'Meta',
							'name'         => 'meta',
							'type'         => 'text',
							'instructions' => 'Secondary line — a price, a stat value, a label.',
						],
						[
							'key'   => 'field_vs_card_href',
							'label' => 'Link',
							'name'  => 'href',
							'type'  => 'text',
						],
					],
				],
				[
					'key'   => 'field_vs_faq_tab',
					'label' => 'FAQ',
					'type'  => 'tab',
				],
				[
					'key'          => 'field_vs_faqs',
					'label'        => 'Frequently asked questions',
					'name'         => 'faqs',
					'type'         => 'repeater',
					'layout'       => 'row',
					'button_label' => 'Add question',
					'instructions' => 'These also generate the page\'s FAQPage structured data, so keep answers factual and self-contained.',
					'sub_fields'   => [
						[
							'key'   => 'field_vs_faq_q',
							'label' => 'Question',
							'name'  => 'question',
							'type'  => 'text',
						],
						[
							'key'   => 'field_vs_faq_a',
							'label' => 'Answer',
							'name'  => 'answer',
							'type'  => 'textarea',
							'rows'  => 4,
						],
						[
							'key'           => 'field_vs_faq_open',
							'label'         => 'Open by default',
							'name'          => 'open',
							'type'          => 'true_false',
							'ui'            => 1,
							'default_value' => 0,
						],
					],
				],
			],
		]
	);
}
add_action( 'acf/include_fields', __NAMESPACE__ . '\\register_field_groups' );

/**
 * Expose the post's "last updated" timestamp to GraphQL.
 *
 * The Astro schema has an optional `updated` field which [slug].astro uses for
 * JSON-LD dateModified. WPGraphQL exposes `modified` already, but it changes on
 * every trivial save — including a typo fix that shouldn't reset the "freshness"
 * signal. This surfaces the editor's explicit intent instead.
 */
function register_updated_field(): void {
	if ( ! function_exists( 'register_graphql_field' ) ) {
		return;
	}

	register_graphql_field(
		'Post',
		'contentUpdatedAt',
		[
			'type'        => 'String',
			'description' => 'Editor-declared content update date (ISO 8601), distinct from the automatic `modified` timestamp.',
			'resolve'     => static function ( $post ) {
				$value = get_post_meta( $post->databaseId, 'vs_content_updated_at', true );

				return $value ? (string) $value : null;
			},
		]
	);
}
add_action( 'graphql_register_types', __NAMESPACE__ . '\\register_updated_field' );
