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
	 * hero_alt lives here rather than relying on the media library's alt text
	 * because the same image can be reused across posts with different
	 * surrounding copy. It is deliberately NOT required: see ALWAYS-PUBLISH at
	 * the foot of this file. A post with no alt text publishes, with the image
	 * unlabelled, and post_warning_notice() says so on this screen.
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
					// Was required, on the grounds that the build failed without it.
					// It no longer does — the loader treats a missing value as an
					// unlabelled image and carries on — so `required` would now be a
					// lie that blocks a save for no reason. The nudge moved from a
					// blocked save to a warning that names what the reader loses:
					// post_warning_notice() at the foot of this file.
					'required'     => 0,
					'instructions' => 'Describe the picture in a sentence — what is in it, not "hero image". '
						. 'It is what someone using a screen reader hears in place of the photo, and it is most of '
						. 'what gets the image found in Google Images. '
						. 'Leave it blank and the alt text saved on the file in the Media Library is used instead; '
						. 'with neither, the post still publishes and the picture goes unlabelled.',
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
			// Renders the group directly under the title. This is the half of the
			// arrangement pages_use_classic_editor() above exists for, and what its
			// comment has always described; without this key the group was an
			// ordinary meta box — draggable, collapsible, and liable to sit below
			// the fold. A page's entire editable content lives in this one box, so
			// an editor who cannot find it concludes the page has nothing in it.
			'position'                              => 'acf_after_title',
			'show_in_graphql'                       => true,
			'graphql_field_name'                    => 'pageFields',
			'map_graphql_types_from_location_rules' => true,
			'graphql_types'                         => [ 'Page' ],
			'fields'                                => [
				// Explains the model up front. Without it the first impression
				// of this screen is an empty editor canvas above an unfamiliar
				// box, which reads as broken.
				//
				// It has to name every tab below it. An earlier version named three
				// of the six, which quietly told an editor that the section copy,
				// the photos and the cards were somebody else's to touch. They are
				// as much theirs as the FAQ. Add a tab, add it here.
				[
					'key'     => 'field_vs_page_intro',
					'label'   => '',
					'name'    => '',
					'type'    => 'message',
					'message' => "<strong>This page's layout and body copy live in the site templates.</strong><br>\n"
						. "What you edit here is the content those templates pour in, a tab each: the “On this page” rail, "
						. "the process steps, the heading and intro copy for each section, the photos, "
						. "the cards and lists, and the FAQ.\n"
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
							// Not required OF THE EDITOR. The value is still mandatory to
							// the site — the loader drops a section row with no id — but
							// fill_blank_row_id() below supplies one on save, so nobody has
							// to invent it. Asking a non-technical person to name an
							// internal key is how you get a row that never appears, or a
							// row that cannot be saved at all.
							'required'     => 0,
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
						// Retired: hidden from the editor, still declared. Deleting
						// them outright breaks the build — see
						// hide_retired_section_fields() at the foot of this file.
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
					'key'   => 'field_vs_images_tab',
					'label' => 'Images',
					'type'  => 'tab',
				],
				[
					'key'          => 'field_vs_images',
					'label'        => 'Images',
					'name'         => 'images',
					'type'         => 'repeater',
					'layout'       => 'table',
					'button_label' => 'Add image',
					'instructions' => 'Every photo on this page. Swap one by choosing a different file — '
						. 'the slot name ties it to its place in the layout, so leave that alone. '
						. 'Alt text describes the picture for screen readers and search engines; '
						. 'it is worth writing properly on every image.',
					'sub_fields'   => [
						[
							'key'          => 'field_vs_image_slot',
							'label'        => 'Slot',
							'name'         => 'slot',
							'type'         => 'text',
							// Generated on save when left blank, exactly as Section ID is
							// above; see fill_blank_row_id().
							'required'     => 0,
							'instructions' => 'Set by the migration. Do not change.',
							'readonly'     => 1,
						],
						[
							'key'   => 'field_vs_image_file',
							'label' => 'Image',
							'name'  => 'image',
							'type'  => 'image',
							// The Astro loader needs a URL plus intrinsic
							// dimensions: <Image> refuses a remote source
							// without them, and they are what prevents layout
							// shift. An ID would cost a second query per image.
							'return_format' => 'array',
							'preview_size'  => 'thumbnail',
							'library'       => 'all',
							// The same list the smile gallery uses in vs-settings.php, for
							// the same reason. `library` above is the picker's SCOPE — all
							// uploads versus only this page's — and filters nothing by
							// type, so without this line the field takes any image
							// WordPress accepts, bmp and ico included. (Not SVG: nothing in
							// cms/ widens upload_mimes, so core still refuses it.) Astro
							// hands every one of these URLs to sharp at build time and
							// sharp has no decoder for those two, so the upload succeeds,
							// the row looks right in wp-admin, and the next deploy fails
							// with an error naming a file rather than a field. Catching it
							// in the picker costs the editor one sentence instead.
							'mime_types'    => 'webp,jpg,jpeg,png',
						],
						[
							'key'          => 'field_vs_image_alt',
							'label'        => 'Alt text',
							'name'         => 'alt',
							'type'         => 'text',
							'instructions' => 'Leave blank to use the alt text stored on the file in the Media Library.',
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
 * The three field keys the importer owns.
 *
 * Each ties a repeater row to a fixed place in a designed template: section_id
 * to a band of copy, slot to an image position, group to a set of cards. Editing
 * one on an imported row detaches the content from the layout it belongs to,
 * which is why all three are readonly.
 */
const IMPORTER_OWNED_KEYS = [
	'field_vs_section_id',
	'field_vs_image_slot',
	'field_vs_card_group',
];

/**
 * The importer-owned keys the system fills in for the editor, and the postmeta
 * key shape each repeater stores its rows under.
 *
 * An id is only ever generated for a row that has none, so an importer-written
 * value is never touched and a generated one never changes on a later save. The
 * pattern is how the ids already used on this page are found without loading and
 * re-saving the whole repeater.
 *
 * cards.group is deliberately NOT here. Whether the cards repeater is wired up
 * or removed is still an open question with the client, so its behaviour is left
 * exactly as it was: still required, still typed by hand.
 */
const GENERATED_ID_FIELDS = [
	'field_vs_section_id' => '/^sections_\d+_section_id$/',
	'field_vs_image_slot' => '/^images_\d+_slot$/',
];

/**
 * Namespace for generated ids.
 *
 * The importer draws its own ids from the template ("services", "first-visit",
 * "heroBg"), and none of them begins with this prefix. Keeping it reserved is
 * what guarantees a value invented here cannot land on one the importer means to
 * write. Note a re-import still replaces each repeater wholesale, so rows added
 * by hand do not survive one — that is the importer's existing contract, and the
 * prefix does not change it.
 */
const GENERATED_ID_PREFIX = 'custom-';

/**
 * Unlock an importer-owned field on a row that does not have a value yet.
 *
 * `readonly` renders the input with a readonly attribute, so it still posts — it
 * posts an empty string. Paired with `required` that is a deadlock on any row the
 * importer did not create: nothing can be typed in, and nothing can be saved
 * without it. On a new page an editor could add a Section copy row and never save
 * it. The same held for Images and Cards. Three of the six tabs were shut by a
 * rule written to protect the other three.
 *
 * The rule is right; its scope was wrong. There is nothing to protect on a row
 * that has no value, so lock the field only once it holds one. The check runs on
 * `acf/prepare_field` rather than `acf/load_field` because only prepare sees the
 * row's value — load runs before values are attached, so it cannot tell an
 * imported row from a new one.
 *
 * Section ID and Slot are no longer `required` — fill_blank_row_id() below gives
 * them a value on save — so for those two this filter is now what lets an editor
 * type a deliberate id instead of accepting a generated one. cards.group is still
 * required and still depends on this to be fillable at all.
 *
 * A repeater also renders one hidden blank template row that "Add row" clones.
 * Its value is empty too, which is precisely the case that needs unlocking.
 *
 * Note this makes the field editable exactly once, by hand, and does not stop an
 * editor clearing an imported value in some future ACF release that lets them.
 * The durable fix is to key rows on something the editor cannot see at all.
 */
function unlock_empty_importer_field( $field ) {
	// Another filter can suppress a field by returning false; pass that through.
	if ( ! is_array( $field ) ) {
		return $field;
	}

	if ( ! in_array( $field['key'] ?? '', IMPORTER_OWNED_KEYS, true ) ) {
		return $field;
	}

	if ( '' !== trim( (string) ( $field['value'] ?? '' ) ) ) {
		return $field;
	}

	$field['readonly'] = 0;

	// Two of the three get a value of their own if none is typed, so say so.
	// Telling an editor to invent an internal key is the burden this whole change
	// is removing; telling them to invent one they did not even have to is worse.
	$generated = GENERATED_ID_FIELDS;

	$field['instructions'] = isset( $generated[ $field['key'] ?? '' ] )
		? 'Leave this blank and one is created for you when you save. It ties the row '
			. 'to a place in the layout and is fixed afterwards, so only type your own '
			. 'if you are matching an id the template already uses.'
		: 'Set this once. It ties the row to a place in the layout, '
			. 'and is fixed afterwards. On a page with no hand-built template it is '
			. 'just the anchor id, so any short lowercase name will do.';

	return $field;
}
add_filter( 'acf/prepare_field', __NAMESPACE__ . '\\unlock_empty_importer_field' );

/**
 * The next unused generated id on a post, for one repeater column.
 *
 * Scans the ids that column already holds and takes the first free number, so
 * the result cannot collide with an importer value, with a hand-typed one, or
 * with a row saved earlier. Reading postmeta directly is deliberate: a repeater
 * writes each row as it goes, so by the time a later row is filtered the earlier
 * rows of the same save are already stored and visible here.
 *
 * The static list covers the case where they are not — a stale meta cache would
 * otherwise hand the same number to two blank rows in one save.
 */
function next_generated_id( int $post_id, string $meta_pattern ): string {
	static $issued = [];

	$scope = $post_id . '|' . $meta_pattern;
	$used  = $issued[ $scope ] ?? [];

	foreach ( (array) get_post_meta( $post_id ) as $meta_key => $values ) {
		if ( ! preg_match( $meta_pattern, (string) $meta_key ) ) {
			continue;
		}

		foreach ( (array) $values as $value ) {
			$used[ (string) $value ] = true;
		}
	}

	$n = 1;
	while ( isset( $used[ GENERATED_ID_PREFIX . $n ] ) ) {
		$n++;
	}

	$id = GENERATED_ID_PREFIX . $n;

	$issued[ $scope ][ $id ] = true;

	return $id;
}

/**
 * Give a blank Section ID or Slot a value on the way into the database.
 *
 * Unlocking the field was only half the fix. An editor adding a row was still
 * handed a box labelled "Section ID" and left to guess what belongs in it, and a
 * guess that duplicates an existing id silently overwrites that section's copy on
 * the site. The value is machinery, not content, so the machine should supply it.
 *
 * This runs on `acf/update_value`, which fires per sub-field per row as the
 * repeater saves, and it is the last point before the empty string is written.
 * It never overwrites: a row that already has an id — imported, generated
 * earlier, or typed — passes through untouched, which is what keeps the id
 * stable across saves and keeps the importer's ownership intact.
 */
function fill_blank_row_id( $value, $post_id, $field ) {
	if ( ! is_array( $field ) ) {
		return $value;
	}

	$generated = GENERATED_ID_FIELDS;
	$key       = (string) ( $field['key'] ?? '' );

	if ( ! isset( $generated[ $key ] ) ) {
		return $value;
	}

	// ACF also saves against option and term ids, which have no postmeta to
	// scan. These two fields only ever live on a page, so anything else is not
	// ours to touch.
	if ( ! is_numeric( $post_id ) ) {
		return $value;
	}

	$current = is_string( $value ) ? trim( $value ) : $value;

	if ( '' !== $current && null !== $current ) {
		return $value;
	}

	return next_generated_id( (int) $post_id, $generated[ $key ] );
}
add_filter( 'acf/update_value', __NAMESPACE__ . '\\fill_blank_row_id', 10, 3 );

/**
 * The section repeater's Button label and Button link, retired from the editor.
 *
 * Nothing renders them. None of the 32 hand-built page templates reads a
 * section's cta_label or cta_href — only the catch-all [...slug].astro route
 * does, and it builds zero pages — and every one of the 213 imported section
 * rows has both empty. Two boxes that accept typing and change nothing is
 * precisely the failure this content model exists to prevent, so the editor
 * should not be shown them.
 *
 * They are hidden rather than deleted because deleting them here alone takes the
 * build down. src/loaders/pages.ts selects `ctaLabel` and `ctaHref` inside
 * `sections` in PAGES_QUERY, unconditionally, for every page; remove the
 * sub-fields and WPGraphQL for ACF stops registering those two fields, the query
 * becomes invalid, and src/lib/wp.ts treats a query-level error as fatal and
 * never retries. The next deploy would fail outright. Nothing is lost by waiting:
 * the values are empty, and a hidden field is as invisible to an editor as a
 * deleted one.
 *
 * To finish the job, in a single change: drop the two fields from PAGES_QUERY,
 * from the PageNode type and from the sections mapping in src/loaders/pages.ts;
 * drop cta_label/cta_href from the sections object in src/content.config.ts and
 * from Section and EMPTY_SECTION in src/lib/page-content.ts; drop them from the
 * row shape in cms/import/import-sections.php; then delete the two sub-fields
 * above. If instead a section button is ever wanted for real, the template has to
 * render it in the same change that unhides the fields — the rule the hero fields
 * were removed under.
 */
const RETIRED_SECTION_KEYS = [
	'field_vs_section_cta_label',
	'field_vs_section_cta_href',
];

function hide_retired_section_fields( $field ) {
	if ( ! is_array( $field ) ) {
		return $field;
	}

	// Returning false is how ACF is told not to render a field at all.
	return in_array( $field['key'] ?? '', RETIRED_SECTION_KEYS, true ) ? false : $field;
}
add_filter( 'acf/prepare_field', __NAMESPACE__ . '\\hide_retired_section_fields' );

/**
 * Expose the post's "last updated" timestamp to GraphQL.
 *
 * The Astro schema has an optional `updated` field which [slug].astro uses for
 * JSON-LD dateModified. WPGraphQL exposes `modified` already, but it changes on
 * every trivial save — including a typo fix that shouldn't reset the "freshness"
 * signal. This surfaces the editor's explicit intent instead.
 */
/**
 * Expose each page's canonical Astro route to GraphQL.
 *
 * The Astro loader keys pages by route, and WordPress's own `uri` is the wrong
 * source for it: the home page is stored with the slug "home", so its uri is
 * "/home/" while the route it serves is "/". Slug edits and parent changes move
 * `uri` too, which would silently detach a page's content from its template.
 *
 * The importer writes _vs_route and nothing else changes it, so it stays
 * authoritative.
 */
function register_route_field(): void {
	if ( ! function_exists( 'register_graphql_field' ) ) {
		return;
	}

	register_graphql_field(
		'Page',
		'vsRoute',
		[
			'type'        => 'String',
			'description' => 'The Astro route this page supplies content for, e.g. "/cosmetic-dentistry/porcelain-veneers/".',
			'resolve'     => static function ( $post ) {
				$route = get_post_meta( $post->databaseId, '_vs_route', true );

				return $route ? (string) $route : null;
			},
		]
	);
}
add_action( 'graphql_register_types', __NAMESPACE__ . '\\register_route_field' );

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

/* -----------------------------------------------------------------------------
 * ALWAYS-PUBLISH — post warnings
 *
 * A blog post publishes no matter what is missing. There is no longer any state
 * an author can leave a post in that makes it vanish from the site: no hero
 * image, no alt text, no excerpt, an unexpected category — the build renders it
 * anyway, substituting a fallback. That is the whole point, and it is also the
 * hazard, because a post that always publishes never tells anyone it is
 * imperfect. Nothing about a missing hero is visible from the edit screen.
 *
 * So the edit screen says it. Everything below computes, in PHP, the same
 * conditions src/loaders/blog.ts computes in TypeScript, and shows them as a
 * warning above the post — before the author wonders, rather than after a
 * reader notices.
 *
 * These are WARNINGS, never errors. Nothing here blocks a save or a publish,
 * and none of the copy mentions builds, loaders, schemas or GraphQL. An author
 * is told what a reader will miss and where to fix it. An error style that does
 * not actually stop anything just teaches people to click past errors.
 *
 *
 * THE PAIR — and why this is more than a comment
 *
 * These rules now exist twice, in two languages:
 *
 *   HERE                        cms/mu-plugins/vs-content-model.php
 *                               post_warning_rules() + post_warnings()
 *   THERE                       src/loaders/blog.ts
 *                               the fallbacks applied per field while building
 *                               each entry, and content.config.ts's schema
 *
 * They are a pair. Changing one without the other is the failure mode: the
 * wp-admin warning quietly stops matching what the site actually does, and an
 * author is either nagged about something harmless or reassured about something
 * broken. The second is worse and neither is detectable by reading either file.
 *
 * A comment naming the other file is the minimum and it is not enough — this
 * repo has already learned that. vs-admin.php derives the category list from
 * BLOG_CATEGORIES rather than copying it (vs-admin.php:78-88) precisely because
 * a copied list is a second thing to forget.
 *
 * The strongest mechanism available from a single PHP file is to stop treating
 * the rules as documentation and start SERVING them, so the other half of the
 * pair can check itself against them at build time on real data:
 *
 *   vsPostWarningContract { revision rules { code field text fallback } }
 *       The rule table itself, machine-readable, over GraphQL. One place the
 *       codes, the author-facing copy and the name of each fallback are
 *       written down. `revision` is bumped whenever a rule is added, removed
 *       or has its predicate changed; the loader pins the revision it was
 *       written against and warns — never fails — when the numbers differ.
 *       That turns "somebody edited PHP and forgot the TypeScript" from
 *       invisible into a line in the build log naming the revision.
 *
 *   Post.vsWarnings: [String]
 *       WordPress's own verdict for one post, as a list of codes, computed by
 *       the same function that renders the notice. The loader already computes
 *       these conditions to decide what to render; comparing the two lists per
 *       post is the only check that catches genuine PREDICATE drift, because it
 *       compares outputs on live content rather than trusting either side's
 *       description of itself. Mismatch is a build WARNING and must stay one:
 *       editors trigger deploys (vs-deploy.php:142-158), so an editor must
 *       never be able to break one.
 *
 * Neither field is queried by the production build today, so neither can take a
 * deploy down; they are additive fields on an existing type. Wiring the
 * comparison up is the TypeScript side's half of the change.
 *
 * A note on where this lives: it is admin UI, so vs-admin.php would be the more
 * natural home. It is here because the rules are statements about the field
 * definitions directly above — hero_alt, the category list, the featured image
 * — and splitting a rule from the field it describes is how the copy drifts. If
 * this section moves, BLOG_CATEGORIES has to be read through a guard the way
 * vs-admin.php's canonical_categories() does.
 * -------------------------------------------------------------------------- */

/**
 * Bump on ANY change to the rule table or to post_warnings() below.
 *
 * The number is meaningless on its own; its whole job is to differ from the one
 * src/loaders/blog.ts pins, so that a change made on one side of the pair shows
 * up in the next build log instead of never.
 */
const POST_WARNING_CONTRACT_REVISION = 2;

/**
 * The rule table: one entry per thing that can be imperfect about a post.
 *
 * `field`     the control on the edit screen the author should go to.
 * `text`      what is shown to the author. Phrased as what the READER loses.
 *             A `%s` is filled from the args post_warnings() returns; entries
 *             with no args must contain no percent signs.
 * `fallback`  short name for what the site does instead, for the contract.
 *
 * The order here is the order the notice lists them in: the title first because
 * it is the loudest, then the image, then the words around it.
 */
function post_warning_rules(): array {
	return [
		'no_title'           => [
			'field'    => 'Title',
			'text'     => 'This post has no title. It will read “Untitled post” on the blog, in Google results, '
				. 'and in anything anyone shares.',
			'fallback' => 'Untitled post',
		],
		'no_hero'            => [
			'field'    => 'Featured image',
			'text'     => 'No featured image is set. On the blog listing — and in the preview someone sees when the '
				. 'link is shared on Facebook or in a text message — this post will show the site’s standard '
				. 'Vivid Smiles image instead of a picture of its own.',
			'fallback' => 'placeholder image',
		],
		// Two codes, because the outcomes are opposite and a single message would
		// have to lie about one of them. Mirrors src/loaders/blog.ts:446-462,
		// where the loader picks between them on whether measuring worked.
		'hero_no_dimensions' => [
			'field'    => 'Featured image',
			'text'     => 'WordPress has not recorded the size of the featured image, so the site had to measure the '
				. 'file itself while building. Your picture is used and readers see it as normal — this only '
				. 'makes the build a little slower. Regenerating thumbnails clears it.',
			'fallback' => 'size measured during the build; your picture is used',
		],
		'hero_missing_file'  => [
			'field'    => 'Featured image',
			'text'     => 'The featured image is still attached to this post, but the file itself is no longer on the '
				. 'server — usually because it was deleted from the Media Library while this post kept '
				. 'pointing at it. The post publishes and shows the standard Vivid Smiles image. Choose a new '
				. 'featured image to fix it.',
			'fallback' => 'placeholder image',
		],
		'hero_unmeasurable'  => [
			'field'    => 'Featured image',
			'text'     => 'The featured image has no recorded size and the file could not be read either, which '
				. 'usually means the upload did not finish. The post publishes, but it shows the standard '
				. 'Vivid Smiles image instead of your picture. Re-upload it as a JPG, PNG or WebP.',
			'fallback' => 'placeholder image',
		],
		'no_alt'             => [
			'field'    => 'Hero image alt text',
			'text'     => 'The featured image has no alt text. Someone using a screen reader will be told nothing about '
				. 'the picture, and it will not be found in Google Images. Fill in “Hero image alt text” in the '
				. 'panel beside the post, or set the alt text on the file itself in the Media Library.',
			'fallback' => 'unlabelled image',
		],
		'no_category'        => [
			'field'    => 'Categories',
			'text'     => 'No category is ticked, so this post will be filed under “%s” on the blog.',
			'fallback' => 'default category',
		],
		'unknown_category'   => [
			'field'    => 'Categories',
			// The chip rail is built from the five known names only (getCategories()
			// in src/lib/blog.ts), so an unexpected category gets no button at all.
			// The earlier wording promised the opposite.
			'text'     => 'This post is filed under “%s”, which is not one of the five the blog is built around. '
				. 'It keeps that name and still appears on the blog under “All Articles”, but it gets no '
				. 'filter button of its own, and tapping its category label just shows everything again. '
				. 'Tick one of the five to have it appear under a filter.',
			'fallback' => 'category kept as written; no filter button',
		],
		'no_excerpt'         => [
			'field'    => 'Excerpt',
			'text'     => 'No excerpt has been written. Google and Facebook will quote the opening sentences of the '
				. 'post, cut off wherever they run out of room, rather than a summary you chose. Add one in the '
				. 'Excerpt box — under Screen Options if you cannot see it.',
			'fallback' => 'opening sentences of the post',
		],
	];
}

/**
 * What is imperfect about one post.
 *
 * Returns [ code => args ], preserving the rule table's order. Empty means the
 * post is in good shape.
 *
 * Computed on read rather than stored in postmeta on save. Stored meta would
 * need the two-hook dance vs-admin.php:499-511 does — the block editor assigns
 * terms after save_post — and would still go stale the moment anything changed
 * a post without going through those hooks: WP-CLI, an import, or simply
 * deleting the featured image from the Media Library. Reading the live post
 * cannot be wrong, and it is a handful of already-cached lookups.
 *
 * Every emptiness test trims first, and the category comparison is
 * case-insensitive, because src/loaders/blog.ts:224-225 and
 * vs-admin.php:363,428 do. Those details are exactly where two implementations
 * of the same rule quietly stop agreeing.
 */
/**
 * Codes that deliberately exist on only one side, so a vocabulary diff between
 * this file and src/loaders/blog.ts reports drift rather than design.
 *
 * PHP only:
 *   no_excerpt          — an editorial nudge. The build does not care: it falls
 *                         back to the opening sentences either way.
 *
 * TypeScript only:
 *   no_date             — WordPress cannot produce a post without a date. The
 *                         loader checks anyway because GraphQL can return null.
 *   hero_url_unusable   — a sourceUrl that will not parse as a URL. Only an
 *                         offload plugin emits one, and it is not visible from
 *                         inside WordPress.
 *
 * Everything else must appear on both sides with the same meaning. If you add a
 * code here, add it there, and bump POST_WARNING_CONTRACT_REVISION.
 */
function post_warnings( \WP_Post $post ): array {
	$found = [];

	if ( '' === trim( wp_strip_all_tags( (string) get_the_title( $post ) ) ) ) {
		$found['no_title'] = [];
	}

	// A thumbnail id can outlive the attachment it points at — deleting the file
	// from the Media Library leaves the meta behind. WPGraphQL reports that as a
	// null sourceUrl and the loader treats it as no hero, so this must too.
	$thumb_id = (int) get_post_thumbnail_id( $post );
	$has_hero = $thumb_id > 0 && '' !== (string) wp_get_attachment_url( $thumb_id );

	if ( ! $has_hero ) {
		$found['no_hero'] = [];
	} else {
		$meta   = wp_get_attachment_metadata( $thumb_id );
		$width  = is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$height = is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0;

		if ( $width < 1 || $height < 1 ) {
			// Which of the two the build will hit depends on whether it can read
			// the file, so decide the same way here rather than guessing: a file
			// that is missing from disk, or is not a raster the build can
			// measure, ends up on the placeholder; anything else gets measured
			// during the build and the author's picture is used.
			// Mirrors src/loaders/blog.ts:446-462.
			$path = get_attached_file( $thumb_id );
			$mime = (string) get_post_mime_type( $thumb_id );

			if ( ! $path || ! file_exists( $path ) ) {
				// The build's HEAD probe reports this as hero_missing_file.
				$found['hero_missing_file'] = [];
			} elseif ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ], true ) ) {
				$found['hero_unmeasurable'] = [];
			} else {
				$found['hero_no_dimensions'] = [];
			}
		}

		// hero_alt first, then the file's own alt text — the same order and the
		// same fallback as the loader. get_field() is guarded because this file
		// must not fatal if SCF is ever deactivated: a fatal in a must-use plugin
		// takes wp-admin down, and there is no way to switch it off from inside.
		$alt = function_exists( 'get_field' ) ? trim( (string) get_field( 'hero_alt', $post->ID ) ) : '';

		if ( '' === $alt ) {
			$alt = trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) );
		}

		if ( '' === $alt ) {
			$found['no_alt'] = [];
		}
	}

	// The FIRST category and nothing else, matching the loader's
	// categories.nodes[0]. In practice there is only ever one —
	// vs-admin.php's normalise_categories() reduces every save to a single term
	// — so "first" is academic; it is spelled out because if that ever stops
	// being true, both sides have to pick the same one.
	$terms = get_the_terms( $post, 'category' );
	$terms = is_array( $terms ) ? array_values( $terms ) : [];

	if ( ! $terms ) {
		// BLOG_CATEGORIES[0] rather than get_option('default_category'): it is
		// what the loader falls back to, and naming the option instead would let
		// the notice promise something the site does not do.
		$found['no_category'] = [ BLOG_CATEGORIES[0] ];
	} else {
		$name  = (string) $terms[0]->name;
		$known = false;

		foreach ( BLOG_CATEGORIES as $canonical ) {
			if ( 0 === strcasecmp( trim( $name ), $canonical ) ) {
				$known = true;
				break;
			}
		}

		if ( ! $known ) {
			$found['unknown_category'] = [ $name ];
		}
	}

	// post_excerpt, deliberately, not get_the_excerpt(): WordPress generates one
	// from the body when the box is empty, and WPGraphQL serves the generated
	// version — verified against the live endpoint, where posts with no written
	// excerpt come back as the first ~55 words ending in an ellipsis. So there
	// is nothing here the build can fail on, only a summary nobody chose. That
	// is what the warning is about.
	if ( '' === trim( (string) $post->post_excerpt ) ) {
		$found['no_excerpt'] = [];
	}

	// Reorder to the rule table, so the notice reads the same way every time no
	// matter what order the checks above happen to run in. A no-op today; it
	// stops being one the first time a check is moved.
	return array_replace( array_intersect_key( post_warning_rules(), $found ), $found );
}

/**
 * The warnings for one post as finished sentences.
 */
function post_warning_messages( \WP_Post $post ): array {
	$rules    = post_warning_rules();
	$messages = [];

	foreach ( post_warnings( $post ) as $code => $args ) {
		$text = (string) ( $rules[ $code ]['text'] ?? '' );

		if ( '' === $text ) {
			continue;
		}

		$messages[] = $args ? vsprintf( $text, $args ) : $text;
	}

	return $messages;
}

/**
 * The post the current admin screen is editing, if it is a blog post.
 */
function warning_screen_post(): ?\WP_Post {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return null;
	}

	$screen = get_current_screen();

	if ( ! $screen || 'post' !== $screen->id || 'post' !== $screen->post_type ) {
		return null;
	}

	$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

	if ( ! $post_id ) {
		return null;
	}

	$post = get_post( $post_id );

	// An auto-draft is a post nobody has written yet. Greeting a blank screen
	// with six complaints is not a warning, it is a wall.
	if ( ! $post instanceof \WP_Post || 'auto-draft' === $post->post_status ) {
		return null;
	}

	return $post;
}

/**
 * The notice itself.
 *
 * Persistent by design, unlike vs-admin.php's category_fix_notice(), which
 * deletes its meta as it prints because it reports a change already made. This
 * one reports a state that is still true, so it recomputes on every load and
 * keeps saying so until the post is fixed. It disappears by itself the moment
 * the last item is dealt with, which is the only dismissal anyone needs.
 */
function post_warning_notice(): void {
	$post = warning_screen_post();

	if ( ! $post instanceof \WP_Post ) {
		return;
	}

	$messages = post_warning_messages( $post );

	if ( ! $messages ) {
		return;
	}

	$items = '';

	foreach ( $messages as $message ) {
		$items .= '<li>' . esc_html( $message ) . '</li>';
	}

	printf(
		'<div class="notice notice-warning"><p><strong>%s</strong> %s</p><ul style="list-style:disc;margin-left:2em">%s</ul></div>',
		esc_html( 'This post will publish exactly as it is.' ),
		esc_html( 'These are the things a reader would notice are missing:' ),
		$items // Each <li> was escaped as it was built.
	);
}
add_action( 'admin_notices', __NAMESPACE__ . '\\post_warning_notice' );

/**
 * The same count on the Posts list.
 *
 * The notice only helps somebody already on the right screen, which means
 * finding out which post needs attention costs one click per post. A column
 * turns that into a glance down a list. Nothing is shown for a post with
 * nothing wrong, so a healthy blog shows an empty column rather than a row of
 * ticks nobody reads.
 */
function add_post_warning_column( array $columns ): array {
	$out = [];

	foreach ( $columns as $key => $label ) {
		// Before the date, which is conventionally last but one.
		if ( 'date' === $key ) {
			$out['vs_warnings'] = 'Needs attention';
		}

		$out[ $key ] = $label;
	}

	if ( ! isset( $out['vs_warnings'] ) ) {
		$out['vs_warnings'] = 'Needs attention';
	}

	return $out;
}
add_filter( 'manage_post_posts_columns', __NAMESPACE__ . '\\add_post_warning_column' );

function render_post_warning_column( $column, $post_id ): void {
	if ( 'vs_warnings' !== $column ) {
		return;
	}

	$post = get_post( (int) $post_id );

	if ( ! $post instanceof \WP_Post ) {
		return;
	}

	$messages = post_warning_messages( $post );

	if ( ! $messages ) {
		return;
	}

	printf(
		'<span title="%s">%s</span>',
		esc_attr( implode( "\n\n", $messages ) ),
		esc_html(
			sprintf(
				1 === count( $messages ) ? '%d thing' : '%d things',
				count( $messages )
			)
		)
	);
}
add_action( 'manage_post_posts_custom_column', __NAMESPACE__ . '\\render_post_warning_column', 10, 2 );

/**
 * Serve the rule table, and each post's verdict, to the site build.
 *
 * See THE PAIR at the head of this section for why. Both fields are additive
 * and unqueried by the production build today, so neither can break a deploy;
 * they exist so the TypeScript half can check itself against this file instead
 * of being trusted to match it.
 */
function register_post_warning_graphql(): void {
	if ( ! function_exists( 'register_graphql_field' ) || ! function_exists( 'register_graphql_object_type' ) ) {
		return;
	}

	register_graphql_object_type(
		'VsPostWarningRule',
		[
			'description' => 'One thing that can be imperfect about a blog post. Never blocks publication.',
			'fields'      => [
				'code'     => [
					'type'        => 'String',
					'description' => 'Stable identifier, e.g. "no_hero". Compare against Post.vsWarnings.',
				],
				'field'    => [
					'type'        => 'String',
					'description' => 'The control in wp-admin an author fixes this in.',
				],
				'text'     => [
					'type'        => 'String',
					'description' => 'The sentence shown to the author. May contain a %s placeholder.',
				],
				'fallback' => [
					'type'        => 'String',
					'description' => 'What the site substitutes instead, in short.',
				],
			],
		]
	);

	register_graphql_object_type(
		'VsPostWarningContract',
		[
			'description' => 'The post-warning rules as WordPress computes them, for the Astro build to check itself against.',
			'fields'      => [
				'revision' => [
					'type'        => 'Int',
					'description' => 'Bumped whenever a rule changes. A build pinned to a different number should warn.',
				],
				'rules'    => [ 'type' => [ 'list_of' => 'VsPostWarningRule' ] ],
			],
		]
	);

	register_graphql_field(
		'RootQuery',
		'vsPostWarningContract',
		[
			'type'    => 'VsPostWarningContract',
			'resolve' => static function () {
				$rules = [];

				foreach ( post_warning_rules() as $code => $rule ) {
					$rules[] = [
						'code'     => $code,
						'field'    => $rule['field'],
						'text'     => $rule['text'],
						'fallback' => $rule['fallback'],
					];
				}

				return [
					'revision' => POST_WARNING_CONTRACT_REVISION,
					'rules'    => $rules,
				];
			},
		]
	);

	register_graphql_field(
		'Post',
		'vsWarnings',
		[
			'type'        => [ 'list_of' => 'String' ],
			'description' => 'Codes for what is imperfect about this post. Never a reason to skip it.',
			'resolve'     => static function ( $post ) {
				$wp_post = get_post( $post->databaseId );

				return $wp_post instanceof \WP_Post ? array_keys( post_warnings( $wp_post ) ) : [];
			},
		]
	);
}
add_action( 'graphql_register_types', __NAMESPACE__ . '\\register_post_warning_graphql' );
