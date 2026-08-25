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
 * The five backgrounds a section can paint itself.
 *
 * A CLOSED list, and the reason it is closed is measured: across the 34 page
 * stylesheets `.alt` and `.dark` mean opposite things on different pages —
 * porcelain-veneers.css:244 paints `.section.alt` charcoal green while
 * teeth-whitening.css:237 paints it cream — so a section that inherits its
 * background from an ancestor class is wrong on at least one page the moment it
 * is reordered or moved. The background has to be a VALUE the section carries,
 * and these five cover every background found in the corpus.
 *
 * The keys are the contract: they become the block's own modifier class in the
 * Astro component (`vs-<block>--charcoal`), so renaming one silently unstyles
 * every section already saved with the old value. The labels are editor-facing
 * and safe to reword.
 */
const BLOCK_BANDS = [
	'paper'     => 'Paper — white',
	'cream'     => 'Cream',
	'sage-pale' => 'Pale sage',
	'sage'      => 'Sage',
	'charcoal'  => 'Charcoal green — dark, white text',
];

/**
 * The bands an editor can place but not author.
 *
 * Some sections have to stay in code — they carry JavaScript, a third-party
 * embed, or a data contract, and docs/PAGE-BLOCKS.md §7 lists them. But "stays in
 * code" and "cannot be positioned by an editor" are two different claims, and
 * treating them as one is what moved the practice band on the pilot page: the
 * template drew LocalTrust outside the `blocks` switch, so PageBlocks rendered
 * the eight editor-ordered sections and the ninth band landed wherever the
 * template happened to put it — the bottom. A code_section row is that band's
 * PLACE in the list. Movable, removable, not editable.
 *
 * A SELECT, never free text. The value names a component. Free text would let an
 * editor name a band that does not exist, and an unrecognised key renders as
 * nothing — on a layout that has no other content, that is a section which
 * silently is not there, with nothing in wp-admin to say why.
 *
 * The keys are the contract, exactly as in BLOCK_BANDS above: each is looked up
 * on the Astro side in src/blocks/registry.ts, in the same commit. Adding the
 * next of the ~45 bespoke bands is one line here and one entry there. Renaming a
 * key orphans every row already saved with the old one. REMOVING a key is worse
 * than it looks — the rows keep the value, the select can no longer display it,
 * and the band stops rendering with no error anywhere; deprecate instead, which
 * here means leaving the key registered so saved rows keep drawing (R4).
 *
 * The labels are editor-facing and safe to reword. Write them as the section a
 * client would recognise on the page, not as the component name.
 */
const BLOCK_CODE_BANDS = [
	'local_trust' => 'Why patients choose us — map, reviews and address',
];

/**
 * The six controls every section layout opens with, in the same order.
 *
 * Shared rather than copied per layout for two reasons. The editor learns one
 * control set and it is identical whichever kind of section they add — that is
 * the whole point of the preamble. And the Astro side reads these six off every
 * block regardless of type, so a layout that quietly diverged (a missing
 * `band`, a `heading` renamed) would render wrong only on the one page that
 * used it, which is the hardest kind of fault to find here.
 *
 * $slug only namespaces the field KEYS, which ACF requires to be globally
 * unique. The field NAMES are identical across layouts on purpose: they are
 * what the meta keys and the GraphQL selection set are built from.
 */
function block_preamble( string $slug ): array {
	$k = 'field_vs_blk_' . $slug . '_';

	return [
		[
			'key'          => $k . 'anchor',
			'label'        => 'Anchor',
			'name'         => 'anchor',
			// Not required of the editor: fill_blank_row_id() below supplies one
			// on save, exactly as it does for Section ID. Asking a non-technical
			// person to invent a URL fragment is how you get two sections sharing
			// one — which is invalid HTML and sends every jump link, and the
			// scroll offset that goes with it, to the wrong section.
			'required'     => 0,
			'type'         => 'text',
			'instructions' => 'The name of this section in the page address — the part after the #, as in '
				. '…/clear-aligners/#process. Leave it blank and one is made for you when you save. '
				. 'It is what the “On this page” rail jumps to and what anyone who has linked to or '
				. 'bookmarked this section is using, so changing it afterwards breaks those links.',
		],
		[
			'key'          => $k . 'nav_label',
			'label'        => 'Rail label',
			'name'         => 'nav_label',
			'type'         => 'text',
			'instructions' => 'What this section is called in the “On this page” rail down the side. '
				. 'Leave it blank to keep the section out of the rail.',
		],
		[
			'key'           => $k . 'band',
			'label'         => 'Background',
			'name'          => 'band',
			'type'          => 'select',
			'choices'       => BLOCK_BANDS,
			// Applies only to a row an editor ADDS — a default on a sub-field of a
			// flexible layout is read when that row is created, and there are no
			// rows anywhere yet. It exists so a new section can never save with no
			// background at all, which would render it unstyled.
			'default_value' => 'paper',
			'return_format' => 'value',
			'allow_null'    => 0,
			'multiple'      => 0,
			'ui'            => 0,
			'instructions'  => 'The colour behind this section. Alternating them is what gives the page its rhythm; '
				. 'two of the same in a row read as one long section.',
		],
		[
			'key'   => $k . 'eyebrow',
			'label' => 'Eyebrow',
			'name'  => 'eyebrow',
			'type'  => 'text',
		],
		[
			'key'          => $k . 'heading',
			'label'        => 'Heading',
			'name'         => 'heading',
			'type'         => 'textarea',
			'rows'         => 2,
			'instructions' => 'May contain <em>…</em> for the accent styling.',
		],
		[
			'key'          => $k . 'body',
			'label'        => 'Body',
			'name'         => 'body',
			'type'         => 'textarea',
			'rows'         => 4,
			'instructions' => 'Plain text. The intro paragraph under the heading.',
		],
	];
}

/**
 * The preamble a code-owned section gets: the two controls that still mean
 * something, and not the four that do not.
 *
 * `anchor` and `nav_label` stay because neither is about the band's CONTENT —
 * they are how the rest of the page reaches it. The "On this page" rail is built
 * from `blocks[].anchor` + `blocks[].nav_label` in block order, so without them
 * a code-owned band cannot be linked to and drops out of a rail it has always
 * been listed in. Both survive a reorder, which is the entire point of the row.
 *
 * `band`, `eyebrow`, `heading` and `body` are dropped because the component
 * already draws all four and nothing here can reach them. A control that posts a
 * value the renderer never reads is precisely the fault this field group keeps
 * removing: the editor sets Background to charcoal, saves, waits for the deploy,
 * and the section is still cream. Note LocalTrust.astro does take a `background`
 * prop, which makes `band` look wire-able — it is not, because this one layout
 * stands in for every band in BLOCK_CODE_BANDS and almost none of the ~45 has
 * such a prop. One band honouring the control and forty-four ignoring it, with
 * no way for the editor to tell which they are looking at, is worse than not
 * offering it.
 *
 * Derived from block_preamble() rather than written out so the two instruction
 * strings keep one author. If a preamble sub-field is ever renamed this returns
 * fewer than two fields and says so in the log, instead of registering a layout
 * that quietly lost its anchor.
 */
function block_code_preamble( string $slug ): array {
	$keep = [ 'anchor', 'nav_label' ];

	$fields = array_values(
		array_filter(
			block_preamble( $slug ),
			static function ( array $field ) use ( $keep ): bool {
				return in_array( $field['name'] ?? '', $keep, true );
			}
		)
	);

	if ( count( $fields ) !== count( $keep ) ) {
		error_log(
			sprintf(
				'vs-content-model: block_code_preamble() matched %d of %d sub-fields. '
					. 'A preamble field has been renamed, so the code_section layout is '
					. 'registering without one of anchor/nav_label.',
				count( $fields ),
				count( $keep )
			)
		);
	}

	return $fields;
}

/**
 * A repeater that holds a plain list of lines — a checklist, a set of bullets.
 *
 * ACF has no "list of strings" field, so the shape is a repeater with a single
 * text sub-field named `item`. Named here once so every list on every layout
 * has the same shape and the Astro side can read `{ item }` off all of them.
 */
function block_list_field( string $key, string $label, string $name, string $button = 'Add line' ): array {
	return [
		'key'          => $key,
		'label'        => $label,
		'name'         => $name,
		'type'         => 'repeater',
		'layout'       => 'table',
		'button_label' => $button,
		'sub_fields'   => [
			[
				'key'   => $key . '_item',
				'label' => 'Line',
				'name'  => 'item',
				'type'  => 'text',
			],
		],
	];
}

/**
 * The image sub-field used inside a section layout and by the hero.
 *
 * Identical settings to the Images repeater below, and for the identical
 * reasons: the Astro loader needs a URL plus intrinsic dimensions because
 * <Image> refuses a remote source without them, and the mime list keeps bmp and
 * ico out of a build that hands every one of these URLs to sharp.
 */
function block_image_field( string $key, string $label = 'Image', string $name = 'image' ): array {
	return [
		'key'           => $key,
		'label'         => $label,
		'name'          => $name,
		'type'          => 'image',
		'return_format' => 'array',
		'preview_size'  => 'thumbnail',
		'library'       => 'all',
		'mime_types'    => 'webp,jpg,jpeg,png',
	];
}

/**
 * Field groups.
 *
 * Requires Secure Custom Fields (WordPress.org's ACF fork), not ACF free — the
 * page group below uses `repeater` and `flexible_content`, both of which ACF
 * charges for and SCF ships free. See cms/bin/setup.sh.
 */
/**
 * Refuse to let two containers mint the same GraphQL type.
 *
 * WPGraphQL for ACF names the type after the PATH OF FIELD NAMES, not after the
 * layout that happens to contain them. Confirmed against the live schema by
 * asking each container for a field it does not have and reading the type back
 * out of the error:
 *
 *   pageFields > cards                         → PageFieldsCards
 *   pageFields > hero > ctas                   → PageFieldsHeroCtas
 *   pageFields > blocks > [card_grid] > cards  → PageFieldsBlocksCards
 *   pageFields > blocks > [comparison_cards] > tiers > bullets
 *                                              → PageFieldsBlocksTiersBullets
 *   practiceSettingsFields > office_hours      → PracticeSettingsFieldsOfficeHours
 *
 * Three things follow, and all three are why this check is not a name tally.
 *
 * The LAYOUT contributes nothing. That is the card_grid/comparison_cards bug:
 * both called a repeater `cards`, both wanted PageFieldsBlocksCards, card_grid
 * registered first and won, and comparison_cards' sub-fields became unqueryable
 * while the type itself still existed — so the schema looked healthy and the
 * blocks capability probe read the resulting error as "not deployed yet" and
 * switched the whole feature off.
 *
 * DEPTH DOES count, because every ancestor field name is in the type name. The
 * top-level `cards` repeater (187 rows) and card_grid's `cards` are
 * PageFieldsCards and PageFieldsBlocksCards — same name, different types, no
 * collision. Both are queryable on the live schema right now, `group` on one and
 * `lead` on the other, which is the proof. A guard that flagged that pair would
 * cost a rename of 187 rows of live content for nothing.
 *
 * But the segments are CONCATENATED, so paths that are different can still
 * arrive at one string: a top-level repeater named `blocks_cards` also mints
 * PageFieldsBlocksCards. Hence the collision key here is the assembled type
 * name, which catches the aliases as well as the obvious cases.
 *
 * Sub-field names within one container are free to repeat — only repeaters,
 * groups and flexible-content layouts mint types.
 *
 * The cost of finding this again in Phase 3, with twenty more layouts, is a day.
 * The cost of this function is a notice in the error log at registration. It
 * logs and never throws: this is a must-use plugin, and one that dies takes
 * wp-admin with it. A naming problem is not worth that.
 */

/**
 * One segment of a GraphQL type name, formatted the way the schema will format
 * it — `office_hours` becomes `OfficeHours`.
 *
 * Delegates to WPGraphQL's own formatter when it is loaded, because this string
 * has to match the schema exactly and a private reimplementation that drifts
 * from theirs would report collisions that do not exist. The fallback is only
 * for the case where this runs before WPGraphQL does.
 */
function graphql_type_segment( string $name ): string {
	if ( class_exists( '\WPGraphQL\Utils\Utils' ) ) {
		if ( method_exists( '\WPGraphQL\Utils\Utils', 'format_type_name' ) ) {
			return (string) \WPGraphQL\Utils\Utils::format_type_name( $name );
		}

		if ( method_exists( '\WPGraphQL\Utils\Utils', 'format_field_name' ) ) {
			return ucfirst( (string) \WPGraphQL\Utils\Utils::format_field_name( $name ) );
		}
	}

	return str_replace( ' ', '', ucwords( (string) preg_replace( '/[^a-zA-Z0-9]+/', ' ', $name ) ) );
}

/**
 * What a container holds, as a comparable string: each direct sub-field's name
 * and type, sorted.
 *
 * Name AND type, because two containers merged under one type name resolve
 * their values through whichever ACF field definition registered first. Same
 * names carrying different types would hand a row to the wrong formatter.
 *
 * Direct sub-fields only. A container nested deeper mints a type of its own and
 * is compared on its own terms by the walk below.
 */
function container_shape( array $fields ): string {
	$shape = [];

	foreach ( $fields as $field ) {
		$shape[] = ( $field['name'] ?? '?' ) . ':' . ( $field['type'] ?? '?' );
	}

	sort( $shape );

	return implode( ',', $shape );
}

/**
 * Walk one level of the local field store, recording the type every container
 * will mint, and recurse.
 *
 * Reads ACF's FLATTENED store rather than the literal arrays passed to
 * acf_add_local_field_group(). acf_add_local_fields() runs every field through
 * acf/prepare_field_for_import, and the repeater and flexible-content types use
 * that hook to lift their children out into siblings carrying `parent` (and, for
 * a layout's children, `parent_layout`) — class-acf-field-flexible-content.php
 * calls acf_extract_var( $layout, 'sub_fields' ), which UNSETS them. So a
 * registered layout has no sub_fields to read, and the previous version of this
 * check — which walked `$field['layouts'][n]['sub_fields']` — iterated an empty
 * array on every layout and could never have reported anything. It passed its
 * own self-test because the test handed it the literal arrays.
 *
 * The flat store happens to model the type naming exactly: a layout's children
 * hang off the flexible field, not off the layout, which is precisely why the
 * layout name is absent from their type names.
 *
 * $layouts maps layout key to layout name, and is used only to write a path a
 * human can follow back to the PHP. It never enters a type name.
 */
function walk_graphql_containers( array $fields, string $type_prefix, string $path, array $layouts, array &$found, int $depth = 0 ): void {
	// The store is a tree, so this cannot recurse forever unless the store is
	// malformed — in which case a must-use plugin looping is a white screen on
	// wp-admin. Ten is far deeper than any field group here.
	if ( $depth > 10 ) {
		error_log( 'vs-content-model: field nesting deeper than 10 at ' . $path . '; the type-name check stopped there.' );

		return;
	}

	foreach ( $fields as $field ) {
		$type = (string) ( $field['type'] ?? '' );
		$name = (string) ( $field['name'] ?? '' );

		$is_container = in_array( $type, [ 'repeater', 'group' ], true );
		$is_flexible  = 'flexible_content' === $type;

		if ( '' === $name || ( ! $is_container && ! $is_flexible ) ) {
			continue;
		}

		// A field kept out of the schema mints no type and cannot collide.
		if ( isset( $field['show_in_graphql'] ) && ! $field['show_in_graphql'] ) {
			continue;
		}

		$in_layout = (string) ( $layouts[ $field['parent_layout'] ?? '' ] ?? '' );
		$here      = $path . ( '' !== $in_layout ? ' > [' . $in_layout . ']' : '' ) . ' > ' . $name;

		// wpgraphql-acf builds the segment from `graphql_field_name` when a field
		// carries one, and falls back to the field name. Nothing here sets it, but
		// reading it means the guard stays right if something ever does.
		$type_name = $type_prefix . graphql_type_segment( (string) ( $field['graphql_field_name'] ?? $name ) );
		$children  = function_exists( 'acf_get_local_fields' ) ? array_values( acf_get_local_fields( (string) ( $field['key'] ?? '' ) ) ) : [];

		if ( $is_container ) {
			$found[ $type_name ][] = [
				'path'  => $here,
				'shape' => container_shape( $children ),
			];
		}

		$child_layouts = [];

		if ( $is_flexible ) {
			foreach ( ( $field['layouts'] ?? [] ) as $layout ) {
				$layout_key  = (string) ( $layout['key'] ?? '' );
				$layout_name = (string) ( $layout['name'] ?? '' );

				if ( '' === $layout_name ) {
					continue;
				}

				$child_layouts[ $layout_key ] = $layout_name;

				// Layouts mint a type each, on the pattern confirmed live by
				// PageFieldsBlocksCardGridLayout. Two layouts sharing a name would
				// merge the same way a repeater pair does, and ACF does not stop
				// you registering the same layout name twice.
				$found[ $type_name . graphql_type_segment( $layout_name ) . 'Layout' ][] = [
					// The key as well as the name, because the one way two layouts land
					// on one type is by sharing a name — and then the name alone names
					// both sides of the report identically.
					'path'  => $here . ' > [' . $layout_name . ' / ' . $layout_key . ']',
					'shape' => container_shape(
						array_filter(
							$children,
							static function ( array $child ) use ( $layout_key ): bool {
								return ( $child['parent_layout'] ?? '' ) === $layout_key;
							}
						)
					),
				];
			}
		}

		walk_graphql_containers( $children, $type_name, $here, $child_layouts, $found, $depth + 1 );
	}
}

/**
 * Every container type the locally registered field groups will mint, keyed by
 * type name.
 *
 * All groups, not just the page group's `blocks` field: the page group has six
 * other repeaters and a group, Practice Settings has one, and any of them can
 * be the other half of a collision.
 */
function collect_graphql_container_types(): array {
	if ( ! function_exists( 'acf_get_local_field_groups' ) || ! function_exists( 'acf_get_local_fields' ) ) {
		return [];
	}

	$found = [];

	foreach ( acf_get_local_field_groups() as $group ) {
		// A group kept out of the schema mints no types at all.
		if ( empty( $group['show_in_graphql'] ) ) {
			continue;
		}

		$prefix = graphql_type_segment( (string) ( $group['graphql_field_name'] ?? '' ) );
		$key    = (string) ( $group['key'] ?? '' );

		if ( '' === $prefix || '' === $key ) {
			continue;
		}

		walk_graphql_containers(
			array_values( acf_get_local_fields( $key ) ),
			$prefix,
			(string) ( $group['graphql_field_name'] ?? $key ),
			[],
			$found
		);
	}

	return $found;
}

/**
 * Report every type name claimed by more than one container.
 *
 * Two shapes under one name is the bug that cost Phase 2 a day. One shape under
 * one name is reported too, but worded differently and deliberately not as the
 * same fault: the query still validates, so nothing is visibly broken today.
 * What is NOT established is which of the two ACF definitions backs the
 * resolver once rows exist — the Phase 2 confirmation was schema-level, taken
 * while every `blocks` field was still empty. Either way it is a trap with a
 * fuse on it: add a sub-field to one side and it is silently unqueryable, with
 * a healthy-looking schema saying otherwise.
 */
function assert_unique_graphql_type_names(): void {
	foreach ( collect_graphql_container_types() as $type_name => $sites ) {
		if ( count( $sites ) < 2 ) {
			continue;
		}

		$paths  = implode( ' and ', array_column( $sites, 'path' ) );
		$shapes = array_unique( array_column( $sites, 'shape' ) );
		$verb   = count( $sites ) > 2 ? 'all mint' : 'both mint';

		if ( count( $shapes ) > 1 ) {
			error_log(
				sprintf(
					'vs-content-model: %s %s the GraphQL type "%s", and their fields differ. '
						. 'Only the one registered first is queryable; the others keep the type and lose '
						. 'their fields, so the schema still looks healthy. Rename all but one.',
					$paths,
					$verb,
					$type_name
				)
			);

			continue;
		}

		error_log(
			sprintf(
				'vs-content-model: %s %s the GraphQL type "%s" with identical fields. '
					. 'Queries validate today, but they are one type: give any side a sub-field the '
					. 'others lack and it will be unqueryable with nothing in the schema to say so. '
					. 'Rename all but one.',
				$paths,
				$verb,
				$type_name
			)
		);
	}
}

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
	 * Deliberately NOT a free-form page builder, and the `blocks` field added at
	 * the foot of this group does not make it one. What an editor gains there is
	 * the ORDER of a page's sections and a closed set of section kinds, each of
	 * which the design system already draws. What they still cannot do is invent
	 * a section, write markup, or set a colour, a width or a spacing. That
	 * distinction is the whole design: reordering is nearly free here — no
	 * stylesheet in the corpus has a vertical margin on a section wrapper, an
	 * id-keyed selector or a :has() — while arbitrary authoring is what would
	 * produce pages the CSS was never written for.
	 *
	 * THE HERO. An earlier version of this group had hero fields and they were
	 * removed, because nothing populated or rendered them: an editor could type
	 * a new headline, save, and see no change on the site, and a field that
	 * silently does nothing is worse than an absent one. That rule stands. The
	 * `hero` group below is added ahead of the templates that will read it — the
	 * field has to exist before a page can be moved onto it — so it is shipped
	 * carrying a message field that says so on the edit screen, in as many words.
	 * That message is the debt. It comes down when the last template reads the
	 * field, and not before.
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
				//
				// The last two tabs are named here as what they are — not connected
				// to the site yet — rather than left out. An editor who finds a tab
				// this note never mentioned has no way to tell whether they have
				// found something new or something broken.
				[
					'key'     => 'field_vs_page_intro',
					'label'   => '',
					'name'    => '',
					'type'    => 'message',
					'message' => "<strong>This page's layout and body copy live in the site templates.</strong><br>\n"
						. "What you edit here is the content those templates pour in, a tab each: the “On this page” rail, "
						. "the process steps, the heading and intro copy for each section, the photos, "
						. "the cards and lists, and the FAQ.\n"
						. "Changes go live on the next site build.\n"
						. "The last two tabs — <em>Hero</em> and <em>Page sections</em> — are part of the rebuild "
						. "that is under way. Nothing on the site reads them yet, and each says so.",
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
				[
					'key'   => 'field_vs_hero_tab',
					'label' => 'Hero',
					'type'  => 'tab',
				],
				[
					'key'       => 'field_vs_hero_intro',
					'label'     => '',
					'name'      => '',
					'type'      => 'message',
					'message'   => "<strong>Not connected yet.</strong> The hero is still written into each page's "
						. "template, and the site does not read these boxes. Filling them in now changes nothing on "
						. "the site — it is not broken, it is not finished.\n"
						. "Each page starts reading its hero from here as it is rebuilt onto the new section system; "
						. "this note comes down when the last one has been.",
					'esc_html'  => 0,
					'new_lines' => 'wpautop',
				],
				/**
				 * The hero, deliberately a GROUP and not one of the section
				 * layouts below.
				 *
				 * A page has exactly one hero, it is always the first thing on the
				 * page, and it is never reordered — 35 uses across 33 pages, no
				 * exceptions. Making it a section an editor can add would allow a
				 * page with two heroes, and a page with none: no <h1>, which is
				 * both an accessibility failure and the single strongest on-page
				 * signal there is. A group gives exactly the same editable copy
				 * with none of that surface.
				 *
				 * These fields are inert until a page's template reads them, which
				 * is the rule the previous hero fields were REMOVED under (see the
				 * group docblock above). The message field directly above is what
				 * pays that debt in the meantime: an editor is told, on the screen,
				 * that typing here does nothing yet. Delete both together.
				 */
				[
					'key'        => 'field_vs_page_hero',
					'label'      => 'Hero',
					'name'       => 'hero',
					'type'       => 'group',
					'layout'     => 'block',
					'sub_fields' => [
						[
							'key'   => 'field_vs_page_hero_eyebrow',
							'label' => 'Eyebrow',
							'name'  => 'eyebrow',
							'type'  => 'text',
						],
						[
							'key'          => 'field_vs_page_hero_h1',
							'label'        => 'Headline',
							'name'         => 'h1',
							'type'         => 'textarea',
							'rows'         => 2,
							'instructions' => 'The page\'s main headline — its <h1>, and usually what Google shows. '
								. 'May contain <em>…</em> for the accent styling.',
						],
						[
							'key'          => 'field_vs_page_hero_sub',
							'label'        => 'Sub-heading',
							'name'         => 'sub',
							'type'         => 'textarea',
							'rows'         => 3,
							'instructions' => 'Plain text. The paragraph under the headline.',
						],
						[
							'key'          => 'field_vs_page_hero_ctas',
							'label'        => 'Buttons',
							'name'         => 'ctas',
							'type'         => 'repeater',
							'layout'       => 'table',
							'button_label' => 'Add button',
							// Every one of the 24 hero button rows on the site today
							// holds exactly two buttons. The cap is that measurement,
							// not a guess: a third button has no design and would wrap
							// or overflow at the narrow widths.
							'max'          => 2,
							'instructions' => 'At most two. The first is the solid button, the second the outlined one.',
							'sub_fields'   => [
								[
									'key'   => 'field_vs_page_hero_cta_label',
									'label' => 'Label',
									'name'  => 'label',
									'type'  => 'text',
								],
								[
									'key'          => 'field_vs_page_hero_cta_href',
									'label'        => 'Link',
									'name'         => 'href',
									'type'         => 'text',
									'instructions' => 'A path on this site like /contact/, an anchor like #process, or a full address.',
								],
							],
						],
						[
							'key'          => 'field_vs_page_hero_ratings',
							'label'        => 'Show the review line',
							'name'         => 'ratings',
							'type'         => 'true_false',
							'ui'           => 1,
							'instructions' => 'The five stars and Google review count under the buttons.',
							// Nothing in this group declares a `default_value`, and that
							// is deliberate. A group's sub-fields are read straight off
							// the page, and ACF answers a field with no stored value
							// with its default — so a default here is a value all 33
							// pages would report having the moment this deploys, without
							// anyone typing anything. Every one of them has to come back
							// empty. (A switch is the one exception that cannot be
							// avoided: ACF's own type default is off, which is the empty
							// state anyway.)
						],
						block_image_field( 'field_vs_page_hero_image' ),
						[
							'key'          => 'field_vs_page_hero_image_alt',
							'label'        => 'Alt text',
							'name'         => 'image_alt',
							'type'         => 'text',
							// Named image_alt, not alt: a group prefixes its sub-fields
							// with its own name, so `alt` here would store as hero_alt —
							// the exact meta key the blog post group already uses for a
							// different field on a different post type. They could not
							// actually collide, and a reader should not have to work
							// that out.
							'instructions' => 'Leave blank to use the alt text stored on the file in the Media Library.',
						],
						[
							'key'           => 'field_vs_page_hero_media_shape',
							'label'         => 'Photo treatment',
							'name'          => 'media_shape',
							'type'          => 'select',
							// A closed list, and short on purpose. These four are the
							// hero media treatments that exist in the templates today:
							// .hero-img (15 pages), .hero-stack (5), .hero-bg (3), and
							// no media at all. The one-off heroes — the two inline
							// Typeform embeds, the home page card — stay in code, so
							// there is deliberately no value here that names them.
							'choices'       => [
								'none'       => 'No photo — copy only',
								'image'      => 'Photo beside the copy',
								'stack'      => 'Stacked photo panel',
								'background' => 'Full-width photo behind the copy',
							],
							'return_format' => 'value',
							'allow_null'    => 1,
							'multiple'      => 0,
							'ui'            => 0,
						],
					],
				],
				[
					'key'   => 'field_vs_blocks_tab',
					'label' => 'Page sections',
					'type'  => 'tab',
				],
				[
					'key'       => 'field_vs_blocks_intro',
					'label'     => '',
					'name'      => '',
					'type'      => 'message',
					'message'   => "<strong>This is how a page is built once it has been moved over.</strong> "
						. "Pages are moved one at a time, by us. Until this one has been, the list stays empty and "
						. "the page renders from its template exactly as it always has, using the other tabs — so "
						. "adding a section here yourself does nothing yet.\n"
						. "On a page that has been moved: drag a row to move that section up or down the page. "
						. "Emptying the list puts the page straight back the way it was on the next build, "
						. "with nothing to undo.",
					'esc_html'  => 0,
					'new_lines' => 'wpautop',
				],
				/**
				 * The ordered list of sections a page is built from.
				 *
				 * ADDITIVE. The six repeaters above keep their fields, their 938
				 * rows and their meaning; nothing here reads or writes them. The
				 * storage keeps them apart with no help from us: a repeater writes
				 * `sections` (its row count), `sections_0_heading`, `_sections`
				 * (its field key) and so on, while a flexible field writes
				 * `blocks`, `blocks_0_<sub>` and `_blocks`. Verified against the
				 * database backup — cms/backup/database.sql holds rows for
				 * `sections`, `sections_N_*` and `_sections` and not one meta key
				 * beginning `blocks`. Different prefix, different rows, so adding
				 * this field cannot touch a value an editor has saved.
				 *
				 * And an empty list is the safe state, not a broken one: with no
				 * rows there is no `blocks` meta at all, GraphQL returns an empty
				 * list, and src/components/PageBlocks.astro renders nothing. The
				 * page keeps rendering from its template. That is the whole
				 * migration switch — `blocks.length > 0`, one page at a time, and
				 * a page is un-migrated by emptying this one field with no deploy
				 * and no code change.
				 *
				 * TWO RULES THAT FAIL AT RUNTIME RATHER THAN AT SAVE TIME, so
				 * neither shows up in wp-admin and both take a page down on the
				 * site instead:
				 *
				 *   1. Never set `graphql_field_name` on a layout. WPGraphQL for
				 *      ACF registers the layout's TYPE from the layout name, but
				 *      resolves a row's __typename from the raw acf_fc_layout
				 *      string stored in the database. Set one and the resolver
				 *      names a type that was never registered — a schema that
				 *      builds and a query that dies on whichever page uses that
				 *      layout.
				 *   2. Never register this field with zero layouts. The GraphQL
				 *      field is a list of an interface, and with no layouts
				 *      nothing implements it.
				 *
				 * Adding a layout here is half a change. The other half is its
				 * entry in src/blocks/registry.ts, in the same commit — the query
				 * is hand-written because introspection is off for public requests
				 * on this host, and an unregistered layout renders as nothing.
				 */
				[
					'key'          => 'field_vs_blocks',
					'label'        => 'Sections',
					'name'         => 'blocks',
					'type'         => 'flexible_content',
					'button_label' => 'Add a section',
					'min'          => 0,
					// Explicit, because the default is the only value that is safe:
					// `required` on this field would make all 33 pages unsavable
					// until somebody added a section to each of them.
					'required'     => 0,
					'instructions' => 'The page, in order, one section per row. Drag a row to move that section up or '
						. 'down the page; the “On this page” rail follows automatically.',
					'layouts'      => [
						/**
						 * FAQ.
						 *
						 * `pull` is the short aside beside the questions. It is a
						 * field of its own rather than reusing the preamble's `body`
						 * because the two are different places on the page — on the
						 * pages that have both today, the template puts the section
						 * row's `body` into the aside, so the backfill maps
						 * sections.faq.body → pull, NOT → body.
						 */
						[
							'key'        => 'layout_vs_blk_faq',
							'name'       => 'faq',
							'label'      => 'FAQ',
							'display'    => 'block',
							'sub_fields' => array_merge(
								block_preamble( 'faq' ),
								[
									[
										'key'          => 'field_vs_blk_faq_pull',
										'label'        => 'Aside',
										'name'         => 'pull',
										'type'         => 'textarea',
										'rows'         => 3,
										'instructions' => 'The short paragraph in the column beside the questions.',
									],
									[
										'key'          => 'field_vs_blk_faq_items',
										'label'        => 'Questions',
										'name'         => 'items',
										'type'         => 'repeater',
										'layout'       => 'row',
										'button_label' => 'Add question',
										'instructions' => 'These also generate the page\'s FAQ structured data, '
											. 'so keep answers factual and self-contained.',
										'sub_fields'   => [
											[
												'key'   => 'field_vs_blk_faq_q',
												'label' => 'Question',
												'name'  => 'question',
												'type'  => 'text',
											],
											[
												'key'   => 'field_vs_blk_faq_a',
												'label' => 'Answer',
												'name'  => 'answer',
												'type'  => 'textarea',
												'rows'  => 4,
											],
											[
												'key'           => 'field_vs_blk_faq_open',
												'label'         => 'Open by default',
												'name'          => 'open',
												'type'          => 'true_false',
												'ui'            => 1,
												'default_value' => 0,
											],
										],
									],
									[
										'key'   => 'field_vs_blk_faq_cta_label',
										'label' => 'Button label',
										'name'  => 'cta_label',
										'type'  => 'text',
									],
									[
										'key'   => 'field_vs_blk_faq_cta_href',
										'label' => 'Button link',
										'name'  => 'cta_href',
										'type'  => 'text',
									],
								]
							),
						],
						/**
						 * A grid of cards.
						 *
						 * Backfills from the `cards` repeater above, whose rows are
						 * grouped by a `group` name; one card_grid section consumes
						 * one group.
						 */
						[
							'key'        => 'layout_vs_blk_card_grid',
							'name'       => 'card_grid',
							'label'      => 'Card grid',
							'display'    => 'block',
							'sub_fields' => array_merge(
								block_preamble( 'cards' ),
								[
									[
										'key'           => 'field_vs_blk_cards_columns',
										'label'         => 'Columns',
										'name'          => 'columns',
										'type'          => 'select',
										'choices'       => [
											'2' => '2',
											'3' => '3',
											'4' => '4',
										],
										'default_value' => '3',
										'return_format' => 'value',
										'allow_null'    => 0,
										'multiple'      => 0,
										'ui'            => 0,
										'instructions'  => 'How many cards sit across a row on a desktop screen. '
											. 'They stack on a phone whichever you choose.',
									],
									[
										'key'          => 'field_vs_blk_cards_numbered',
										'label'        => 'Number the cards',
										'name'         => 'numbered',
										'type'         => 'true_false',
										'ui'           => 1,
										'default_value' => 0,
									],
									[
										'key'          => 'field_vs_blk_cards_cards',
										'label'        => 'Cards',
										'name'         => 'cards',
										'type'         => 'repeater',
										'layout'       => 'row',
										'button_label' => 'Add card',
										'sub_fields'   => [
											[
												'key'          => 'field_vs_blk_cards_card_meta',
												'label'        => 'Meta',
												'name'         => 'meta',
												'type'         => 'text',
												'instructions' => 'Secondary line — a price, a stat value, a label.',
											],
											[
												'key'   => 'field_vs_blk_cards_card_title',
												'label' => 'Title',
												'name'  => 'title',
												'type'  => 'text',
											],
											[
												'key'          => 'field_vs_blk_cards_card_lead',
												'label'        => 'Lead',
												'name'         => 'lead',
												'type'         => 'text',
												'instructions' => 'One line under the title, above the body.',
											],
											[
												'key'   => 'field_vs_blk_cards_card_body',
												'label' => 'Body',
												'name'  => 'body',
												'type'  => 'textarea',
												'rows'  => 3,
											],
											[
												'key'   => 'field_vs_blk_cards_card_href',
												'label' => 'Link',
												'name'  => 'href',
												'type'  => 'text',
											],
										],
									],
								]
							),
						],
						/**
						 * Copy on one side, a photo on the other.
						 *
						 * `media_side` and `ratio` are values on the row rather than
						 * variants of separate layouts because flipping a band is
						 * the commonest edit there is on these pages, and it should
						 * not cost the editor their content.
						 */
						[
							'key'        => 'layout_vs_blk_media_split',
							'name'       => 'media_split',
							'label'      => 'Photo and copy',
							'display'    => 'block',
							'sub_fields' => array_merge(
								block_preamble( 'media' ),
								[
									block_image_field( 'field_vs_blk_media_image' ),
									[
										'key'          => 'field_vs_blk_media_image_alt',
										'label'        => 'Alt text',
										'name'         => 'image_alt',
										'type'         => 'text',
										// The Images repeater this backfills from carries a
										// per-slot alt, so without this the migration would
										// drop it. Same fallback as there.
										'instructions' => 'Leave blank to use the alt text stored on the file in the Media Library.',
									],
									[
										'key'           => 'field_vs_blk_media_side',
										'label'         => 'Photo on the',
										'name'          => 'media_side',
										'type'          => 'select',
										'choices'       => [
											'left'  => 'Left',
											'right' => 'Right',
										],
										'default_value' => 'left',
										'return_format' => 'value',
										'allow_null'    => 0,
										'multiple'      => 0,
										'ui'            => 0,
									],
									[
										'key'           => 'field_vs_blk_media_ratio',
										'label'         => 'Split',
										'name'          => 'ratio',
										'type'          => 'select',
										'choices'       => [
											'even'       => 'Even',
											'wide-text'  => 'More room for the words',
											'wide-media' => 'More room for the photo',
										],
										'default_value' => 'even',
										'return_format' => 'value',
										'allow_null'    => 0,
										'multiple'      => 0,
										'ui'            => 0,
									],
									[
										'key'          => 'field_vs_blk_media_quote',
										'label'        => 'Pull quote',
										'name'         => 'quote',
										'type'         => 'textarea',
										'rows'         => 3,
										'instructions' => 'Optional. Set larger, beside or under the copy.',
									],
									block_list_field(
										'field_vs_blk_media_checklist',
										'Checklist',
										'checklist',
										'Add a point'
									),
									[
										'key'   => 'field_vs_blk_media_cta_label',
										'label' => 'Button label',
										'name'  => 'cta_label',
										'type'  => 'text',
									],
									[
										'key'   => 'field_vs_blk_media_cta_href',
										'label' => 'Button link',
										'name'  => 'cta_href',
										'type'  => 'text',
									],
								]
							),
						],
						/**
						 * Numbered process steps. Backfills from the Process tab's
						 * repeater above.
						 */
						[
							'key'        => 'layout_vs_blk_process_steps',
							'name'       => 'process_steps',
							'label'      => 'Process steps',
							'display'    => 'block',
							'sub_fields' => array_merge(
								block_preamble( 'steps' ),
								[
									[
										// `layout` is this sub-field's NAME — the shape the
										// steps are drawn in — not ACF's own `layout`
										// setting, which only applies to repeaters and
										// groups.
										'key'           => 'field_vs_blk_steps_layout',
										'label'         => 'Shape',
										'name'          => 'layout',
										'type'          => 'select',
										'choices'       => [
											'grid'    => 'Grid',
											'card'    => 'Cards',
											'divided' => 'Divided list',
										],
										'default_value' => 'grid',
										'return_format' => 'value',
										'allow_null'    => 0,
										'multiple'      => 0,
										'ui'            => 0,
									],
									[
										'key'           => 'field_vs_blk_steps_columns',
										'label'         => 'Columns',
										'name'          => 'columns',
										'type'          => 'select',
										'choices'       => [
											'2' => '2',
											'3' => '3',
											'4' => '4',
										],
										'default_value' => '4',
										'return_format' => 'value',
										'allow_null'    => 0,
										'multiple'      => 0,
										'ui'            => 0,
									],
									[
										'key'          => 'field_vs_blk_steps_steps',
										'label'        => 'Steps',
										'name'         => 'steps',
										'type'         => 'repeater',
										'layout'       => 'row',
										'button_label' => 'Add step',
										'sub_fields'   => [
											[
												'key'          => 'field_vs_blk_steps_step_tag',
												'label'        => 'Tag',
												'name'         => 'tag',
												'type'         => 'text',
												'instructions' => 'e.g. "Step One".',
											],
											[
												'key'          => 'field_vs_blk_steps_step_num',
												'label'        => 'Number',
												'name'         => 'num',
												'type'         => 'text',
												// Text, not number: the site prints these
												// verbatim and some are written "01".
												'instructions' => 'The figure shown on the step, exactly as it should read.',
											],
											[
												'key'   => 'field_vs_blk_steps_step_title',
												'label' => 'Title',
												'name'  => 'title',
												'type'  => 'text',
											],
											[
												'key'   => 'field_vs_blk_steps_step_body',
												'label' => 'Body',
												'name'  => 'body',
												'type'  => 'textarea',
												'rows'  => 3,
											],
										],
									],
								]
							),
						],
						/**
						 * The scrolling strip of smile photographs.
						 *
						 * No fields beyond the preamble on purpose: the tiles are
						 * files in the repository, read by src/lib/smiles.ts, and
						 * there is nothing here for an editor to fill in or get
						 * wrong.
						 */
						[
							'key'        => 'layout_vs_blk_gallery_marquee',
							'name'       => 'gallery_marquee',
							'label'      => 'Smile gallery strip',
							'display'    => 'block',
							'sub_fields' => block_preamble( 'gallery' ),
						],
						/**
						 * Side-by-side comparison cards — this against that,
						 * treatment levels, materials.
						 */
						[
							'key'        => 'layout_vs_blk_comparison_cards',
							'name'       => 'comparison_cards',
							'label'      => 'Comparison cards',
							'display'    => 'block',
							'sub_fields' => array_merge(
								block_preamble( 'compare' ),
								[
									[
										'key'          => 'field_vs_blk_compare_cards',
										'label'        => 'Cards',
										'name'         => 'tiers',
										'type'         => 'repeater',
										'layout'       => 'row',
										'button_label' => 'Add card',
										'sub_fields'   => [
											[
												'key'          => 'field_vs_blk_compare_card_tag',
												'label'        => 'Tag',
												'name'         => 'tag',
												'type'         => 'text',
												'instructions' => 'The small label above the title.',
											],
											[
												'key'   => 'field_vs_blk_compare_card_title',
												'label' => 'Title',
												'name'  => 'title',
												'type'  => 'text',
											],
											[
												'key'   => 'field_vs_blk_compare_card_body',
												'label' => 'Body',
												'name'  => 'body',
												'type'  => 'textarea',
												'rows'  => 3,
											],
											block_list_field(
												'field_vs_blk_compare_bullets',
												'Bullets',
												'bullets',
												'Add a bullet'
											),
											[
												'key'          => 'field_vs_blk_compare_card_ribbon',
												'label'        => 'Ribbon',
												'name'         => 'ribbon',
												'type'         => 'text',
												'instructions' => 'Optional flash across the corner — "Most chosen", "Best value".',
											],
											[
												'key'           => 'field_vs_blk_compare_card_featured',
												'label'         => 'Highlight this card',
												'name'          => 'featured',
												'type'          => 'true_false',
												'ui'            => 1,
												'default_value' => 0,
											],
										],
									],
								]
							),
						],
						/**
						 * One large figure beside a paragraph and a list of habits
						 * — the `.lasting-card` shell. Aftercare on the treatment
						 * pages, cost and insurance on the fee pages.
						 *
						 * Deliberately narrow. Every sub-field is something the
						 * band already draws: `value` and `unit` are the two halves
						 * of `.lasting-stat .big` (the unit is its `<em>`),
						 * `caption` is the line under them, `intro` is the
						 * paragraph that opens `.lasting-body` and `points` is the
						 * `<ul>` beneath it. A general-purpose stat block would
						 * offer a second figure or a rich body that nothing in the
						 * markup knows how to place, and the editor would only
						 * discover that after publishing.
						 *
						 * `intro` is its own field rather than the preamble's
						 * `body` because they are two paragraphs in two places —
						 * `body` sits under the section heading, `intro` inside the
						 * card, and the pilot page uses both. Same reason the FAQ
						 * layout carries `pull`, and the same warning for the
						 * backfill: sections.lasting.body maps to `body`, and the
						 * card's opening paragraph to `intro`.
						 */
						[
							'key'        => 'layout_vs_blk_stat_callout',
							'name'       => 'stat_callout',
							'label'      => 'Figure and list',
							'display'    => 'block',
							'sub_fields' => array_merge(
								block_preamble( 'stat' ),
								[
									[
										'key'          => 'field_vs_blk_stat_value',
										'label'        => 'Figure',
										'name'         => 'value',
										'type'         => 'text',
										// Text, not number: the figures in the corpus are
										// written "20–22" and "10+", and the dash is an
										// en dash the copy is specific about.
										'instructions' => 'The large number, exactly as it should read — for example 20–22.',
									],
									[
										'key'          => 'field_vs_blk_stat_unit',
										'label'        => 'Unit',
										'name'         => 'unit',
										'type'         => 'text',
										'instructions' => 'The small word set tight against the figure — hrs, yrs, months. '
											. 'Leave it blank for a figure that reads without one.',
									],
									[
										'key'          => 'field_vs_blk_stat_caption',
										'label'        => 'Caption',
										'name'         => 'caption',
										'type'         => 'text',
										'instructions' => 'The single line under the figure saying what it measures.',
									],
									[
										'key'          => 'field_vs_blk_stat_intro',
										'label'        => 'Card intro',
										'name'         => 'intro',
										'type'         => 'textarea',
										'rows'         => 4,
										'instructions' => 'Plain text. The paragraph inside the card, above the list — not the one '
											. 'under the section heading, which is Body above.',
									],
									[
										'key'          => 'field_vs_blk_stat_points',
										'label'        => 'List',
										'name'         => 'points',
										'type'         => 'repeater',
										'layout'       => 'row',
										'button_label' => 'Add a point',
										'instructions' => 'The list under that paragraph. Each line opens bold and continues in plain text.',
										'sub_fields'   => [
											[
												'key'          => 'field_vs_blk_stat_point_lead',
												'label'        => 'Lead',
												'name'         => 'lead',
												'type'         => 'text',
												// The colon belongs to the template, which
												// prints "<strong>lead:</strong> body". Typing
												// one here puts two on the page.
												'instructions' => 'The bold opening of the line. Do not end it with a colon — one is added for you.',
											],
											[
												'key'   => 'field_vs_blk_stat_point_body',
												'label' => 'Body',
												'name'  => 'body',
												'type'  => 'textarea',
												'rows'  => 2,
											],
										],
									],
								]
							),
						],
						/**
						 * A section the site builds itself.
						 *
						 * The escape hatch of docs/PAGE-BLOCKS.md 1.3, and the only
						 * layout with no editable content. It holds a place in the
						 * order for one of the bands in BLOCK_CODE_BANDS; the
						 * component that has always drawn that band still draws it,
						 * with the props it has always had.
						 *
						 * Last in the picker on purpose. It is the exception, and an
						 * editor scanning the list for the section they want to add
						 * should meet the seven they can fill in first.
						 *
						 * No repeater and no group, so it mints no GraphQL type of its
						 * own and gives assert_unique_graphql_type_names() nothing to
						 * catch.
						 *
						 * FOR THE ASTRO SIDE: pass this row's `anchor` into the
						 * component as its id rather than letting the component keep
						 * its own default. LocalTrust.astro defaults to id="local"
						 * while the rail link is built from `anchor`, so a generated
						 * `custom-3` anchor beside a hardcoded `local` id is a rail
						 * entry that jumps nowhere. One id, and the CMS owns it.
						 */
						[
							'key'        => 'layout_vs_blk_code_section',
							'name'       => 'code_section',
							'label'      => 'Built-in section',
							'display'    => 'block',
							'sub_fields' => array_merge(
								block_code_preamble( 'code' ),
								[
									[
										'key'           => 'field_vs_blk_code_band_key',
										'label'         => 'Which section',
										'name'          => 'band_key',
										'type'          => 'select',
										'choices'       => BLOCK_CODE_BANDS,
										'return_format' => 'value',
										'allow_null'    => 0,
										'multiple'      => 0,
										'ui'            => 0,
										// Deliberately NOT required. With allow_null off and
										// at least one choice the select has no blank option,
										// so every save already posts a real key and
										// `required` adds nothing to the normal path. What it
										// would add is a lockout: a page holding a row whose
										// key was later retired could not be saved at all
										// until somebody picked a different section — the
										// same shape of deadlock unlock_empty_importer_field()
										// below exists to undo.
										'required'      => 0,
										'instructions'  => 'This row is a section the site builds for itself — the map, '
											. 'reviews and address band, for instance. Pick which one it is; there is '
											. 'nothing else to fill in, because its wording, its pictures and its '
											. 'background are all part of the design rather than content. Drag this row '
											. 'to move that section up or down the page, or delete it to take the section '
											. 'off the page — but you cannot change what is inside it from here. Ask us '
											. 'if it needs to say something different.',
									],
								]
							),
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
 *
 * blocks[].anchor gets the same treatment and is not in this list either, for a
 * mechanical reason: every section layout declares its own `anchor` sub-field
 * with its own key, so listing them would mean six entries here and a seventh
 * thing to remember each time a layout is added. block_anchor_meta_pattern()
 * below recognises them by shape instead.
 */
const GENERATED_ID_FIELDS = [
	'field_vs_section_id' => '/^sections_\d+_section_id$/',
	'field_vs_image_slot' => '/^images_\d+_slot$/',
];

/**
 * The postmeta pattern for a section anchor, or null if this is not one.
 *
 * Matches on structure rather than on a list of keys: any sub-field named
 * `anchor` whose parent is the `blocks` field. ACF stamps `parent` on every
 * sub-field of a flexible layout when the group is registered, so this holds for
 * a layout added tomorrow without anyone editing this function.
 *
 * All layouts share the one meta shape — blocks_0_anchor, blocks_1_anchor — so
 * the numbering that next_generated_id() hands out is unique across the whole
 * page rather than per layout. That is what it has to be: two sections with the
 * same anchor is invalid HTML, and it sends both the rail link and the scroll
 * offset that goes with it to whichever one the browser finds first.
 */
function block_anchor_meta_pattern( array $field ): ?string {
	if ( 'anchor' !== ( $field['name'] ?? '' ) ) {
		return null;
	}

	if ( 'field_vs_blocks' !== ( $field['parent'] ?? '' ) ) {
		return null;
	}

	return '/^blocks_\d+_anchor$/';
}

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
 * Give a blank Section ID, Slot or section Anchor a value on the way into the
 * database.
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
 *
 * For a section anchor "never overwrites" is the point rather than a side
 * effect. An anchor is a public address: it is in the “On this page” rail, in
 * any link a patient or another site has to that section, and in the scroll
 * offset the page applies when someone arrives on one. Regenerating it when a
 * heading is edited or a section is dragged would detach every one of those,
 * silently. Generated once, then permanent.
 */
function fill_blank_row_id( $value, $post_id, $field ) {
	if ( ! is_array( $field ) ) {
		return $value;
	}

	$generated = GENERATED_ID_FIELDS;
	$key       = (string) ( $field['key'] ?? '' );

	$pattern = $generated[ $key ] ?? block_anchor_meta_pattern( $field );

	if ( null === $pattern ) {
		return $value;
	}

	// ACF also saves against option and term ids, which have no postmeta to
	// scan. These fields only ever live on a page, so anything else is not
	// ours to touch.
	if ( ! is_numeric( $post_id ) ) {
		return $value;
	}

	$current = is_string( $value ) ? trim( $value ) : $value;

	if ( '' !== $current && null !== $current ) {
		return $value;
	}

	return next_generated_id( (int) $post_id, $pattern );
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

/**
 * Run the collision check once every group is registered.
 *
 * `acf/include_fields` is where all five groups register — this one, Practice
 * Settings and the menu group — and ACF fires it earlier in the same `init`
 * callback that ends with `acf/init`, so by here the local store is complete.
 * Reading the store rather than the literal arrays means the check sees what
 * ACF actually kept, which is not the same shape as what was handed to it.
 */
add_action( 'acf/init', __NAMESPACE__ . '\\assert_unique_graphql_type_names', 99 );
