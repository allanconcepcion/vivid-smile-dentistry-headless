<?php
/**
 * Plugin Name:  Vivid Smiles — Page content migration
 * Description:  Runs the `blocks` back-fill and the `hero` copy back-fill, one
 *               route at a time, from Tools, for administrators only, on a host
 *               with no shell.
 * Author:       Concepcion.Work
 * Version:      0.2.0
 *
 * WHAT THIS IS FOR
 *
 * Two back-fills, on one screen, both of which move wording that is currently
 * in the front end's templates into WordPress so the owner can edit it:
 *
 *   Page sections  cms/import/backfill-blocks.php turns a page's existing
 *                  repeater rows into an ordered `blocks` list.
 *   Hero copy      cms/import/backfill-hero.php fills the `hero` group with the
 *                  eyebrow, headline, sub-heading and buttons the page already
 *                  renders, so the Hero tab holds real words instead of blanks.
 *
 * Both were written for `wp eval-file`, and the hosted CMS (GoDaddy Managed
 * WordPress) offers no SSH and therefore no WP-CLI. Without this screen neither
 * can be run at all. docs/PAGE-BLOCKS.md, phases 2 and 3.
 *
 * This is a front end and nothing else. It decides nothing: every judgement
 * about which section row becomes which layout, and every character of hero
 * copy, stays in the two engines and their two JSON payloads, which this file
 * includes and calls. Two copies of that logic writing to the same live CMS is
 * the failure mode worth more than any convenience — a dry run in one and a
 * write in the other would disagree, and nobody would find out until a page
 * rendered wrong.
 *
 * That principle is honoured unevenly and it is worth knowing which is which.
 * The hero mode has exactly one writer: render_hero() calls vs_hb_apply_route()
 * and so does the WP-CLI driver, so a dry run here is evidence about a run
 * anywhere. The sections mode does NOT — run() below re-implements the write
 * that backfill-blocks.php already contains, and the two have to be kept in
 * agreement by hand. If the sections mode is ever touched again, that is the
 * thing to fix, and vs_hb_apply_route() is the shape to copy.
 *
 * THE TWO MODES ARE INDEPENDENT, deliberately. Each has its own engine file and
 * its own payload, both hand-uploaded, and either can be missing. A mode whose
 * files are absent reports that and takes itself off the screen; the other one
 * keeps working. Nothing is shared but the URL, the nonce, the capability check
 * and the notice renderer.
 *
 * WHEN TO DELETE IT
 *
 * When both jobs are done: the pages in block-map.json all migrated and the map
 * no longer growing, and the routes in hero-payload.json all filled. Realistically
 * the end of Phase 3. At that point this is a tool that writes page content on a
 * live, internet-facing admin and has no remaining job, which is the definition
 * of attack surface kept for sentiment. Delete this file, delete the uploaded
 * vs-migrate/ directory beside it, and re-deploy. Nothing else references either.
 *
 * Note that the deletion date now covers two jobs rather than one, so check both
 * before deleting: an unfilled hero is a screen still worth having, even if
 * every section has long since been migrated.
 *
 * Delete it sooner if the host ever gains SSH: WP-CLI is the better runner,
 * because it cannot be reached by an HTTP request at all.
 *
 * SECURITY, which is most of the design
 *
 *   - Every entry point checks manage_options. The capability argument to
 *     add_management_page() hides a link; it is not a boundary, so the render
 *     callback and the POST handler check again for themselves.
 *   - Nothing mutates on GET. The handler returns immediately unless the
 *     request method is POST, before it looks at a single field.
 *   - Every write is nonce-protected with check_admin_referer(), which dies
 *     rather than returning on failure.
 *   - The route comes from a <select> built out of the mode's own payload keys
 *     and is validated back against that list with a strict in_array(). No path,
 *     no file name and no code ever arrives from the request. An admin screen
 *     that accepted a path would be an arbitrary-file-include hole on a live CMS.
 *   - Dry run is the default and a separate button, on both forms. A request
 *     that names no button, or an unrecognised one, plans and reports; only the
 *     button called `vs_write` writes.
 *   - A page whose `blocks` is already non-empty is refused unless a separate
 *     checkbox is ticked in the same POST. Emptying `blocks` un-migrates a page
 *     with no deploy and no code change, so almost everything here is
 *     reversible — an editor's arrangement is the exception, because nothing
 *     anywhere records what the order used to be.
 *   - A page whose hero already holds different wording is refused the same way,
 *     and refused WHOLE: not one of its fields is written, so no page is ever
 *     left half from the payload and half from an editor. That refusal is the
 *     kinder one, because the plan prints the wording it would replace beside
 *     the wording it would write, before anybody agrees to anything.
 *   - The mode is a hidden field and nothing more. It picks which form's
 *     submission is being read; it is not a permission and it is checked after
 *     the nonce and the capability, never instead of them.
 *
 * LOAD ORDER: must-use plugins load in filename order and `vs-migrate.php`
 * sorts after `vs-content-model.php`, which is where `field_vs_blocks` is
 * declared. That ordering is belt and braces rather than a requirement —
 * nothing below runs at load time, the earliest hook is `admin_menu`, and the
 * field is read through acf_get_field() at request time in any case.
 *
 * WHAT HAS TO BE ON THE HOST BESIDES THIS FILE
 *
 * cms/bin/deploy-mu-plugins.sh copies mu-plugins/*.php and nothing else, so the
 * engines and their payloads need uploading by hand, once, into either:
 *
 *   wp-content/mu-plugins/vs-migrate/
 *   wp-content/vs-import/bin/
 *
 * and the four files are:
 *
 *   backfill-blocks.php + block-map.json      the sections mode
 *   backfill-hero.php   + hero-payload.json   the hero mode
 *
 * The first directory is preferred and is checked first. A .php file in a
 * SUBDIRECTORY of mu-plugins is not auto-loaded by WordPress — only top-level
 * files are — so putting it there does not silently start running it on every
 * request. Both directories are web-readable on this host, which is worth
 * knowing and is not a leak: requested directly, either engine defines its
 * functions and stops (see the guard described below), and both payloads hold
 * page copy that is already published — hero-payload.json in particular is the
 * wording every one of those pages is serving right now, and holds no phone
 * number, booking URL, address or key by construction. Add a deny rule for the
 * directory if the host offers one.
 */

declare( strict_types=1 );

namespace VividSmiles\Migrate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MENU_SLUG    = 'vs-page-sections-migration';
const CAPABILITY   = 'manage_options';
const NONCE_ACTION = 'vs_page_sections_migration';

/**
 * The constant backfill-blocks.php must define for this screen to include it.
 *
 * See engine() for why a marker is needed at all.
 */
const LIBRARY_SENTINEL = 'VS_BACKFILL_LIBRARY';

/**
 * The same marker, for the hero mode's engine.
 *
 * A second name rather than a shared one: the two engines are separate uploads
 * and either can be absent, so "is the library there" has to be answerable
 * about each of them on its own.
 */
const HERO_LIBRARY_SENTINEL = 'VS_HERO_BACKFILL_LIBRARY';

/**
 * The bookkeeping meta backfill-blocks.php writes after a successful run.
 *
 * Read and written here in exactly the same shape, so a run from this screen
 * and a run from WP-CLI agree about which pages are already done. Diverging on
 * this key would make each runner treat the other's work as an editor's
 * arrangement and refuse it.
 */
const BACKFILL_META = '_vs_blocks_backfill';

/**
 * Where the engine and the map may live, in preference order.
 *
 * Fixed in code. Nothing in this list is ever assembled from a request value,
 * and the only file names passed to locate() below are literals — that is the
 * whole of the input validation story for file access on this screen.
 */
function candidate_dirs(): array {
	return [
		WPMU_PLUGIN_DIR . '/vs-migrate',
		WP_CONTENT_DIR . '/vs-import/bin',
	];
}

/** The first readable copy of a known file name, or '' if there is none. */
function locate( string $basename ): string {
	foreach ( candidate_dirs() as $dir ) {
		$path = $dir . '/' . $basename;

		if ( is_readable( $path ) ) {
			return $path;
		}
	}

	return '';
}

/**
 * Make backfill-blocks.php's planner callable, or explain why it is not.
 *
 * Returns [ ok, list of plain-English lines to show the administrator ].
 *
 * THE FILE CANNOT BE INCLUDED AS IT STANDS, and this is deliberate on its part
 * rather than an oversight. Its first statement is:
 *
 *     if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
 *         fwrite( STDERR, "Run through WP-CLI.\n" );
 *         exit( 1 );
 *     }
 *
 * That sits ABOVE every function definition, so a web request that includes the
 * file gets no functions and a killed response — a blank wp-admin page, on a
 * must-use plugin that cannot be deactivated from wp-admin. And even with the
 * guard gone, the bottom third of the file is a driver that parses arguments
 * and WRITES, at include time; including it would run a migration as a side
 * effect of opening a screen.
 *
 * The smallest change that fixes both, made in backfill-blocks.php by whoever
 * owns it and not here: delete those four lines from the top, and put this
 * immediately above the `// Arguments.` banner, ahead of the driver and below
 * the last function:
 *
 *     if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
 *         // Library mode. The functions above are all a non-CLI caller wants;
 *         // the driver below parses WP-CLI arguments and writes.
 *         define( 'VS_BACKFILL_LIBRARY', true );
 *         return;
 *     }
 *
 * `return` at the top level of an included file is legal, and legal inside
 * eval() too, so `wp eval-file` keeps behaving exactly as it does today.
 *
 * Until that lands this screen refuses to run rather than guessing. It detects
 * the change by looking for the constant's NAME in the source before including
 * anything — the check has to happen on the text, because the only other way to
 * find out whether a file exits on include is to include it and lose the
 * request. Absent marker means refuse, so a file this cannot understand fails
 * closed. After the include the constant and the functions are confirmed for
 * real, in case the name appeared in the source without the guard.
 *
 * Nothing here is called at plugin load. The include happens inside the Tools
 * screen, so the worst case for a malformed engine file is that one screen,
 * not every request to wp-admin.
 */
function engine(): array {
	if ( function_exists( 'vs_bb_plan_route' ) && function_exists( 'vs_bb_layout_shapes' ) ) {
		return [ true, [] ];
	}

	$path = locate( 'backfill-blocks.php' );

	if ( '' === $path ) {
		return [
			false,
			[
				'backfill-blocks.php is not on this install, so there is no migration engine to run.',
				'Upload cms/import/backfill-blocks.php and cms/import/block-map.json into '
					. 'wp-content/mu-plugins/vs-migrate/. cms/bin/deploy-mu-plugins.sh copies '
					. 'mu-plugins/*.php only, so this is a one-off manual upload.',
			],
		];
	}

	$source = (string) file_get_contents( $path );

	if ( false === strpos( $source, LIBRARY_SENTINEL ) ) {
		return [
			false,
			[
				sprintf( 'Found the engine at %s, but it still exits at the top unless it is running under WP-CLI.', $path ),
				'Including it as it stands would blank this screen and, with the guard removed but the '
					. 'driver left in place, would run a migration just by opening it. Neither is a thing '
					. 'to work around from here.',
				'The one change it needs is described in the comment above ' . basename( __FILE__ )
					. '\'s engine() function: move the WP-CLI guard down to just above the argument '
					. 'parsing, make it define ' . LIBRARY_SENTINEL . ' and `return` instead of exit. '
					. 'WP-CLI behaviour does not change.',
			],
		];
	}

	require_once $path;

	if ( ! defined( LIBRARY_SENTINEL ) || ! function_exists( 'vs_bb_plan_route' ) || ! function_exists( 'vs_bb_layout_shapes' ) ) {
		return [
			false,
			[
				sprintf( 'Loaded %s, but it did not leave the planner behind.', $path ),
				'Expected the constant ' . LIBRARY_SENTINEL . ' and the functions vs_bb_plan_route() '
					. 'and vs_bb_layout_shapes(). Refusing to run rather than half-calling a file that '
					. 'is not what this screen expects.',
			],
		];
	}

	return [ true, [] ];
}

/**
 * block-map.json, parsed.
 *
 * Returns [ 'error' => string ] or the map plus the fingerprint the CLI prints,
 * which is the only way a reader can tell two runs apart when the map has been
 * edited between them.
 */
function read_map(): array {
	$path = locate( 'block-map.json' );

	if ( '' === $path ) {
		return [
			'error' => 'block-map.json is not on this install. Upload cms/import/block-map.json into '
				. 'wp-content/mu-plugins/vs-migrate/ beside the engine.',
		];
	}

	$raw = (string) file_get_contents( $path );
	$map = json_decode( $raw, true );

	if ( ! is_array( $map ) || empty( $map['routes'] ) || ! is_array( $map['routes'] ) ) {
		return [ 'error' => sprintf( '%s is empty or malformed — it has no `routes` object.', $path ) ];
	}

	return [
		'path'   => $path,
		'raw'    => $raw,
		'map'    => $map,
		'routes' => $map['routes'],
		'sha'    => substr( sha1( $raw ), 0, 12 ),
	];
}

/**
 * The registered shape of every `blocks` layout, or the reason there is none.
 *
 * Read off the live registration rather than the source, for the reason
 * backfill-blocks.php gives: ACF silently drops an unrecognised key inside an
 * update_field() row, so a map naming a sub-field this install does not have
 * would migrate a page with that piece missing and say nothing.
 */
function preflight(): array {
	if ( ! function_exists( 'acf_get_field' ) || ! function_exists( 'update_field' ) ) {
		return [
			[],
			'Secure Custom Fields is not active on this install, so there is no `blocks` field to write into.',
		];
	}

	$field = \acf_get_field( 'field_vs_blocks' );

	if ( ! is_array( $field ) || empty( $field['layouts'] ) ) {
		return [
			[],
			'The `blocks` field is not registered here. It is declared in '
				. 'cms/mu-plugins/vs-content-model.php and has to be on this host before anything can be '
				. 'written into it. Deploy that file, confirm `blocks` appears on PageFields in GraphQL, '
				. 'then reload this screen.',
		];
	}

	return [ \vs_bb_layout_shapes( $field ), '' ];
}

// ---------------------------------------------------------------------------
// The hero mode's three equivalents of the above. Same shapes, same failure
// style, deliberately separate functions: the two modes must be able to fail
// independently. A host missing backfill-blocks.php should still be able to
// fill heroes, and vice versa — welding them together would mean one absent
// upload takes both jobs off the screen.
// ---------------------------------------------------------------------------

/**
 * Make backfill-hero.php's planner and writer callable, or explain why not.
 *
 * The same arrangement engine() describes, and for the same reasons — except
 * that backfill-hero.php was written already knowing them, so its WP-CLI guard
 * is at the bottom of the file from the start and no edit is needed to make it
 * includable. The sentinel is still checked in the SOURCE before including
 * anything: the only other way to find out whether a file exits on include is
 * to include it and lose the request.
 *
 * Nothing here runs at plugin load. The include happens inside the Tools screen.
 */
function hero_engine(): array {
	if ( function_exists( 'vs_hb_plan_route' ) && function_exists( 'vs_hb_apply_route' ) ) {
		return [ true, [] ];
	}

	$path = locate( 'backfill-hero.php' );

	if ( '' === $path ) {
		return [
			false,
			[
				'backfill-hero.php is not on this install, so there is no hero back-fill engine to run.',
				'Upload cms/import/backfill-hero.php and cms/import/hero-payload.json into '
					. 'wp-content/mu-plugins/vs-migrate/, beside the two files the sections mode uses. '
					. 'cms/bin/deploy-mu-plugins.sh copies mu-plugins/*.php only, so this is a manual upload.',
			],
		];
	}

	$source = (string) file_get_contents( $path );

	if ( false === strpos( $source, HERO_LIBRARY_SENTINEL ) ) {
		return [
			false,
			[
				sprintf( 'Found a hero engine at %s, but it is not the one this screen knows how to load.', $path ),
				'Expected it to announce itself as a library by defining ' . HERO_LIBRARY_SENTINEL
					. ' when it is not running under WP-CLI. Without that marker there is no way to tell, '
					. 'short of including it, whether it exits at the top or runs a migration on include. '
					. 'Refusing rather than finding out.',
			],
		];
	}

	require_once $path;

	if ( ! defined( HERO_LIBRARY_SENTINEL ) || ! function_exists( 'vs_hb_plan_route' ) || ! function_exists( 'vs_hb_apply_route' ) ) {
		return [
			false,
			[
				sprintf( 'Loaded %s, but it did not leave the engine behind.', $path ),
				'Expected the constant ' . HERO_LIBRARY_SENTINEL . ' and the functions vs_hb_plan_route() '
					. 'and vs_hb_apply_route(). Refusing to run rather than half-calling a file that is not '
					. 'what this screen expects.',
			],
		];
	}

	return [ true, [] ];
}

/**
 * hero-payload.json, parsed.
 *
 * Returns [ 'error' => string ] or the payload plus the fingerprint the CLI
 * prints and the receipt records, which is the only way a reader can tell two
 * runs apart when the payload has been regenerated between them.
 */
function read_payload(): array {
	$path = locate( 'hero-payload.json' );

	if ( '' === $path ) {
		return [
			'error' => 'hero-payload.json is not on this install. Upload cms/import/hero-payload.json into '
				. 'wp-content/mu-plugins/vs-migrate/ beside the engine.',
		];
	}

	$raw     = (string) file_get_contents( $path );
	$payload = json_decode( $raw, true );

	if ( ! is_array( $payload ) || empty( $payload['routes'] ) || ! is_array( $payload['routes'] ) ) {
		return [ 'error' => sprintf( '%s is empty or malformed — it has no `routes` object.', $path ) ];
	}

	return [
		'path'   => $path,
		'raw'    => $raw,
		'routes' => $payload['routes'],
		'sha'    => substr( sha1( $raw ), 0, 12 ),
	];
}

/**
 * The registered shape of the `hero` group, or the reason there is none.
 *
 * Read off the live registration for the reason backfill-hero.php gives, which
 * bites harder here than it does for `blocks`: ACF's group writer iterates the
 * sub-fields it KNOWS and never looks at an array key that matches none of
 * them. A payload naming a sub-field this install has not got would write
 * nothing at all and report success.
 */
function hero_preflight(): array {
	if ( ! function_exists( 'acf_get_field' ) || ! function_exists( 'update_field' ) ) {
		return [
			[],
			'Secure Custom Fields is not active on this install, so there is no `hero` group to write into.',
		];
	}

	$field = \acf_get_field( \vs_hb_group_key() );

	if ( ! is_array( $field ) || empty( $field['sub_fields'] ) ) {
		return [
			[],
			'The `hero` group is not registered here. It is declared in '
				. 'cms/mu-plugins/vs-content-model.php and has to be on this host before anything can be '
				. 'written into it. Deploy that file, confirm `hero` appears on PageFields in GraphQL, '
				. 'then reload this screen.',
		];
	}

	return [ \vs_hb_group_shape( $field ), '' ];
}

/**
 * Plan one route and, if asked and allowed, write it.
 *
 * Returns everything the screen needs to explain what happened, and writes at
 * most one field on at most one page.
 *
 * ONE ROUTE PER RUN, where the CLI plans every route in the map before writing
 * any of them. That difference is safe for the reason the whole migration is
 * safe: `blocks` is per-page and a page renders from its template until its own
 * field is filled, so there is no state in which two routes are half-migrated
 * with respect to each other. Reporting on twenty routes in one HTML page would
 * be unreadable, and unreadable is how a bad plan gets approved.
 */
function run( string $route, array $config, array $shapes, array $map_meta, bool $write, bool $overwrite_confirmed ): array {
	$result = [
		'route'    => $route,
		'plan'     => null,
		'outcome'  => 'failed',
		'messages' => [],
	];

	$plan           = \vs_bb_plan_route( $route, $config, $shapes );
	$result['plan'] = $plan;

	if ( ! empty( $plan['errors'] ) ) {
		$result['outcome']  = 'failed';
		$result['messages'] = array_merge(
			[ sprintf( '%d problem(s) with this route. Nothing was written.', count( $plan['errors'] ) ) ],
			$plan['errors']
		);

		return $result;
	}

	$post_id = (int) $plan['post_id'];
	$rows    = (array) $plan['rows'];

	// The same hash the CLI computes, over the same JSON, so "already
	// back-filled by the other runner" is recognised rather than refused.
	$hash = md5( (string) wp_json_encode( $rows ) );

	// The raw meta rather than get_field(): a flexible-content field stores its
	// layout names here, so this answers "are there rows" without ACF formatting
	// a value that is about to be replaced.
	$existing       = get_post_meta( $post_id, 'blocks', true );
	$existing_count = is_array( $existing ) ? count( $existing ) : 0;

	if ( $existing_count > 0 ) {
		// "Did this run produce what is already there?" — the same test the CLI
		// makes, over the same hash, so the two runners agree.
		//
		// A PURE REORDER READS AS UNCHANGED. The hash covers the rows this run
		// would write, not the order the rows are in on the page, so an editor
		// who has dragged the sections around and changed nothing else lands
		// here and gets "nothing to do". That is the outcome to want: their
		// arrangement is left alone and nothing is written. Anything that
		// changes the row COUNT, the map, or the underlying copy falls through
		// to the refusal below.
		// The receipt records a hash of what was STORED. Comparing it against a
		// hash of what is stored NOW is what makes this claim true; comparing it
		// against the plan would only prove the map has not changed, and would
		// announce "same content" over a heading an editor rewrote in wp-admin
		// since the last run.
		$previous  = json_decode( (string) get_post_meta( $post_id, BACKFILL_META, true ), true );
		$stored_now = \get_field( 'blocks', $post_id, false );
		$is_ours   = is_array( $previous )
			&& ( $previous['hash'] ?? '' ) === md5( (string) wp_json_encode( $stored_now ) )
			&& ( $previous['plan'] ?? '' ) === $hash
			&& (int) ( $previous['rows'] ?? -1 ) === $existing_count;

		if ( $is_ours ) {
			$result['outcome']    = 'unchanged';
			$result['messages'][] = sprintf(
				'Already back-filled: %d section(s) already on this page, from this same map and this '
					. 'same content. Nothing to do, and nothing was written.',
				$existing_count
			);

			return $result;
		}

		if ( ! $overwrite_confirmed ) {
			$result['outcome']    = 'refused';
			$result['messages'][] = sprintf(
				'Refused. This page already holds %d section(s) that this run did not produce — either '
					. 'somebody has arranged them in the editor, or they came from an earlier version of '
					. 'the map.',
				$existing_count
			);
			$result['messages'][] = 'Nothing anywhere records what that order used to be, so replacing it '
				. 'is the one action on this screen that cannot be undone. The plan below is what would '
				. 'replace it.';
			$result['messages'][] = 'To go ahead: tick the overwrite confirmation and run again. To keep '
				. 'the existing arrangement: do nothing.';

			return $result;
		}
	}

	if ( ! $write ) {
		$result['outcome']    = 'planned';
		$result['messages'][] = sprintf(
			'Dry run. %d section(s) would be written to page %d. Nothing was written.',
			count( $rows ),
			$post_id
		);

		if ( $existing_count > 0 ) {
			$result['messages'][] = sprintf(
				'This page already holds %d section(s); a real run would replace them wholesale.',
				$existing_count
			);
		}

		return $result;
	}

	// One call, a wholesale replace, which is what makes a repeated submission
	// incapable of appending or duplicating: there is no add_row() here or in
	// the engine. Written by field KEY — writing the parent by name leaves SCF
	// without its companion _field reference and the rows invisible to both
	// wp-admin and WPGraphQL. import-pages.php sets that convention out in full.
	// MUST come before update_field() when replacing, and "wholesale" is only
	// true because of it. update_field() writes the sub-fields the map NAMES; a
	// sub-field the layout has but the map does not fill is skipped, not
	// cleared — so an editor's value survives what the screen calls a
	// replacement and keeps rendering. The same gap orphans a previous layout's
	// sub-fields when the layout at an index changes: ACF rewrites the parent
	// list and leaves blocks_3_columns and friends behind as dead meta,
	// invisible in wp-admin and in GraphQL, and resurrected the moment that
	// index returns to the old layout.
	//
	// delete_field() loops every existing row through delete_row(), which takes
	// the sub-field meta with it. Cheap, and it makes both problems moot.
	if ( $existing_count > 0 ) {
		\delete_field( 'field_vs_blocks', $post_id );
	}

	$written = \update_field( 'field_vs_blocks', $rows, $post_id );

	// Read back rather than trust the return. update_field() answers false on
	// failure and the receipt below is what a later run reads to decide it has
	// nothing to do — stamping it on a write that did not happen is how a page
	// ends up permanently reported as migrated while holding nothing.
	$stored       = \get_field( 'blocks', $post_id, false );
	$stored_count = is_array( $stored ) ? count( $stored ) : 0;

	if ( false === $written || $stored_count !== count( $rows ) ) {
		$result['outcome']    = 'failed';
		$result['messages'][] = sprintf(
			'The write did not take. WordPress reports %d section(s) stored where %d were sent, so '
				. 'nothing has been recorded and this page is unchanged as far as the migration is '
				. 'concerned. Try again; if it repeats, the page may be locked by another editor.',
			$stored_count,
			count( $rows )
		);

		return $result;
	}

	update_post_meta(
		$post_id,
		BACKFILL_META,
		(string) wp_json_encode(
			[
				// Hash of what is now STORED, not of what we intended to store.
				// Hashing the plan makes a later run compare plan-to-plan and
				// announce "same content" over an editor's rewritten heading.
				'hash'  => md5( (string) wp_json_encode( $stored ) ),
				'plan'  => $hash,
				'rows'  => count( $rows ),
				'map'   => $map_meta['sha'],
				'route' => $route,
				'when'  => gmdate( 'c' ),
			]
		)
	);

	// update_field() fires neither transition_post_status nor acf/save_post, so
	// vs-deploy.php never hears about this and the front end keeps serving the
	// pre-migration page indefinitely. Telling the operator to "open the page
	// and click Update" is a step nobody will remember on page nineteen of
	// twenty-one, so ask for the build here.
	if ( function_exists( '\\VividSmiles\\Deploy\\queue' ) ) {
		\VividSmiles\Deploy\queue( sprintf( 'page sections back-filled for %s', $route ) );
		$result['messages'][] = 'A front-end rebuild has been queued; the live site picks this up in a '
			. 'few minutes.';
	} else {
		$result['messages'][] = 'NOTE: the deploy trigger is not available, so the live site will not '
			. 'rebuild by itself. Open the page and click Update to queue a build.';
	}

	$result['outcome']    = 'written';
	$result['messages'][] = sprintf( 'Wrote %d section(s) to page %d.', count( $rows ), $post_id );
	$result['messages'][] = 'The six source repeaters — sections, cards, images, faqs, process steps and '
		. 'the "On this page" links — were read and left exactly as they were. Nothing else on this page '
		. 'was touched.';
	$result['messages'][] = 'To undo: open the page, Page content, Page sections, and empty the list. The '
		. 'page renders from its template again on the next build, with no deploy and no code change.';

	return $result;
}

// ---------------------------------------------------------------------------
// The screen.
// ---------------------------------------------------------------------------

/**
 * Tools → Page content migration.
 *
 * Registered for administrators. vs-admin.php already removes Tools entirely
 * for anyone below administrator, so this is the second of three locks; the
 * third is the check inside the render callback, which is the one that actually
 * holds if either of the others is ever loosened.
 *
 * RENAMED from "Page sections migration" when the hero mode arrived, because
 * that name had become a description of half the screen. MENU_SLUG is
 * deliberately not renamed with it: the slug is the URL, and an existing
 * bookmark or a link in somebody's notes should keep working. A screen that
 * moved would be a worse outcome than a constant whose name has aged.
 */
function register_menu(): void {
	add_management_page(
		'Page content migration',
		'Page content migration',
		CAPABILITY,
		MENU_SLUG,
		__NAMESPACE__ . '\\render'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\register_menu' );

/**
 * Read the submission, if there is one.
 *
 * Returns null for anything that is not a properly authorised POST — including
 * every GET, checked first and before any field is read. A migration that could
 * be started by following a link is a migration that can be started by an image
 * tag in an email.
 */
function submission( array $routes, string $mode ): ?array {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';

	if ( 'POST' !== $method ) {
		return null;
	}

	// Re-checked here rather than trusted from the menu registration: a
	// capability argument to add_management_page() controls a link, and
	// remove_menu_page() hides a link rather than closing a door — the point
	// vs-admin.php makes about every other screen it takes away.
	if ( ! current_user_can( CAPABILITY ) ) {
		wp_die( 'You do not have permission to run the page content migration.', 403 );
	}

	// Dies on a bad or missing nonce rather than returning, so there is no path
	// where a failed check falls through into the handler below.
	check_admin_referer( NONCE_ACTION );

	// WHICH OF THE TWO FORMS ON THIS SCREEN WAS SUBMITTED.
	//
	// The screen draws a form per mode and each renderer calls this with its own
	// name; a POST from the other form is not this renderer's business and is
	// ignored here rather than being validated against the wrong route list. It
	// sits below the nonce and capability checks deliberately — those are the
	// gate, and a request that fails them must die at them, not fall through to a
	// mode comparison that happens to return null.
	//
	// Ignoring rather than erroring is what stops a hero submission from painting
	// "that route is not in block-map.json" across the sections form, which is a
	// message about the wrong thing and the sort that gets a good tool distrusted.
	$posted_mode = isset( $_POST['vs_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['vs_mode'] ) ) : '';

	if ( $posted_mode !== $mode ) {
		return null;
	}

	$posted = isset( $_POST['vs_route'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['vs_route'] ) ) : '';

	// Validated against the payload's own keys with a strict comparison. The
	// request cannot introduce a route, only choose one the mode's own JSON file
	// already describes — which is also why there is no free-text path field
	// anywhere on either form.
	if ( '' === $posted || ! in_array( $posted, array_keys( $routes ), true ) ) {
		return [
			'route'     => '',
			'write'     => false,
			'overwrite' => false,
			'error'     => sprintf(
				'That route is not in %s. Choose one from the list and try again.',
				'hero' === $mode ? 'hero-payload.json' : 'block-map.json'
			),
		];
	}

	return [
		'route' => $posted,

		// Only this one button writes. A POST naming no button, or naming the
		// dry-run button, or naming something unrecognised, plans and reports.
		// The default has to be the harmless one.
		'write' => isset( $_POST['vs_write'] ),

		// Deliberately a separate control from the button, and deliberately not
		// remembered across submissions: confirming an overwrite is a decision
		// about one page on one run, not a mode to leave switched on.
		'overwrite' => isset( $_POST['vs_overwrite_confirmed'] )
			&& 'yes' === sanitize_text_field( wp_unslash( (string) $_POST['vs_overwrite_confirmed'] ) ),

		'error' => '',
	];
}

/** A field value as one short, readable line. */
function preview( $value ): string {
	if ( is_array( $value ) ) {
		return sprintf( '%d row(s)', count( $value ) );
	}

	$text = trim( (string) $value );

	if ( '' === $text ) {
		return '(blank)';
	}

	$text = preg_replace( '/\s+/', ' ', $text ) ?? $text;

	if ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) > 140 : strlen( $text ) > 140 ) {
		$text = ( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 137 ) : substr( $text, 0, 137 ) ) . '…';
	}

	return $text;
}

/**
 * The per-block plan, block by block and field by field.
 *
 * This is the screen's whole reason for existing: somebody has to be able to
 * see what a write would do, in full, before agreeing to it. Everything is run
 * through esc_html() — these values are page copy and several of them contain
 * markup.
 */
function render_plan( array $plan ): void {
	$rows = (array) $plan['rows'];

	echo '<h2>What this would write</h2>';
	echo '<p>' . esc_html(
		sprintf(
			'%d section(s), in this order, onto page %d. Only the `blocks` field is written.',
			count( $rows ),
			(int) $plan['post_id']
		)
	) . '</p>';

	foreach ( (array) $plan['report'] as $index => $line ) {
		$row = isset( $rows[ $index ] ) ? (array) $rows[ $index ] : [];

		echo '<h3>' . esc_html( sprintf( '%d. %s', (int) $line['position'], (string) $line['layout'] ) ) . '</h3>';

		echo '<p>';
		echo 'Anchor <code>#' . esc_html( (string) $line['anchor'] ) . '</code>';
		echo ' &middot; band <code>' . esc_html( (string) $line['band'] ) . '</code>';
		echo ' &middot; copy from <code>' . esc_html( (string) $line['source'] ) . '</code>';
		echo ' &middot; ';
		echo '' !== (string) $line['nav_label']
			? 'in the &ldquo;On this page&rdquo; rail as ' . esc_html( '"' . $line['nav_label'] . '"' )
			: 'not in the &ldquo;On this page&rdquo; rail';
		echo '</p>';

		echo '<table class="widefat striped" style="max-width:60em"><tbody>';

		$blanks = [];

		foreach ( $row as $name => $value ) {
			if ( 'acf_fc_layout' === $name ) {
				continue;
			}

			if ( ! is_array( $value ) && '' === trim( (string) $value ) ) {
				$blanks[] = (string) $name;
			}

			echo '<tr>';
			echo '<th scope="row" style="width:12em">' . esc_html( (string) $name ) . '</th>';
			echo '<td>' . esc_html( preview( $value ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		foreach ( (array) $line['rows'] as $row_line ) {
			echo '<p style="margin:.4em 0 0"><em>' . esc_html( (string) $row_line ) . '</em></p>';
		}

		if ( $blanks ) {
			// Blank is not automatically wrong — a blank nav_label is how a
			// section stays out of the rail, and the map has bands that carry no
			// eyebrow. It is called out because a blank nobody expected is how a
			// missing heading reaches the live site quietly.
			$escaped = array_map(
				static function ( string $name ): string {
					return '<code>' . esc_html( $name ) . '</code>';
				},
				$blanks
			);

			echo '<p style="margin:.4em 0 0">Left blank: ' . implode( ', ', $escaped );
			echo ' &mdash; check each one is meant to be.</p>';
		}
	}

	if ( ! empty( $plan['warnings'] ) ) {
		echo '<h2>Not migrated, on purpose</h2>';
		echo '<p>Rows the map deliberately leaves behind, with the reason somebody wrote down. '
			. 'They stay in their original repeaters and are simply not part of the new list.</p>';
		echo '<pre style="white-space:pre-wrap;background:#fff;border:1px solid #c3c4c7;padding:1em">';
		foreach ( (array) $plan['warnings'] as $warning ) {
			echo esc_html( (string) $warning ) . "\n";
		}
		echo '</pre>';
	}
}

/** Where every route in the map currently stands. Read-only. */
function render_status( array $routes ): void {
	echo '<h2>Where the pages stand</h2>';
	echo '<table class="widefat striped" style="max-width:60em"><thead><tr>';
	echo '<th>Route</th><th>Page</th><th>Sections</th><th>Back-filled</th>';
	echo '</tr></thead><tbody>';

	foreach ( array_keys( $routes ) as $route ) {
		$post_id  = \vs_bb_page_by_route( (string) $route );
		$existing = $post_id ? get_post_meta( $post_id, 'blocks', true ) : '';
		$count    = is_array( $existing ) ? count( $existing ) : 0;
		$previous = $post_id ? json_decode( (string) get_post_meta( $post_id, BACKFILL_META, true ), true ) : null;

		echo '<tr>';
		echo '<td><code>' . esc_html( (string) $route ) . '</code></td>';
		echo '<td>' . ( $post_id ? esc_html( (string) $post_id ) : '<strong>not found</strong>' ) . '</td>';
		echo '<td>' . esc_html( 0 === $count ? 'none — renders from its template' : sprintf( '%d', $count ) ) . '</td>';
		echo '<td>' . esc_html(
			is_array( $previous ) && isset( $previous['when'] )
				? (string) $previous['when'] . ' (map ' . (string) ( $previous['map'] ?? '?' ) . ')'
				: '—'
		) . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';
}

/** A list of lines inside a notice box. */
function render_notice( string $class, array $lines ): void {
	if ( ! $lines ) {
		return;
	}

	echo '<div class="notice ' . esc_attr( $class ) . '"><p>';
	echo implode( '<br>', array_map( 'esc_html', $lines ) );
	echo '</p></div>';
}

/**
 * The screen itself: one page, one nonce, one capability check, two migrations.
 *
 * The two are drawn as separate <form>s rather than as a mode switch, which is
 * what keeps this a small addition instead of a rewrite. There is no
 * round-trip to change mode and no JavaScript; each form carries its own route
 * list, its own buttons and its own overwrite confirmation, and submission()
 * tells them apart by a hidden field. Above all, each is rendered by its own
 * function, so the sections mode's early returns — a missing engine, a missing
 * map, an unregistered field — take the sections mode off the screen and leave
 * the hero mode working. That independence is the point: the two engines are
 * separate manual uploads to a host with no shell, and the state where one
 * arrived and the other did not is the normal state, not the exception.
 */
function render(): void {
	// The lock that matters. add_management_page()'s capability argument governs
	// the menu; this governs the screen, and it is the one that still holds if
	// somebody reaches the URL directly.
	if ( ! current_user_can( CAPABILITY ) ) {
		wp_die( 'You do not have permission to view the page content migration.', 403 );
	}

	echo '<div class="wrap">';
	echo '<h1>Page content migration</h1>';
	echo '<p>Two one-way jobs that move a page&rsquo;s wording out of its template and into WordPress, one '
		. 'route at a time. Both write to the live CMS, both refuse a page somebody has already edited, and '
		. 'both have a dry run that writes nothing. Use it.</p>';

	echo '<h2 style="margin-top:1.5em">Page sections</h2>';
	render_sections();

	echo '<hr style="margin:3em 0">';

	echo '<h2>Hero copy</h2>';
	render_hero();

	echo '</div>';
}

/**
 * The sections mode. Unchanged in substance from when it was the whole screen.
 */
function render_sections(): void {
	echo '<p>Turns a page&rsquo;s existing content into the ordered <strong>Page sections</strong> list, '
		. 'one route at a time. It writes one field and reads six, and it '
		. 'never edits or deletes the content it reads from.</p>';

	list( $engine_ok, $engine_notes ) = engine();

	if ( ! $engine_ok ) {
		render_notice( 'notice-error', $engine_notes );

		return;
	}

	$map_meta = read_map();

	if ( isset( $map_meta['error'] ) ) {
		render_notice( 'notice-error', [ (string) $map_meta['error'] ] );

		return;
	}

	$routes = (array) $map_meta['routes'];

	list( $shapes, $preflight_error ) = preflight();

	if ( '' !== $preflight_error ) {
		render_notice( 'notice-error', [ $preflight_error ] );

		return;
	}

	$submission = submission( $routes, 'sections' );
	$result     = null;

	if ( is_array( $submission ) && '' !== (string) $submission['error'] ) {
		render_notice( 'notice-error', [ (string) $submission['error'] ] );
	} elseif ( is_array( $submission ) ) {
		$result = run(
			(string) $submission['route'],
			(array) $routes[ $submission['route'] ],
			$shapes,
			$map_meta,
			(bool) $submission['write'],
			(bool) $submission['overwrite']
		);

		$class = [
			'written'   => 'notice-success',
			'unchanged' => 'notice-info',
			'planned'   => 'notice-info',
			'refused'   => 'notice-warning',
			'failed'    => 'notice-error',
		][ $result['outcome'] ] ?? 'notice-info';

		render_notice(
			$class,
			array_merge(
				[ sprintf( '%s — %s', (string) $result['route'], strtoupper( (string) $result['outcome'] ) ) ],
				(array) $result['messages']
			)
		);
	}

	$selected = is_array( $submission ) ? (string) $submission['route'] : (string) array_key_first( $routes );

	echo '<form method="post" action="' . esc_url( admin_url( 'tools.php?page=' . MENU_SLUG ) ) . '">';
	wp_nonce_field( NONCE_ACTION );

	// Which of the screen's two forms this is. Not a security control — the nonce
	// and the capability check are — just the thing that stops one mode reading
	// the other mode's submission and validating a route against the wrong list.
	echo '<input type="hidden" name="vs_mode" value="sections">';

	echo '<table class="form-table" role="presentation"><tbody>';

	echo '<tr><th scope="row"><label for="vs_route">Route</label></th><td>';
	echo '<select name="vs_route" id="vs_route">';
	foreach ( array_keys( $routes ) as $route ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( (string) $route ),
			selected( (string) $route, $selected, false ),
			esc_html( (string) $route )
		);
	}
	echo '</select>';
	echo '<p class="description">' . esc_html(
		sprintf( 'The %d route(s) block-map.json describes. Nothing else can be migrated from here.', count( $routes ) )
	) . '</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row">Overwrite</th><td>';
	// Never pre-ticked, on any render. Carrying it across submissions would turn
	// a decision about one page into a setting, and the whole point of it is
	// that somebody chose it deliberately this time.
	echo '<label><input type="checkbox" name="vs_overwrite_confirmed" value="yes"> ';
	echo 'Replace the sections already on this page.</label>';
	echo '<p class="description">Leave this alone unless a run has told you to. A page that already has '
		. 'sections is refused without it. Replacing them discards the order they are in, and nothing '
		. 'anywhere records what that order was — it is the only thing on this screen that cannot be '
		. 'undone.</p>';
	echo '</td></tr>';

	echo '</tbody></table>';

	echo '<p class="submit">';
	echo '<button type="submit" name="vs_dry_run" value="1" class="button button-primary button-large">'
		. 'Dry run &mdash; show me what it would write</button> ';
	echo '<button type="submit" name="vs_write" value="1" class="button button-large">'
		. 'Run it for real</button>';
	echo '</p>';
	echo '<p class="description">Dry run first, every time. It reads the page and reports, and writes '
		. 'nothing.</p>';

	echo '</form>';

	// Shown for every outcome except a failed plan. On a refusal in particular
	// this is the point of the screen: it is what WOULD replace the arrangement
	// already there, and nobody should tick the overwrite box without reading
	// it. A failed plan is deliberately not drawn — its rows stop at the first
	// broken block, and a half-built list read as a full one is worse than no
	// list at all.
	if ( is_array( $result ) && 'failed' !== $result['outcome'] && is_array( $result['plan'] ) && ! empty( $result['plan']['rows'] ) ) {
		render_plan( (array) $result['plan'] );
	}

	echo '<hr style="margin:2em 0">';

	render_status( $routes );

	echo '<p class="description">';
	echo esc_html( sprintf( 'Map: %s (sha1 %s)', (string) $map_meta['path'], (string) $map_meta['sha'] ) );
	echo '<br>';
	echo esc_html( sprintf( 'Layouts registered on this install: %s', implode( ', ', array_keys( $shapes ) ) ) );
	echo '</p>';
}

/**
 * The hero mode.
 *
 * Everything that decides or writes lives in backfill-hero.php and is reached
 * through vs_hb_plan_route() and vs_hb_apply_route(); this function draws a
 * form, reads a submission and prints a result. That is not tidiness for its
 * own sake — it is the fix for the thing the sections mode above got wrong.
 * Its run() re-implements the write that its own engine already contains, so
 * there are two writers for `blocks` and a dry run in one is not evidence about
 * the other. There is exactly one writer for the hero, and the WP-CLI driver
 * calls it too.
 */
function render_hero(): void {
	echo '<p>Fills each page&rsquo;s <strong>Hero</strong> boxes with the wording that page already renders, '
		. 'so the owner edits real words instead of blank ones. A correct run changes nothing on the site: '
		. 'the templates fall back to these same words while the boxes are empty, so moving them into '
		. 'WordPress is meant to be invisible. It writes at most five fields on one page and never touches '
		. 'the hero photo, its alt text or the photo treatment.</p>';

	list( $engine_ok, $engine_notes ) = hero_engine();

	if ( ! $engine_ok ) {
		render_notice( 'notice-error', $engine_notes );

		return;
	}

	$payload = read_payload();

	if ( isset( $payload['error'] ) ) {
		render_notice( 'notice-error', [ (string) $payload['error'] ] );

		return;
	}

	$routes = (array) $payload['routes'];

	list( $shape, $preflight_error ) = hero_preflight();

	if ( '' !== $preflight_error ) {
		render_notice( 'notice-error', [ $preflight_error ] );

		return;
	}

	$submission = submission( $routes, 'hero' );
	$plan       = null;
	$result     = null;

	if ( is_array( $submission ) && '' !== (string) $submission['error'] ) {
		render_notice( 'notice-error', [ (string) $submission['error'] ] );
	} elseif ( is_array( $submission ) ) {
		$route = (string) $submission['route'];

		$plan = \vs_hb_plan_route( $route, (array) $routes[ $route ], $shape );

		$result = \vs_hb_apply_route(
			$plan,
			$shape,
			(bool) $submission['write'],
			(bool) $submission['overwrite'],
			(string) $payload['sha']
		);

		$class = [
			'written'   => 'notice-success',
			'unchanged' => 'notice-info',
			'planned'   => 'notice-info',
			'refused'   => 'notice-warning',
			'failed'    => 'notice-error',
		][ $result['outcome'] ] ?? 'notice-info';

		render_notice(
			$class,
			array_merge(
				[ sprintf( '%s — %s', (string) $result['route'], strtoupper( (string) $result['outcome'] ) ) ],
				(array) $result['messages']
			)
		);
	}

	$selected = is_array( $submission ) ? (string) $submission['route'] : (string) array_key_first( $routes );

	echo '<form method="post" action="' . esc_url( admin_url( 'tools.php?page=' . MENU_SLUG ) ) . '">';
	wp_nonce_field( NONCE_ACTION );

	echo '<input type="hidden" name="vs_mode" value="hero">';

	echo '<table class="form-table" role="presentation"><tbody>';

	echo '<tr><th scope="row"><label for="vs_hero_route">Route</label></th><td>';
	echo '<select name="vs_route" id="vs_hero_route">';
	foreach ( array_keys( $routes ) as $route ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( (string) $route ),
			selected( (string) $route, $selected, false ),
			esc_html( (string) $route )
		);
	}
	echo '</select>';
	echo '<p class="description">' . esc_html(
		sprintf( 'The %d route(s) hero-payload.json describes. Nothing else can be filled from here.', count( $routes ) )
	) . '</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row">Overwrite</th><td>';
	// Never pre-ticked, for the reason the sections form gives: confirming an
	// overwrite is a decision about one page on one run, not a mode to leave on.
	echo '<label><input type="checkbox" name="vs_overwrite_confirmed" value="yes"> ';
	echo 'Replace hero wording somebody has already typed on this page.</label>';
	echo '<p class="description">Leave this alone unless a run has told you to. A page whose hero already '
		. 'holds different wording is refused without it &mdash; and refused whole, so none of its fields '
		. 'are written. Unlike the sections list, this one is recoverable: the wording it would replace is '
		. 'printed below before you agree to it.</p>';
	echo '</td></tr>';

	echo '</tbody></table>';

	echo '<p class="submit">';
	echo '<button type="submit" name="vs_dry_run" value="1" class="button button-primary button-large">'
		. 'Dry run &mdash; show me what it would write</button> ';
	echo '<button type="submit" name="vs_write" value="1" class="button button-large">'
		. 'Run it for real</button>';
	echo '</p>';
	echo '<p class="description">Dry run first, every time. It reads the page and reports, and writes '
		. 'nothing.</p>';

	echo '</form>';

	if ( is_array( $plan ) ) {
		render_hero_plan( $plan );
	}

	echo '<hr style="margin:2em 0">';

	render_hero_status( $routes, $shape );

	echo '<p class="description">';
	echo esc_html( sprintf( 'Payload: %s (sha1 %s)', (string) $payload['path'], (string) $payload['sha'] ) );
	echo '<br>';
	echo esc_html( sprintf( 'Hero sub-fields registered on this install: %s', implode( ', ', array_keys( $shape['fields'] ) ) ) );
	echo '<br>';
	echo esc_html( sprintf( 'Writable from here: %s', implode( ', ', \vs_hb_writable_fields() ) ) );
	echo '</p>';
}

/**
 * The per-field plan, field by field, in full.
 *
 * This is the screen's whole reason for existing: somebody has to be able to
 * see what a write would do, exactly, before agreeing to it. Rendered in <pre>
 * with the value between markers, because these values are compared byte for
 * byte — several legitimately contain newlines and runs of indentation the
 * built page reproduces, and a trailing space is the difference between a write
 * and a refusal. It is invisible without something either side of it.
 *
 * Everything goes through esc_html(). These values are page copy and several of
 * them contain markup: the headlines carry <em>, and one carries a class.
 */
function render_hero_plan( array $plan ): void {
	if ( ! empty( $plan['errors'] ) ) {
		echo '<h2>Why this cannot run</h2>';
		echo '<p>Nothing was written. Each of these is a fault in the payload or in what this page holds, '
			. 'and none of them is worked around by trying again.</p>';
		echo '<ul class="ul-disc">';
		foreach ( (array) $plan['errors'] as $error ) {
			echo '<li>' . esc_html( (string) $error ) . '</li>';
		}
		echo '</ul>';

		return;
	}

	echo '<h2>What this would write</h2>';
	echo '<p>' . esc_html(
		sprintf(
			'Page %d. Values are shown between [ and ] so a leading or trailing space is visible; a '
				. 'newline inside one is shown as \n.',
			(int) $plan['post_id']
		)
	) . '</p>';

	echo '<table class="widefat striped" style="max-width:70em"><thead><tr>';
	echo '<th style="width:8em">Field</th><th style="width:9em">What happens</th><th>Value</th>';
	echo '</tr></thead><tbody>';

	foreach ( (array) $plan['fields'] as $field ) {
		$action = (string) $field['action'];

		$says = [
			'write'    => 'written &mdash; the box is empty',
			'same'     => 'skipped &mdash; already exactly this',
			'conflict' => '<strong>in the way</strong> &mdash; holds something else',
		][ $action ] ?? esc_html( $action );

		echo '<tr>';
		echo '<td><code>' . esc_html( (string) $field['name'] ) . '</code></td>';
		echo '<td>' . $says . '</td>';
		echo '<td><pre style="white-space:pre-wrap;margin:0;font-size:12px">';
		echo esc_html( '[' . (string) $field['canonical'] . ']' );
		echo '</pre>';

		if ( 'conflict' === $action ) {
			echo '<p style="margin:.6em 0 .2em"><strong>Currently on the page:</strong></p>';
			echo '<pre style="white-space:pre-wrap;margin:0;font-size:12px;background:#fcf9e8">';
			echo esc_html( '[' . (string) $field['stored'] . ']' );
			echo '</pre>';
		}

		echo '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';

	if ( ! empty( $plan['omitted'] ) ) {
		$escaped = array_map(
			static function ( $name ): string {
				return '<code>' . esc_html( (string) $name ) . '</code>';
			},
			(array) $plan['omitted']
		);

		echo '<p style="margin-top:1em"><strong>Deliberately not written:</strong> ' . implode( ', ', $escaped ) . '.</p>';
		echo '<p>Those boxes are left exactly as they are. Where one is blank that is the correct stored '
			. 'value and not an oversight &mdash; the template keeps its own wording, which for these is '
			. 'the only rendering that is right. A <code>sub</code> holding a real link, or a button whose '
			. 'address is site data rather than page copy, cannot be stored in a plain-text box without '
			. 'publishing escaped tags or a stale link.</p>';
	}

	foreach ( (array) $plan['warnings'] as $warning ) {
		echo '<div class="notice notice-warning inline" style="margin:1em 0"><p>'
			. esc_html( (string) $warning ) . '</p></div>';
	}
}

/** Where every route in the payload currently stands. Read-only. */
function render_hero_status( array $routes, array $shape ): void {
	$writable = \vs_hb_writable_fields();

	echo '<h2>Where the pages stand</h2>';
	echo '<p>What each page&rsquo;s hero holds right now &mdash; read straight out of the database, not '
		. 'from any record of a previous run.</p>';

	echo '<table class="widefat striped" style="max-width:70em"><thead><tr>';
	echo '<th>Route</th><th>Page</th>';
	foreach ( $writable as $name ) {
		echo '<th>' . esc_html( $name ) . '</th>';
	}
	echo '<th>Filled</th>';
	echo '</tr></thead><tbody>';

	foreach ( array_keys( $routes ) as $route ) {
		$post_id  = \vs_hb_page_by_route( (string) $route );
		$previous = $post_id
			? json_decode( (string) get_post_meta( $post_id, \vs_hb_receipt_meta(), true ), true )
			: null;

		echo '<tr>';
		echo '<td><code>' . esc_html( (string) $route ) . '</code></td>';
		echo '<td>' . ( $post_id ? esc_html( (string) $post_id ) : '<strong>not found</strong>' ) . '</td>';

		foreach ( $writable as $name ) {
			$stored = $post_id ? \vs_hb_stored( $post_id, $name, $shape ) : '';

			echo '<td>' . ( '' === $stored ? '&mdash;' : '&#10003;' ) . '</td>';
		}

		echo '<td>' . esc_html(
			is_array( $previous ) && isset( $previous['when'] )
				? (string) $previous['when'] . ' (payload ' . (string) ( $previous['payload'] ?? '?' ) . ')'
					. ( ! empty( $previous['forced'] ) ? ' FORCED' : '' )
				: '—'
		) . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';
	echo '<p class="description">A dash is an empty box, which is not a fault: the fields a route '
		. 'deliberately leaves out stay empty for good, and the page keeps its template wording for them.</p>';
}
