<?php
/**
 * Plugin Name:  Vivid Smiles — Editor Admin
 * Description:  Curates wp-admin down to the screens an editor actually owns,
 *               and locks the blog category list to the five the site is built
 *               from.
 * Author:       Concepcion.Work
 * Version:      0.1.0
 *
 * Two jobs, both the same idea: an editor should not be shown a control that
 * does nothing, and should not be able to reach one that quietly breaks the
 * build. A field that silently does nothing is worse than an absent one; an
 * error that names the fix is worth ten that do not.
 *
 * ADMINISTRATORS ARE EXEMPT FROM EVERYTHING HERE. Every restriction is gated on
 * manage_options and every gate goes through restrictions_apply() below. That is
 * not politeness, it is the recovery path: this is a must-use plugin, so it
 * cannot be deactivated from wp-admin. Hiding Plugins or the Theme File Editor
 * from an administrator would lock the site's owner out of their own install
 * with no way back in short of FTP.
 *
 * LOAD ORDER: must-use plugins load in filename order, and `vs-admin.php` sorts
 * FIRST — ahead of `vs-config.php`, which cms/README.md relies on sorting first.
 * That is harmless because nothing in this file runs at load time: every line
 * below is a hook registration, and the earliest of them fires on `init`, long
 * after all eight vs-* plugins are in memory. The one cross-file reference —
 * ContentModel\BLOG_CATEGORIES — is read inside a function for exactly that
 * reason. Do not move it to file scope.
 */

declare( strict_types=1 );

namespace VividSmiles\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the restrictions in this file apply to the request in hand.
 *
 * Three exemptions, in cost order:
 *
 * - WP-CLI. The importers in cms/import/ create terms and write posts wholesale,
 *   and depending on how `wp` is invoked there may be no current user at all —
 *   in which case a capability check answers "no" and the import silently does
 *   half its job. A shell with WP-CLI on it is already privileged.
 * - Cron. seed_blog_categories() and anything else running unattended has no
 *   user either.
 * - Administrators, per the header above.
 *
 * REST and AJAX are deliberately NOT exempt: the block editor creates categories
 * over REST and the classic editor does it over admin-ajax, so exempting either
 * would leave the "+ Add New Category" hole wide open on the one screen editors
 * actually use.
 */
function restrictions_apply(): bool {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return false;
	}

	if ( wp_doing_cron() ) {
		return false;
	}

	return ! current_user_can( 'manage_options' );
}

/**
 * The five canonical blog categories, read from the file that owns them.
 *
 * Derived rather than copied. vs-content-model.php declares BLOG_CATEGORIES and
 * cms/README.md calls those names a public contract — the Astro z.enum, the
 * client-side filter keys and shared /blog/?category=… URLs all carry them
 * verbatim. A second list here would be a second thing to forget to update, and
 * the drift would surface as posts vanishing from the site.
 *
 * Returns an empty array if vs-content-model.php is missing, which is a broken
 * install rather than a state to code around; callers below degrade to "block
 * nothing new, enforce nothing" instead of guessing at the list.
 */
function canonical_categories(): array {
	$const = 'VividSmiles\ContentModel\BLOG_CATEGORIES';

	return defined( $const ) ? (array) constant( $const ) : [];
}

/**
 * The five, written out for a human: "A, B, C, D and E".
 */
function canonical_categories_sentence(): string {
	$names = canonical_categories();

	if ( ! $names ) {
		return '';
	}

	$last = array_pop( $names );

	return $names ? implode( ', ', $names ) . ' and ' . $last : $last;
}


/* -------------------------------------------------------------------------
 * A. The admin menu
 * ---------------------------------------------------------------------- */

/**
 * The only Appearance screen an editor owns.
 *
 * cms/README.md lists Appearance → Menus as an editing surface: the primary and
 * footer menus, and the mega-panel fields vs-menus.php hangs off them. So the
 * Appearance menu has to stay — removing themes.php outright would take Menus
 * with it and break a documented workflow.
 *
 * A whitelist rather than a blacklist of the four known-bad children (Themes,
 * Customize, Widgets, Theme File Editor). The host carries ten plugins, five of
 * which are nothing to do with this setup, and any of them can add another
 * Appearance child tomorrow. A blacklist would let that one through.
 */
const APPEARANCE_KEEP = [ 'nav-menus.php' ];

/**
 * Top-level menus a content editor has no use for.
 *
 * Comments: nothing in the Astro site renders them. SCF: the field groups are
 * declared in code (see vs-content-model.php), so editing them in the UI does
 * not persist — the exact "control that does nothing" this file exists to
 * remove. GraphQL: an API console. Tools and Plugins: neither is content, and
 * Tools is where the host's migration and file-manager plugins live.
 *
 * SCF and WPGraphQL already gate their own menus on manage_options, so those two
 * lines are usually no-ops. They cost nothing and they survive a plugin release
 * that relaxes its own gate.
 */
const REMOVE_MENUS = [
	'plugins.php',
	'tools.php',
	'edit-comments.php',
	'edit.php?post_type=acf-field-group',
	'graphiql-ide',
];

/**
 * Runs late so plugins that register their own menus on the default priority
 * have already done so; there is nothing to remove before it exists.
 */
function curate_menu(): void {
	if ( ! restrictions_apply() ) {
		return;
	}

	global $submenu;

	if ( isset( $submenu['themes.php'] ) ) {
		foreach ( $submenu['themes.php'] as $index => $entry ) {
			// Customize registers itself as `customize.php?return=…`, so the
			// slug has to be compared without its query string or it never
			// matches anything.
			$file = explode( '?', (string) ( $entry[2] ?? '' ) )[0];

			if ( ! in_array( $file, APPEARANCE_KEEP, true ) ) {
				unset( $submenu['themes.php'][ $index ] );
			}
		}

		// With children left in place, WordPress points a top-level menu at its
		// FIRST child rather than at its own slug, so Appearance now opens
		// Menus. If Menus is not registered at all — a block theme hides it —
		// that leaves Appearance pointing at themes.php, which guard_screens()
		// below refuses to serve. Remove the whole menu rather than leave a link
		// that dead-ends.
		if ( ! $submenu['themes.php'] ) {
			remove_menu_page( 'themes.php' );
		}
	}

	foreach ( REMOVE_MENUS as $slug ) {
		remove_menu_page( $slug );
	}
}
add_action( 'admin_menu', __NAMESPACE__ . '\\curate_menu', 999 );

/**
 * Screens that are refused outright, not merely unlinked.
 *
 * remove_menu_page() hides a link; it does not close a door. The Customize link
 * is also in the admin bar, editors bookmark URLs, and "why can't I find X"
 * ends with someone pasting a wp-admin URL into chat. So the menu and the screen
 * are handled separately and deliberately.
 *
 * The first group is genuinely reachable by the editors here: all four need only
 * edit_theme_options, which they hold because Appearance → Menus is their job,
 * and tools.php's own capability is edit_posts. The second group is already
 * capability-blocked for anyone without manage_options and is listed anyway —
 * those two screens edit live PHP on a production CMS, and naming them costs a
 * line each.
 */
const BLOCKED_SCREENS = [
	'themes.php',
	'customize.php',
	'widgets.php',
	'site-editor.php',
	'tools.php',
	'edit-comments.php',
	'comment.php',

	'theme-editor.php',
	'plugin-editor.php',
	'plugins.php',
];

/**
 * SCF's own post types. Its field-group screens are reachable as
 * `edit.php?post_type=…`, which is not a distinct $pagenow, so they are matched
 * on the query argument instead. Field groups live in code and editing them in
 * the UI changes nothing that survives a page load.
 */
const BLOCKED_POST_TYPES = [
	'acf-field-group',
	'acf-field',
	'acf-post-type',
	'acf-taxonomy',
	'acf-ui-options-page',
];

function guard_screens(): void {
	if ( ! restrictions_apply() ) {
		return;
	}

	// admin-ajax.php fires admin_init too. Its $pagenow matches nothing below,
	// but bailing early keeps every AJAX request in the admin off this path.
	if ( wp_doing_ajax() ) {
		return;
	}

	$pagenow   = (string) ( $GLOBALS['pagenow'] ?? '' );
	$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';

	$blocked = in_array( $pagenow, BLOCKED_SCREENS, true )
		|| ( $post_type !== '' && in_array( $post_type, BLOCKED_POST_TYPES, true ) );

	if ( ! $blocked ) {
		return;
	}

	// Name the fix, not just the refusal. "You do not have permission" tells an
	// editor nothing about where their work actually lives.
	wp_die(
		'<h1>Not part of editing this site</h1>'
		. '<p>The website is built from the content in this WordPress, not from a WordPress '
		. 'theme, so this screen changes nothing visitors will ever see — and some of these '
		. 'screens can stop the site rebuilding at all.</p>'
		. '<p>What you are looking for is almost certainly one of: <strong>Posts</strong>, '
		. '<strong>Pages</strong>, <strong>Testimonials</strong>, <strong>Practice Settings</strong>, '
		. 'or <strong>Appearance → Menus</strong> for the navigation.</p>'
		. '<p>If you genuinely need this screen, ask an administrator — they still have it.</p>',
		'Screen not available',
		[
			'response'  => 403,
			'back_link' => true,
		]
	);
}
add_action( 'admin_init', __NAMESPACE__ . '\\guard_screens' );

/**
 * The admin bar carries its own links to two of the screens above, and would
 * otherwise hand back what the menu curation just took away.
 */
function trim_admin_bar( \WP_Admin_Bar $bar ): void {
	if ( ! restrictions_apply() ) {
		return;
	}

	$bar->remove_node( 'customize' );
	$bar->remove_node( 'edit-site' );
	$bar->remove_node( 'comments' );
}
add_action( 'admin_bar_menu', __NAMESPACE__ . '\\trim_admin_bar', 999 );


/* -------------------------------------------------------------------------
 * B. The blog category list
 * ---------------------------------------------------------------------- */

/**
 * Make the five categories a closed list for everyone but an administrator.
 *
 * A sixth category is the most expensive mistake available in this admin, and
 * the least visible: the post publishes in WordPress, src/loaders/blog.ts
 * rejects it as "category … is not one of the five the site supports", and the
 * post simply does not exist on the website. The only diagnostic is a warning
 * line in a Vercel build log nobody is watching. "+ Add New Category" sits one
 * click away in the editor sidebar.
 *
 * Done by re-capping the taxonomy rather than by filtering manage_categories on
 * the user, because manage_categories is shared: post_tag and the Review Tags
 * taxonomy in vs-content-model.php both use it, and Review Tags is deliberately
 * editor-extensible. Denying the primitive cap would close a door this content
 * model wants open.
 *
 * assign_terms stays edit_posts — untouched from the WordPress default — so an
 * editor still picks freely from the five. Only creating, renaming and deleting
 * move up to manage_options. Renaming matters as much as creating: the names are
 * embedded verbatim in shared /blog/?category=… URLs.
 *
 * The visible effect on an editor's screen is that Posts → Categories and the
 * "+ Add New Category" control stop being rendered at all, in both editors and
 * over REST. Choosing from the five becomes the only path because it is the only
 * control left.
 */
function lock_category_capabilities( array $args, string $taxonomy ): array {
	if ( 'category' !== $taxonomy ) {
		return $args;
	}

	$args['capabilities'] = [
		'manage_terms' => 'manage_options',
		'edit_terms'   => 'manage_options',
		'delete_terms' => 'manage_options',
		'assign_terms' => 'edit_posts',
	];

	return $args;
}
add_filter( 'register_taxonomy_args', __NAMESPACE__ . '\\lock_category_capabilities', 10, 2 );

/**
 * Refuse the creation itself, and say why.
 *
 * The capabilities above remove the affordance; this closes the path. Anything
 * that reaches wp_insert_term() — a plugin, a stale REST client, a future admin
 * screen — gets a WP_Error whose message is the one an editor needs to read, and
 * both editors surface it verbatim.
 *
 * Creating a category that IS one of the five is always allowed. That is not a
 * loophole, it is a requirement: seed_blog_categories() runs on `init` on any
 * request that finds the install unseeded, including an anonymous one with no
 * user and no capabilities at all. Blocking it there would leave the install
 * with no categories whatsoever.
 */
function block_new_categories( $term, $taxonomy ) {
	if ( 'category' !== $taxonomy ) {
		return $term;
	}

	if ( ! restrictions_apply() ) {
		return $term;
	}

	$canonical = canonical_categories();

	// No list to check against means vs-content-model.php is not loaded. Refusing
	// every category on a half-built install would be its own outage.
	if ( ! $canonical ) {
		return $term;
	}

	foreach ( $canonical as $name ) {
		if ( 0 === strcasecmp( trim( (string) $term ), $name ) ) {
			return $term;
		}
	}

	return new \WP_Error(
		'vs_category_locked',
		sprintf(
			'The website only understands five blog categories: %s. A post filed under '
			. 'anything else is skipped by the site build — it stays published here and '
			. 'never appears on the website. Pick one of the five instead. If a new '
			. 'category is genuinely needed it has to be added to the website\'s code at '
			. 'the same time, so ask an administrator.',
			canonical_categories_sentence()
		)
	);
}
add_filter( 'pre_insert_term', __NAMESPACE__ . '\\block_new_categories', 10, 2 );

/**
 * Post meta holding "we changed your categories, here is what happened", read
 * and deleted by the notice below.
 */
const CATEGORY_FIX_META = '_vs_category_fixed';

/**
 * One category per post, enforced on save.
 *
 * The second trap, and a quieter one than the sixth category. src/loaders/blog.ts
 * reads `node.categories.nodes[0]` — the FIRST category and nothing else. Tick
 * two and one of them decides how the post files itself on the hub, with no way
 * to tell from wp-admin which one won. The checkbox list gives no hint that it is
 * really a radio button.
 *
 * So: reduce to one, deterministically, and then say so. The tie-break is the
 * order in ContentModel\BLOG_CATEGORIES, which is also the order src/lib/blog.ts
 * displays them in — arbitrary tie-breaks are fine as long as they are the site's
 * own and are disclosed. Non-canonical categories are dropped in the same pass,
 * falling back to the site's default category, so a post can never leave here in
 * a state the loader will skip.
 *
 * Silently correcting an editor would be the same sin this file exists to fix,
 * which is why every correction leaves a notice behind.
 */
function normalise_categories( int $post_id ): void {
	if ( ! restrictions_apply() ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( 'auto-draft' === get_post_status( $post_id ) ) {
		return;
	}

	$canonical = canonical_categories();

	if ( ! $canonical ) {
		return;
	}

	$assigned = wp_get_post_categories( $post_id, [ 'fields' => 'all' ] );

	// No categories at all is not this function's problem: WordPress applies
	// default_category on insert, and vs-content-model.php points that at
	// "Dental Tips".
	if ( ! is_array( $assigned ) || ! $assigned ) {
		return;
	}

	$keep = null;

	foreach ( $canonical as $name ) {
		foreach ( $assigned as $term ) {
			if ( 0 === strcasecmp( $term->name, $name ) ) {
				$keep = $term;
				break 2;
			}
		}
	}

	// Everything assigned is off-contract — only reachable if an administrator
	// made the terms and an editor picked them. Fall back to the default rather
	// than let the post fall off the site.
	if ( null === $keep ) {
		$default = get_term( (int) get_option( 'default_category' ), 'category' );
		$keep    = $default instanceof \WP_Term ? $default : null;
	}

	if ( null === $keep ) {
		return;
	}

	$dropped = [];

	foreach ( $assigned as $term ) {
		if ( $term->term_id !== $keep->term_id ) {
			$dropped[] = $term->name;
		}
	}

	if ( ! $dropped ) {
		return;
	}

	// wp_set_post_categories() does not fire save_post, so this cannot recurse
	// back into the hooks below.
	wp_set_post_categories( $post_id, [ $keep->term_id ], false );

	update_post_meta(
		$post_id,
		CATEGORY_FIX_META,
		[
			'kept'    => $keep->name,
			'dropped' => $dropped,
		]
	);
}

/**
 * Two hooks, because the two editors set terms at different moments.
 *
 * The classic editor and Quick Edit go through wp_insert_post(), which assigns
 * categories BEFORE it fires save_post — so save_post sees the final state. The
 * block editor does not: the REST controller inserts the post first and calls
 * handle_terms() afterwards, so a save_post handler reads the terms the post had
 * a moment ago and any correction it makes is overwritten seconds later.
 * `rest_after_insert_post` fires after handle_terms and before the response is
 * prepared, which is the only point in a REST save where the terms are both
 * final and still changeable.
 *
 * save_post therefore stands down during REST requests rather than doing work
 * that is about to be undone — and, worse, leaving a notice about a change that
 * did not stick.
 */
function normalise_categories_on_save( int $post_id ): void {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}

	normalise_categories( $post_id );
}
add_action( 'save_post_post', __NAMESPACE__ . '\\normalise_categories_on_save', 20 );

function normalise_categories_after_rest( \WP_Post $post ): void {
	normalise_categories( $post->ID );
}
add_action( 'rest_after_insert_post', __NAMESPACE__ . '\\normalise_categories_after_rest' );

/**
 * Tell the editor what was changed under them, once.
 */
function category_fix_notice(): void {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return;
	}

	$screen = get_current_screen();

	if ( ! $screen || 'post' !== $screen->id ) {
		return;
	}

	$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

	if ( ! $post_id ) {
		return;
	}

	$fix = get_post_meta( $post_id, CATEGORY_FIX_META, true );

	if ( ! is_array( $fix ) || empty( $fix['kept'] ) ) {
		return;
	}

	delete_post_meta( $post_id, CATEGORY_FIX_META );

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html(
			sprintf(
				'This post is now filed under "%s". The website shows one category per post, '
				. 'so %s %s removed. Change it in the Categories panel if that is the wrong one.',
				(string) $fix['kept'],
				'"' . implode( '", "', (array) ( $fix['dropped'] ?? [] ) ) . '"',
				count( (array) ( $fix['dropped'] ?? [] ) ) === 1 ? 'was' : 'were'
			)
		)
	);
}
add_action( 'admin_notices', __NAMESPACE__ . '\\category_fix_notice' );
