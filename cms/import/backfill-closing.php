<?php
/**
 * Fill each page's `closing` group with the wording that page already renders.
 *
 *   npx wp-env run cli wp eval-file wp-content/vs-import/bin/backfill-closing.php dry-run
 *   npx wp-env run cli wp eval-file wp-content/vs-import/bin/backfill-closing.php
 *   npx wp-env run cli wp eval-file wp-content/vs-import/bin/backfill-closing.php /our-office/
 *
 * Commit 66b5469 made the two closing bands editable: the consultation invite
 * (its small line, headline and paragraph) on 20 routes and the booking-strip
 * sentence on 17. Every one of those templates now prefers `closing.consult_*`
 * / `closing.note` from WordPress and falls back to its own literal when the
 * box is blank. They are all blank. The feature works and the owner cannot use
 * it — opening the Bottom of page tab shows empty boxes, so changing one word
 * of his own invite means retyping the whole thing.
 *
 * This script types them in for him, from cms/import/closing-payload.json,
 * which holds the words each page renders today for 22 routes. One honest
 * difference from the hero run, recorded in the payload's own `_` block: the
 * values were extracted from the templates' JSX fallbacks with the indentation
 * collapsed, and Astro bakes that indentation into the built HTML, so a correct
 * run changes WHITESPACE on about 20 routes and changes no word on any. The
 * acceptance test is therefore the words sweep, not raw byte identity; a run
 * that changes a word has gone wrong.
 *
 * DERIVED FROM backfill-hero.php, deliberately, and deliberately a separate
 * file. The planner, the validator, the writer and the receipt are that file's,
 * proven on 24 pages, with the hero's two non-text sub-fields (the `ctas`
 * repeater and the `ratings` switch) taken out because this group has none.
 * It cannot simply BE that file with a different group key: both engines
 * define plain global functions, and the Tools screen includes whichever one
 * the chosen mode needs — a second file declaring the hero engine's planner
 * under the hero engine's name in the same request is a fatal "cannot
 * redeclare", which on a must-use plugin is a blank wp-admin. So this file
 * carries its own `vs_cb_` prefix throughout, announces itself with its own
 * sentinel, and leaves its own receipt. A fix found in one engine should be
 * looked for in the other.
 *
 * WHAT IT TOUCHES, exhaustively: at most the four sub-fields of one group on
 * one page — consult_eyebrow, consult_headline, consult_body, note. Nothing
 * else. Not the hero, not the images, not `blocks`, not the six source
 * repeaters, not the post itself, not SEO. Unlike the hero group this list IS
 * the whole group, so vs_cb_writable_fields() is not fencing off a photo; it is
 * the contract a hand-edited payload is checked against, so a fifth key — or a
 * renamed one — is a hard error rather than a silently ignored one.
 *
 * FIELD ABSENT MEANS DELIBERATELY BLANK, and that is load-bearing rather than
 * lazy. The templates keep their literal exactly while a box is empty, so for
 * the values the payload cannot represent honestly — a paragraph or a sentence
 * containing a real <a>, or the live {phoneLabel} expression — blank IS the
 * correct stored value and anything else would publish escaped tags or a
 * frozen phone number. Seven values are left out for exactly that reason, each
 * named in the payload's `_.left_on_template`. An omitted field is therefore
 * never written, never cleared and never defaulted.
 *
 * HOW THAT IS ENFORCED, since it is the requirement most easily broken by a
 * plausible-looking change. The write is ONE update_field() call against the
 * GROUP, by field key, carrying only the sub-fields being filled:
 *
 *     update_field( 'field_vs_page_closing', [ 'note' => '…' ], $id );
 *
 * ACF's group type loops its REGISTERED sub-fields and, for each one, looks for
 * the value under the sub-field's key and then under its name. Finding neither
 * it does `continue` — it does not write, and it does not delete. So omission is
 * enforced by ACF itself rather than by care on our part, which is the only kind
 * of enforcement worth relying on. (includes/fields/class-acf-field-group.php,
 * update_value(); the same loop prefixes each sub-field's name with the group's,
 * which is how `note` becomes the `closing_note` meta key.)
 *
 * TWO TRAPS IN THAT SENTENCE, both of which look like improvements:
 *
 *   1. DO NOT write a sub-field by its own key. `update_field(
 *      'field_vs_page_closing_note', $v, $id )` is the obvious refactor and it
 *      is wrong: update_field() resolves that key to a field whose `name` is
 *      still the bare `note`, because the group prefix is applied at write time
 *      by the PARENT and nothing else. The value lands in postmeta `note` /
 *      `_note` — a pair of orphans invisible to wp-admin and to WPGraphQL — and
 *      the closing group is left exactly as empty as it was, with every return
 *      value saying success.
 *
 *   2. DO NOT call update_field() on the group with an EMPTY array. ACF guards
 *      its group update with acf_is_array(), which is `is_array() && ! empty()`,
 *      so [] fails it, update_value() returns null, and acf_update_value() reads
 *      null as "delete" and calls the group's delete_value() — which loops every
 *      sub-field and deletes it. An empty write request DELETES THE WHOLE GROUP:
 *      here that is all four boxes, including the ones the payload deliberately
 *      leaves out and whatever an editor has typed into them since. Smaller
 *      blast radius than the hero's photo, same mechanism, same two locks:
 *      vs_cb_apply_route() returns before writing when there is nothing to
 *      write, and still checks the array is non-empty immediately before the
 *      call. Both, on purpose.
 *
 * IDEMPOTENT BY COMPARISON, not by bookkeeping. Every carried field is compared
 * against what is stored right now, byte for byte, before anything is written:
 * an empty box is written, a box already holding exactly this value is skipped,
 * and a box holding something else is a refusal. A second run therefore finds
 * every field in the "already holds exactly this" state and writes nothing at
 * all — which stays true even if the receipt below is deleted, or the run
 * happens on a different machine, or somebody restores a backup. The receipt is
 * an audit trail and nothing depends on it.
 *
 * IT REFUSES A CLOSING SOMEBODY HAS ALREADY TYPED IN. If any field the payload
 * carries holds a different non-empty value, the whole route is refused and
 * NOTHING is written for it — not even the fields that would have been clean.
 * Half an invite is a state nobody has a procedure for. `force` overrides, and
 * the dry run prints both values first so the decision is made on evidence.
 *
 * THE SWITCH IS THE HEADLINE, and the hero's ratings trap has no twin here —
 * worth saying so the next reader does not go looking for it. The consult trio
 * renders from WordPress only while `consultOn` is true, and that is
 * `consultHeadline !== ""` (src/lib/page-content.ts:349); `note` is not part of
 * the trio and stands on its own. Three consequences this script enforces:
 *
 *   - A route carrying ONLY `note` is valid. Two of the 22 are exactly that —
 *     pages with a booking strip and no consultation invite.
 *   - A route carrying `consult_eyebrow` or `consult_body` that would still have
 *     no `consult_headline` after the run is a hard error: those values would be
 *     stored and never drawn, which looks like success and is not.
 *   - Filling the headline turns nothing OFF. When the switch flips, a blank
 *     eyebrow still falls to the template's own via `||` and a blank paragraph
 *     via its ternary (about-us/index.astro:627-629 is the shape every page
 *     uses), so there is no field that draws by default while the group is
 *     blank and vanishes the moment it is not. That is the whole difference
 *     from `ratings`, and why no payload entry here is required to carry
 *     anything but what it means to say.
 *
 * TAGS IN THE PLAIN BOXES ARE REFUSED. Three of the four render escaped — the
 * small line as `{eyebrow}` (VirtualConsult.astro:75), the paragraph and the
 * sentence as `{closing.consultBody}` and `{closing.note}` in every template —
 * so a stored <a> would be published as visible angle brackets. Only the
 * headline goes through `set:html`, and its wp-admin instructions teach exactly
 * one tag, a bare <em>; anything else in it is reported as a warning rather
 * than refused, because it would render as real markup and be seen.
 *
 * BACKSLASHES ARE REFUSED. update_metadata() runs wp_unslash() over the value it
 * is given, so a lone backslash is eaten on the way into the database and the
 * stored value silently stops matching the payload. wp_slash() would round-trip
 * it, but no importer in this repo slashes and today's payload contains not one
 * backslash, so the write path stays identical to its proven siblings and a
 * value that would be mangled is reported instead. If that ever fires, slash it
 * here deliberately rather than discovering the missing character on the page.
 *
 * ARGUMENTS. `wp eval-file` passes positional arguments in $args and eats
 * anything starting with a dash as one of its own flags, so the words are bare:
 *
 *   dry-run          plan and report, write nothing
 *   force            overwrite a closing somebody has already typed into
 *   /a/route/        limit the run to one route; repeatable. Default: every
 *                    route in the payload
 *   payload=<path>   an absolute path to a different closing-payload.json
 *
 * Environment equivalents, for hosts where arguments are awkward to pass:
 * VS_CLOSING_DRY_RUN, VS_CLOSING_FORCE, VS_CLOSING_ROUTE, VS_CLOSING_PAYLOAD.
 *
 * NO SHELL ON THE CMS HOST. GoDaddy Managed WordPress has no SSH and therefore
 * no WP-CLI, so in practice this runs from Tools → Page content migration,
 * which includes this file as a library and calls the same functions the
 * driver at the bottom calls. One writer, two front ends. See the guard above
 * the driver for how that is arranged and why the guard is at the BOTTOM.
 *
 * No `declare(strict_types=1)`, no `namespace`, no top-level `const` and no
 * `__DIR__` — see import-reviews.php and backfill-blocks.php for why: this file
 * is also run through `wp eval-file`, which eval()s it, where a const
 * declaration is a parse error and __DIR__ resolves to WP-CLI's own source
 * directory rather than this file's.
 */

/**
 * The page whose _vs_route meta matches, or 0.
 *
 * Matched on the full route rather than the slug, for the reason
 * import-pages.php gives: slugs repeat across branches.
 *
 * Deliberately a third copy of vs_bb_page_by_route() rather than a call to it
 * or to the hero engine's copy. This file has to stand alone — the Tools screen
 * includes whichever engine the chosen mode needs and not the others, and a
 * cross-file dependency would make the closing mode fail whenever the file it
 * leaned on happened to be absent.
 */
function vs_cb_page_by_route( $route ) {
	$found = get_posts(
		[
			'post_type'        => 'page',
			'post_status'      => 'any',
			'numberposts'      => 1,
			'meta_key'         => '_vs_route',
			'meta_value'       => $route,
			'suppress_filters' => false,
		]
	);

	return $found ? (int) $found[0]->ID : 0;
}

/** The ACF key of the closing group. Written by KEY; see the docblock. */
function vs_cb_group_key() {
	return 'field_vs_page_closing';
}

/** The group's field name, and therefore the prefix on every sub-field's meta key. */
function vs_cb_group_name() {
	return 'closing';
}

/**
 * The bookkeeping meta a successful run leaves behind.
 *
 * Exposed as a function so the Tools screen reads the name from here rather than
 * declaring its own copy. Two spellings of one meta key is how two runners stop
 * recognising each other's work. Distinct from the hero engine's receipt on
 * purpose: one page will carry both, and each answers for its own group.
 */
function vs_cb_receipt_meta() {
	return '_vs_closing_backfill';
}

/**
 * The only sub-fields this script may ever write.
 *
 * A closed list, checked against the payload before anything else happens.
 * Here it happens to be the entire group — there is no photo to fence off —
 * but the list still earns its place: it is the payload's contract, and a key
 * outside it is either a typo or a registration that has moved, both of which
 * ACF would swallow without a word. Naming one is an error rather than a
 * silent skip, because a silent skip is how somebody spends an afternoon
 * wondering why their key did nothing.
 *
 * In registration order, which is the order every report prints in.
 */
function vs_cb_writable_fields() {
	return [ 'consult_eyebrow', 'consult_headline', 'consult_body', 'note' ];
}

/**
 * The sub-field TYPES this script knows how to store and read back as one
 * string.
 *
 * vs-content-model.php registers the four boxes as one `text` and three
 * `textarea`, and for both the stored meta is the value itself, byte for byte.
 * The hero engine also knew a repeater and a true/false; this one deliberately
 * does not, so if the live registration ever says a writable field is some
 * other type, the planner refuses that field with the type named instead of
 * guessing at how to compare it. Read live, refuse on drift — never fatal.
 */
function vs_cb_writable_types() {
	return [ 'text', 'textarea' ];
}

/**
 * The closing group as this install actually registers it: sub-field name =>
 * type.
 *
 * Read off the live registration rather than the source, for the reason
 * backfill-blocks.php gives about layouts and the reason applies harder here:
 * ACF's group loop iterates the fields it KNOWS, so a payload key naming a
 * sub-field this install does not have is not dropped with a complaint — it is
 * never looked at. Nothing would be written and nothing would be said. Checked
 * up front instead, against this.
 *
 * Flat, because the group is flat. The hero's version also catalogued nested
 * repeaters; a sub-field of any type this script cannot write is caught by the
 * type check in vs_cb_validate_value() rather than modelled here.
 */
function vs_cb_group_shape( $field ) {
	$shape = [
		'fields' => [],
	];

	foreach ( (array) ( $field['sub_fields'] ?? [] ) as $sub ) {
		$name = (string) ( $sub['name'] ?? '' );

		if ( '' === $name ) {
			continue;
		}

		$shape['fields'][ $name ] = (string) ( $sub['type'] ?? '' );
	}

	return $shape;
}

/**
 * What one closing sub-field holds RIGHT NOW, as a single comparable string.
 *
 * '' means empty — never written, or written and cleared. Every non-empty value
 * this script can produce is a non-empty string, so '' is unambiguous.
 *
 * Read from postmeta rather than through get_field(), the way vs-migrate.php
 * reads `blocks`: this is asking what bytes are in the database, and ACF's
 * formatting layer is a filter between the question and the answer. It also
 * makes the read-back check after a write mean what it says.
 *
 * $shape is accepted so the call has the same shape as the hero engine's, which
 * is what the Tools screen copies when it grows a closing mode; a group of four
 * plain boxes has no nested structure to consult, so it is not read.
 */
function vs_cb_stored( $post_id, $name, $shape ) {
	unset( $shape );

	return (string) get_post_meta( $post_id, vs_cb_group_name() . '_' . $name, true );
}

/**
 * One value as a short line, with its edges marked.
 *
 * The markers are not decoration. These values are compared byte for byte and
 * several of them legitimately contain newlines and runs of indentation the
 * built page reproduces; a trailing space is the difference between a write and
 * a refusal, and it is invisible without something either side of it.
 */
function vs_cb_preview( $text, $limit = 160 ) {
	$text = (string) $text;

	if ( '' === $text ) {
		return '(blank)';
	}

	$flat = preg_replace( '/\n/', '\\n', $text );
	$flat = null === $flat ? $text : $flat;

	if ( function_exists( 'mb_strlen' ) ? mb_strlen( $flat ) > $limit : strlen( $flat ) > $limit ) {
		$flat = ( function_exists( 'mb_substr' ) ? mb_substr( $flat, 0, $limit - 3 ) : substr( $flat, 0, $limit - 3 ) ) . '...';
	}

	return '[' . $flat . '] ' . sprintf( '(%d bytes)', strlen( $text ) );
}

/**
 * Decide what one route needs, and write nothing.
 *
 * Returns:
 *
 *   route     the route as given
 *   post_id   the page it resolved to, or 0
 *   fields    one entry per sub-field the payload CARRIES, in registration
 *             order, each with an action of write | same | conflict
 *   omitted   the writable sub-fields the payload does not carry, which is to
 *             say the ones that must stay empty so the template keeps its
 *             literal. Reported because "left blank" is a decision here and a
 *             decision nobody sees is a decision nobody checked.
 *   errors    anything that makes this route unsafe to write. Any error at all
 *             and the route is not written, forced or otherwise.
 *   warnings  things the operator should read before agreeing, which do not by
 *             themselves stop a write.
 *
 * Pure. It reads the payload, the registration and the database, and touches
 * none of them.
 */
function vs_cb_plan_route( $route, $entry, $shape ) {
	$plan = [
		'route'    => $route,
		'post_id'  => 0,
		'fields'   => [],
		'omitted'  => [],
		'errors'   => [],
		'warnings' => [],
	];

	if ( ! is_array( $entry ) || ! $entry ) {
		$plan['errors'][] = 'the payload entry for this route is empty or is not an object';

		return $plan;
	}

	$post_id = vs_cb_page_by_route( $route );

	if ( ! $post_id ) {
		$plan['errors'][] = "no WordPress page has _vs_route = {$route} — run: cd cms && npm run import:pages";

		return $plan;
	}

	$plan['post_id'] = $post_id;

	$writable = vs_cb_writable_fields();

	// Every key the payload names, before any of them is acted on. A key this
	// script will not write, or one this install does not register, is a fault in
	// the payload and not a thing to route around: ACF's group loop would never
	// look at it, so the run would report success over a field that was never
	// written.
	foreach ( array_keys( $entry ) as $name ) {
		$name = (string) $name;

		if ( ! in_array( $name, $writable, true ) ) {
			$plan['errors'][] = sprintf(
				'`%s` is not a field this script writes. It writes exactly: %s — which is the whole of the '
					. 'closing group. Anything else on the page is out of scope on purpose.',
				$name,
				implode( ', ', $writable )
			);
			continue;
		}

		if ( ! isset( $shape['fields'][ $name ] ) ) {
			$plan['errors'][] = sprintf(
				'`%s` is not registered on the closing group on this install. It is declared in '
					. 'cms/mu-plugins/vs-content-model.php; deploy that file before running. Writing it now '
					. 'would store nothing and report success.',
				$name
			);
		}
	}

	if ( $plan['errors'] ) {
		return $plan;
	}

	// Walked in the registration's order rather than the payload's, so two routes
	// always print their fields in the same order and a reader can compare them.
	foreach ( $writable as $name ) {
		if ( ! array_key_exists( $name, $entry ) ) {
			$plan['omitted'][] = $name;
			continue;
		}

		$value  = $entry[ $name ];
		$type   = (string) $shape['fields'][ $name ];
		$faults = vs_cb_validate_value( $name, $value, $type );

		if ( $faults ) {
			$plan['errors'] = array_merge( $plan['errors'], $faults );
			continue;
		}

		// What to hand update_field() and the string to compare it against are
		// one and the same here: every box is plain text, and its stored meta is
		// the value byte for byte. The hero engine built the pair in one place so
		// its repeater rows and its switch could not drift apart; with four
		// strings there is nothing to drift, but the read-back after the write
		// still compares against exactly what was sent, which is the point.
		$write_value = (string) $value;
		$canonical   = $write_value;

		$stored = vs_cb_stored( $post_id, $name, $shape );

		if ( '' === $stored ) {
			$action = 'write';
		} elseif ( $stored === $canonical ) {
			$action = 'same';
		} else {
			$action = 'conflict';
		}

		$plan['fields'][] = [
			'name'      => $name,
			'type'      => $type,
			'action'    => $action,
			'value'     => $write_value,
			'canonical' => $canonical,
			'stored'    => $stored,
		];
	}

	if ( $plan['errors'] ) {
		return $plan;
	}

	$carried = [];

	foreach ( $plan['fields'] as $field ) {
		$carried[ $field['name'] ] = $field;
	}

	// AN INVITE THAT CANNOT RENDER. The templates read the small line and the
	// paragraph only while consultOn is true, and consultOn is
	// `consultHeadline !== ""` (page-content.ts:349). Writing either onto a page
	// with no headline stores a value nothing will ever draw, which looks like
	// success and is not. `note` is deliberately not in this list: it is not part
	// of the trio and a route carrying only a booking-strip sentence is valid.
	$needs_headline = array_values(
		array_filter(
			[ 'consult_eyebrow', 'consult_body' ],
			static function ( $name ) use ( $carried ) {
				return isset( $carried[ $name ] );
			}
		)
	);

	if ( $needs_headline ) {
		$headline_after_run = isset( $carried['consult_headline'] )
			? (string) $carried['consult_headline']['canonical']
			: vs_cb_stored( $post_id, 'consult_headline', $shape );

		if ( '' === $headline_after_run ) {
			$plan['errors'][] = sprintf(
				'carries %s, but this page would still have no `consult_headline` afterwards. The invite only '
					. 'renders from WordPress when the headline is filled (page-content.ts:349: consultOn = '
					. 'consultHeadline !== ""), so those values would be stored and never drawn. Add the '
					. 'headline, or drop these.',
				implode( ' and ', array_map(
					static function ( $name ) {
						return '`' . $name . '`';
					},
					$needs_headline
				) )
			);
		}
	}

	// Not an error, and not silent either. The headline is the one box rendered
	// with set:html, and its wp-admin instructions teach exactly one tag — a bare
	// <em>. wp-admin runs a logged-in user's textarea save through wp_kses unless
	// they hold unfiltered_html; <em> survives that, a class attribute does not.
	// Nothing this script does causes that — update_field() does not filter — but
	// the first time the owner opens this page and presses Update, it is the save
	// path the value goes through, and that is worth knowing BEFORE 20 pages are
	// filled.
	if ( isset( $carried['consult_headline'] ) ) {
		$headline = (string) $carried['consult_headline']['canonical'];

		if ( false !== strpos( $headline, 'class=' ) ) {
			$plan['warnings'][] = 'the headline carries a class attribute. The Bottom of page instructions teach a '
				. 'bare <em>, and wp_kses strips attributes on a normal save — check that saving this page in '
				. 'wp-admin afterwards leaves it intact, or use <em> with no attribute.';
		}

		// `<em ...>` with an attribute is still an em — the attribute is the
		// warning above, and one fact should be reported once.
		if ( preg_match( '/<(?!\/?em[\s>])[a-z\/!]/i', $headline ) ) {
			$plan['warnings'][] = 'the headline carries a tag other than <em>. It is rendered with set:html, so it '
				. 'will appear as real markup on the page — make sure that is meant.';
		}
	}

	// Site data, which the Bottom of page instructions ask the owner to keep out
	// of the paragraph: a phone number stored in a page's copy is a second copy of
	// a fact that lives in Practice Settings, and it goes stale silently. A
	// warning rather than a refusal, because the pattern is a guess at what a
	// phone number looks like and "600+ hours" must not stop a run.
	foreach ( [ 'consult_body', 'note' ] as $name ) {
		if ( isset( $carried[ $name ] )
			&& preg_match( '/\(?\d{3}\)?[\s.-]\d{3}[\s.-]\d{4}/', (string) $carried[ $name ]['canonical'] )
		) {
			$plan['warnings'][] = sprintf(
				'`%s` looks as though it contains a phone number. The site\'s buttons always carry the current '
					. 'one from Practice Settings; a number typed into copy goes out of date on its own. Consider '
					. 'leaving this key out and letting the template keep its own sentence.',
				$name
			);
		}
	}

	return $plan;
}

/**
 * Everything that is wrong with one payload value, as plain sentences.
 *
 * Strict on purpose. Each of these has a specific way of going wrong quietly,
 * and the alternative to refusing here is finding out from the live site.
 */
function vs_cb_validate_value( $name, $value, $type ) {
	// The type is read off the live registration, not assumed. A writable field
	// that this install registers as something other than a plain text box is a
	// drift between the content model and this script, and the honest response is
	// to say so and stop — not to store a string into a repeater and read back
	// nonsense.
	if ( ! in_array( $type, vs_cb_writable_types(), true ) ) {
		return [
			sprintf(
				'`%s` is registered as %s on this install, and this script only knows how to fill a plain '
					. 'text box (%s). Either cms/mu-plugins/vs-content-model.php has changed the field, or this '
					. 'script needs teaching the new type. It will not guess.',
				$name,
				'' === $type ? 'an untyped field' : '`' . $type . '`',
				implode( ' or ', vs_cb_writable_types() )
			),
		];
	}

	if ( ! is_string( $value ) ) {
		return [ sprintf( '`%s` must be a string, not %s.', $name, gettype( $value ) ) ];
	}

	$faults = [];

	if ( '' === $value ) {
		// Ambiguous, so refused. A key holding "" could mean "store nothing here"
		// or "clear what is there", and the file's own contract is that a field to
		// be left empty is a field left OUT. Say it by omission.
		$faults[] = sprintf(
			'`%s` is an empty string. Leave the key out entirely to keep the box blank — an omitted field '
				. 'is never written, which is how the template keeps its own literal.',
			$name
		);
	}

	// Three of the four boxes render escaped: the small line as {eyebrow}
	// (VirtualConsult.astro:75), the paragraph and the sentence as
	// {closing.consultBody} and {closing.note} in every page template. A tag
	// stored in one of them is published as visible angle brackets, which is
	// exactly why closing-payload.json leaves seven values on their templates.
	// The headline is the one box rendered with set:html and is checked for its
	// tags in the planner instead. Matched on "looks like a tag" rather than on
	// a bare `<`, because "<1 hour" is a sentence and renders correctly.
	if ( 'consult_headline' !== $name && preg_match( '/<[a-z\/!]/i', $value ) ) {
		$faults[] = sprintf(
			'`%s` contains what looks like an HTML tag. This box is rendered as plain text, so the tag would '
				. 'be published as literal angle brackets rather than as markup. Leave the key out for this '
				. 'route and let the template keep its own wording — the payload already does that for the '
				. 'values that carry a link.',
			$name
		);
	}

	return array_merge( $faults, vs_cb_check_string( '`' . $name . '`', $value ) );
}

/**
 * The one character that does not survive the trip into postmeta.
 *
 * update_metadata() calls wp_unslash() on the value it is handed, so a lone
 * backslash is stripped between here and the database and the stored value stops
 * matching the payload — by one invisible character, on a page whose whole
 * acceptance test is that no word changes. wp_slash() before the write would
 * round-trip it correctly; no importer in this repo does that, and today's
 * payload contains no backslash at all, so the write path is left identical to
 * its siblings and the case is reported rather than guessed at.
 */
function vs_cb_check_string( $where, $value ) {
	if ( false !== strpos( (string) $value, '\\' ) ) {
		return [
			sprintf(
				'%s contains a backslash. update_metadata() runs wp_unslash() over what it is given, so it '
					. 'would be eaten on the way in and the stored value would silently differ from the '
					. 'payload. Slash it deliberately in this script if it is really wanted.',
				$where
			),
		];
	}

	return [];
}

/** How many of a plan's carried fields fall into each action. */
function vs_cb_counts( $plan ) {
	$counts = [
		'write'    => 0,
		'same'     => 0,
		'conflict' => 0,
	];

	foreach ( (array) $plan['fields'] as $field ) {
		$action = (string) $field['action'];

		if ( isset( $counts[ $action ] ) ) {
			$counts[ $action ]++;
		}
	}

	return $counts;
}

/**
 * Act on one plan. THE ONLY FUNCTION IN THIS PROJECT THAT WRITES A CLOSING GROUP.
 *
 * The Tools screen and the WP-CLI driver below both call this. That is
 * deliberate and it is the lesson of the sections migration, whose screen grew
 * its own copy of the write: two implementations against one live CMS drift, and
 * the way you find out is a dry run in one disagreeing with a write in the
 * other, on a page nobody looks at for a month.
 *
 * Returns [ outcome, messages, written ] where outcome is one of:
 *
 *   failed     the plan has errors, or the write did not take. Nothing written.
 *   refused    a field already holds something else and force was not given.
 *              Nothing written — not even the clean fields on the same route.
 *   unchanged  every carried field already holds exactly this. Nothing written.
 *   planned    dry run. Nothing written.
 *   written    the fields named in `written` were written and read back.
 */
function vs_cb_apply_route( $plan, $shape, $write, $force, $payload_sha = '' ) {
	$result = [
		'route'    => (string) $plan['route'],
		'outcome'  => 'failed',
		'messages' => [],
		'written'  => [],
	];

	if ( ! empty( $plan['errors'] ) ) {
		$result['messages'][] = sprintf(
			'%d problem(s) with this route. Nothing was written.',
			count( $plan['errors'] )
		);
		$result['messages'] = array_merge( $result['messages'], (array) $plan['errors'] );

		return $result;
	}

	$post_id   = (int) $plan['post_id'];
	$conflicts = [];
	$todo      = [];

	foreach ( (array) $plan['fields'] as $field ) {
		if ( 'conflict' === $field['action'] ) {
			$conflicts[] = $field;
		}

		if ( 'write' === $field['action'] || ( $force && 'conflict' === $field['action'] ) ) {
			$todo[] = $field;
		}
	}

	// ALL OR NOTHING PER ROUTE. A conflict on one field stops the whole group,
	// including the fields that were clean. An invite half from the payload and
	// half from an editor is a state with no procedure and no way to tell by
	// looking which half came from where.
	if ( $conflicts && ! $force ) {
		$result['outcome']    = 'refused';
		$result['messages'][] = sprintf(
			'Refused. %d field(s) on this page already hold something other than what this run would '
				. 'write, so nothing was written for this route at all — not even the empty fields.',
			count( $conflicts )
		);

		foreach ( $conflicts as $field ) {
			$result['messages'][] = sprintf(
				'  %s — stored %s',
				$field['name'],
				vs_cb_preview( $field['stored'] )
			);
			$result['messages'][] = sprintf(
				'  %s — would write %s',
				str_repeat( ' ', strlen( (string) $field['name'] ) ),
				vs_cb_preview( $field['canonical'] )
			);
		}

		$result['messages'][] = 'Either somebody has typed into these boxes, or the payload has changed since '
			. 'the last run. Read both values above before deciding.';
		$result['messages'][] = 'To replace what is there: run again with force. To keep it: do nothing — '
			. 'the page renders from what it holds now either way.';

		return $result;
	}

	if ( ! $todo ) {
		$result['outcome']    = 'unchanged';
		$result['messages'][] = sprintf(
			'Already filled: all %d field(s) this route carries hold exactly these values. Nothing to do, '
				. 'and nothing was written.',
			count( (array) $plan['fields'] )
		);

		return $result;
	}

	$names = [];

	foreach ( $todo as $field ) {
		$names[] = (string) $field['name'];
	}

	if ( ! $write ) {
		$result['outcome']    = 'planned';
		$result['messages'][] = sprintf(
			'Dry run. %d field(s) would be written to page %d: %s. Nothing was written.',
			count( $todo ),
			$post_id,
			implode( ', ', $names )
		);

		if ( $conflicts ) {
			$result['messages'][] = sprintf(
				'FORCED: %d of those already hold a different value and would be replaced.',
				count( $conflicts )
			);
		}

		if ( ! empty( $plan['omitted'] ) ) {
			$result['messages'][] = sprintf(
				'Not written, because the payload leaves them out: %s. They keep whatever they hold now — '
					. 'and while a box is blank, that means the template keeps its own wording.',
				implode( ', ', (array) $plan['omitted'] )
			);
		}

		return $result;
	}

	$values = [];

	foreach ( $todo as $field ) {
		$values[ (string) $field['name'] ] = $field['value'];
	}

	// The guard described at the top, restated at the point of use because this
	// is where it matters: acf_is_array() is `is_array() && ! empty()`, so an
	// empty array makes the group's update_value() return null, which
	// acf_update_value() reads as a delete, which runs the group's delete_value()
	// over EVERY sub-field — the boxes the payload leaves out, and anything an
	// editor has typed into them, included. The branch above already returns when
	// there is nothing to write; this is the second lock.
	if ( ! $values ) {
		$result['messages'][] = 'Internal check failed: nothing to write, but the write path was reached. '
			. 'Refusing, because an empty write against an ACF group deletes the group.';

		return $result;
	}

	// ONE call, against the GROUP, by field KEY, carrying only these sub-fields.
	//
	// Writing the parent by key rather than by name is the convention
	// import-pages.php sets out and the reason holds here: a value written by name
	// leaves SCF without its companion _field reference and the result invisible
	// to both wp-admin and WPGraphQL. Writing each sub-field by ITS own key is the
	// tempting alternative and is wrong — see trap 1 in the docblock.
	//
	// ACF walks its registered sub-fields and skips every one this array does not
	// mention, so every box the payload left out comes through untouched — along
	// with whatever an editor has since typed into it. That is the omission
	// contract, and it is enforced by ACF rather than by this line being careful.
	update_field( vs_cb_group_key(), $values, $post_id );

	// Read back rather than trust the return value, exactly as vs-migrate.php
	// does. update_field() on a group returns the parent's own meta write and says
	// nothing useful about the sub-fields; the receipt below is what a later
	// reader treats as proof, and stamping it over a write that did not land is
	// how a page ends up permanently recorded as done while holding nothing.
	$mismatched = [];

	foreach ( $todo as $field ) {
		$now = vs_cb_stored( $post_id, (string) $field['name'], $shape );

		if ( $now !== (string) $field['canonical'] ) {
			$mismatched[] = sprintf(
				'  %s — sent %s, stored %s',
				$field['name'],
				vs_cb_preview( $field['canonical'] ),
				vs_cb_preview( $now )
			);
		}
	}

	if ( $mismatched ) {
		$result['messages'][] = sprintf(
			'The write did not take cleanly on %d field(s), so no receipt was recorded and this page is '
				. 'not considered filled. Check the values below and try again.',
			count( $mismatched )
		);
		$result['messages'] = array_merge( $result['messages'], $mismatched );

		return $result;
	}

	// A record of what was STORED, not of what was intended — the distinction
	// vs-migrate.php pays for elsewhere. Bookkeeping only: the decision to write
	// or skip is made by comparing fields against the database, so a deleted or
	// stale receipt cannot cause a wrong write. It is here to answer "when, from
	// which payload, and was it forced".
	$recorded = [];

	foreach ( (array) $plan['fields'] as $field ) {
		$recorded[ (string) $field['name'] ] = vs_cb_stored( $post_id, (string) $field['name'], $shape );
	}

	update_post_meta(
		$post_id,
		vs_cb_receipt_meta(),
		(string) wp_json_encode(
			[
				'hash'    => md5( (string) wp_json_encode( $recorded ) ),
				'fields'  => $names,
				'omitted' => array_values( (array) $plan['omitted'] ),
				'payload' => (string) $payload_sha,
				'route'   => (string) $plan['route'],
				'forced'  => (bool) ( $force && $conflicts ),
				'when'    => gmdate( 'c' ),
			]
		)
	);

	$result['outcome'] = 'written';
	$result['written'] = $names;

	$result['messages'][] = sprintf(
		'Wrote %d field(s) to page %d: %s.',
		count( $names ),
		$post_id,
		implode( ', ', $names )
	);

	if ( ! empty( $plan['omitted'] ) ) {
		$result['messages'][] = sprintf(
			'Not written, because the payload leaves them out: %s. They hold exactly what they held '
				. 'before this run — and a box left blank is how the template keeps its own wording, which '
				. 'for the fields this payload omits is the only correct rendering.',
			implode( ', ', (array) $plan['omitted'] )
		);
	}

	$result['messages'][] = 'Nothing else on this page was touched: not the hero, not the images, not the '
		. 'page sections, not the six source repeaters, not the SEO fields.';
	$result['messages'][] = 'To undo: open the page, Page content, Bottom of page, and empty the boxes. The '
		. 'template renders its own wording again on the next build, with no deploy and no code change.';

	// update_field() fires neither transition_post_status nor acf/save_post, so
	// vs-deploy.php never hears about this and the front end keeps serving the
	// pre-write page indefinitely. Telling the operator to open each page and
	// press Update is a step nobody remembers on page nineteen of twenty-two.
	if ( function_exists( 'VividSmiles\\Deploy\\queue' ) ) {
		call_user_func( 'VividSmiles\\Deploy\\queue', sprintf( 'closing copy back-filled for %s', $plan['route'] ) );
		$result['messages'][] = 'A front-end rebuild has been queued; the live site picks this up in a few '
			. 'minutes. It should read identically — whitespace may move, no word should.';
	} else {
		$result['messages'][] = 'NOTE: the deploy trigger is not available here, so the live site will not '
			. 'rebuild by itself. Open the page and press Update to queue a build.';
	}

	return $result;
}

// ---------------------------------------------------------------------------
// The library ends here. Everything below is the WP-CLI driver.
// ---------------------------------------------------------------------------
//
// The guard sits HERE rather than at the top of the file, which is the whole
// reason wp-admin can reuse this. backfill-blocks.php learned it the hard way,
// backfill-hero.php started where that one ended up, and this file copies it.
//
// At the top, a `! WP_CLI` guard makes the file unusable from anywhere else:
// including it to reach the planner exits the request and blanks the including
// screen — on a must-use plugin, a screen that cannot then be deactivated from
// wp-admin. Deleting the guard instead is worse: the driver below would run on
// include, so merely OPENING the Tools screen would write to 22 pages.
//
// Splitting the difference. Under WP-CLI the condition is false and execution
// falls through to the arguments, so the command line behaves exactly as
// documented. Anywhere else the file defines its functions, announces itself as
// a library, and returns before the driver can do anything at all.
//
// The sentinel is this file's own. The Tools screen greps the SOURCE for it
// before including anything, which is the only way to tell whether a file
// exits on include short of including it and losing the request — and the hero
// engine's sentinel must not vouch for this file.
//
// `return` rather than `exit`, because this file is also run through
// `wp eval-file`, which eval()s it — and there, return simply ends the eval.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	if ( ! defined( 'VS_CLOSING_BACKFILL_LIBRARY' ) ) {
		define( 'VS_CLOSING_BACKFILL_LIBRARY', true );
	}

	return;
}

// ---------------------------------------------------------------------------
// Arguments.
// ---------------------------------------------------------------------------

$dry_run      = ! empty( getenv( 'VS_CLOSING_DRY_RUN' ) );
$force        = ! empty( getenv( 'VS_CLOSING_FORCE' ) );
$only         = [];
$payload_path = (string) getenv( 'VS_CLOSING_PAYLOAD' );

if ( (string) getenv( 'VS_CLOSING_ROUTE' ) !== '' ) {
	$only[] = (string) getenv( 'VS_CLOSING_ROUTE' );
}

foreach ( (array) ( isset( $args ) ? $args : [] ) as $arg ) {
	$arg = ltrim( (string) $arg, '-' );

	if ( 'dry-run' === $arg || 'dry' === $arg ) {
		$dry_run = true;
	} elseif ( 'force' === $arg ) {
		$force = true;
	} elseif ( 0 === strpos( $arg, 'payload=' ) ) {
		$payload_path = substr( $arg, 8 );
	} elseif ( '' !== $arg && '/' === $arg[0] ) {
		$only[] = $arg;
	} elseif ( '' !== $arg ) {
		WP_CLI::error( "Unrecognised argument \"{$arg}\". Expected: dry-run, force, payload=<path>, or /a/route/." );
	}
}

if ( '' === $payload_path ) {
	// Located off WP_CONTENT_DIR like every other importer's payload, because
	// __DIR__ is meaningless inside eval'd code. cms/import is mapped to
	// wp-content/vs-import/bin by cms/.wp-env.json; on the CMS host, upload the
	// payload beside this file or pass payload=<absolute path>.
	$payload_path = WP_CONTENT_DIR . '/vs-import/bin/closing-payload.json';
}

if ( ! file_exists( $payload_path ) ) {
	WP_CLI::error( "closing-payload.json not found at {$payload_path}. Pass payload=<absolute path> if it lives elsewhere." );
}

$payload_raw = (string) file_get_contents( $payload_path );
$payload     = json_decode( $payload_raw, true );

if ( ! is_array( $payload ) || empty( $payload['routes'] ) || ! is_array( $payload['routes'] ) ) {
	WP_CLI::error( "closing-payload.json at {$payload_path} is empty or malformed — it has no `routes` object." );
}

$payload_sha = substr( sha1( $payload_raw ), 0, 12 );
$routes      = (array) $payload['routes'];

if ( $only ) {
	$selected = [];

	foreach ( $only as $route ) {
		$route = '/' === substr( $route, -1 ) ? $route : $route . '/';

		if ( ! isset( $routes[ $route ] ) ) {
			WP_CLI::error(
				sprintf(
					'closing-payload.json has no entry for %s. It knows: %s',
					$route,
					implode( ', ', array_keys( $routes ) )
				)
			);
		}

		$selected[ $route ] = $routes[ $route ];
	}

	$routes = $selected;
}

WP_CLI::log( 'Vivid Smiles — closing copy backfill' );
WP_CLI::log( sprintf( '  payload %s (sha1 %s)', $payload_path, $payload_sha ) );
WP_CLI::log( sprintf( '  routes  %d', count( $routes ) ) );
WP_CLI::log(
	sprintf(
		'  mode    %s',
		$dry_run
			? 'DRY RUN — nothing is written'
			: ( $force ? 'WRITE, FORCED — a closing somebody has typed into will be overwritten' : 'write' )
	)
);
WP_CLI::log( '' );

// ---------------------------------------------------------------------------
// Preflight. The group has to exist before anything else is worth checking — it
// lives in a must-use plugin somebody hand-deploys to the CMS host.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'acf_get_field' ) || ! function_exists( 'update_field' ) ) {
	WP_CLI::error(
		"Secure Custom Fields is not active on this install, so there is no `closing` group to write into.\n"
			. 'Install and activate it (cms/bin/setup.sh does this for the local environment), then re-run.'
	);
}

$closing_field = acf_get_field( vs_cb_group_key() );

if ( ! $closing_field || empty( $closing_field['sub_fields'] ) ) {
	WP_CLI::error(
		"The `closing` group is not registered on this WordPress install, so there is nothing to fill.\n"
			. "\n"
			. "It is defined in cms/mu-plugins/vs-content-model.php and has to be on the HOST before this runs.\n"
			. "Deploy it, confirm it, then re-run:\n"
			. "\n"
			. "  php -l cms/mu-plugins/vs-content-model.php\n"
			. "  bash cms/bin/deploy-mu-plugins.sh vs-content-model.php\n"
			. "\n"
			. "Confirm from outside: `closing` should appear on PageFields in GraphQL."
	);
}

$shape = vs_cb_group_shape( $closing_field );

WP_CLI::log( 'Preflight' );
WP_CLI::log(
	sprintf(
		'  ok    closing group registered — %d sub-fields: %s',
		count( $shape['fields'] ),
		implode( ', ', array_keys( $shape['fields'] ) )
	)
);
WP_CLI::log( sprintf( '  ok    writable by this script: %s', implode( ', ', vs_cb_writable_fields() ) ) );
WP_CLI::log( '' );

// ---------------------------------------------------------------------------
// Plan every route before writing any of them. One fault anywhere stops the
// whole run — a site half in the payload and half in its templates is worse
// than one entirely in its templates, and it is the state nobody has a
// procedure for.
// ---------------------------------------------------------------------------

$plans  = [];
$errors = [];

foreach ( $routes as $route => $entry ) {
	$plan    = vs_cb_plan_route( (string) $route, (array) $entry, $shape );
	$plans[] = $plan;

	foreach ( $plan['errors'] as $error ) {
		$errors[] = sprintf( '%s — %s', $route, $error );
	}
}

foreach ( $plans as $plan ) {
	$counts = vs_cb_counts( $plan );

	WP_CLI::log(
		sprintf(
			'%s (post %d) — %d to write, %d already correct, %d in the way',
			$plan['route'],
			$plan['post_id'],
			$counts['write'],
			$counts['same'],
			$counts['conflict']
		)
	);

	// The name column is wider than the hero's: `consult_headline` is sixteen
	// characters and a ragged column is how a reader misreads which line is which.
	foreach ( $plan['fields'] as $field ) {
		WP_CLI::log(
			sprintf(
				'  %-16s %-8s %s',
				$field['name'],
				$field['action'],
				vs_cb_preview( $field['canonical'] )
			)
		);

		if ( 'conflict' === $field['action'] ) {
			WP_CLI::log( sprintf( '  %-16s %-8s %s', '', 'stored', vs_cb_preview( $field['stored'] ) ) );
		}
	}

	if ( $plan['omitted'] ) {
		WP_CLI::log( sprintf( '  left blank on purpose: %s', implode( ', ', $plan['omitted'] ) ) );
	}

	foreach ( $plan['warnings'] as $warning ) {
		WP_CLI::log( '  check: ' . $warning );
	}

	WP_CLI::log( '' );
}

if ( $errors ) {
	WP_CLI::error(
		sprintf(
			"%d problem(s). Nothing was written.\n\n  %s",
			count( $errors ),
			implode( "\n  ", $errors )
		)
	);
}

// ---------------------------------------------------------------------------
// Write. Through vs_cb_apply_route(), which is the same function the Tools
// screen calls — there is deliberately no second implementation here.
// ---------------------------------------------------------------------------

$tally = [
	'written'   => 0,
	'unchanged' => 0,
	'refused'   => 0,
	'planned'   => 0,
	'failed'    => 0,
];

foreach ( $plans as $plan ) {
	$result = vs_cb_apply_route( $plan, $shape, ! $dry_run, $force, $payload_sha );

	$tally[ $result['outcome'] ]++;

	foreach ( $result['messages'] as $message ) {
		WP_CLI::log( sprintf( '%s — %s', $result['route'], $message ) );
	}
}

WP_CLI::log( '' );
WP_CLI::log( 'Only the closing boxes listed above were written. The hero, the images, the page sections and' );
WP_CLI::log( 'the six source repeaters were not read from and not touched.' );

if ( $tally['failed'] > 0 || $tally['refused'] > 0 ) {
	WP_CLI::error(
		sprintf(
			'%d written, %d already filled, %d REFUSED, %d FAILED — see above.',
			$tally['written'],
			$tally['unchanged'],
			$tally['refused'],
			$tally['failed']
		)
	);
}

WP_CLI::success(
	$dry_run
		? sprintf( 'Dry run clean across %d route(s). Nothing was written.', count( $plans ) )
		: sprintf( '%d written, %d already filled, 0 refused.', $tally['written'], $tally['unchanged'] )
);
