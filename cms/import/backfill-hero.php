<?php
/**
 * Fill each page's `hero` group with the wording that page already renders.
 *
 *   npx wp-env run cli wp eval-file wp-content/vs-import/bin/backfill-hero.php dry-run
 *   npx wp-env run cli wp eval-file wp-content/vs-import/bin/backfill-hero.php
 *   npx wp-env run cli wp eval-file wp-content/vs-import/bin/backfill-hero.php /our-office/
 *
 * Commit 68d80ac made the hero editable on 25 routes: every template now prefers
 * `hero.eyebrow` / `hero.h1` / `hero.sub` / `hero.ctas` from WordPress and falls
 * back to its own literal when they are blank. They are all blank. The feature
 * works and the owner cannot use it — opening the Hero tab shows empty boxes, so
 * changing one word of his own headline means retyping the whole thing.
 *
 * This script types them in for him, from cms/import/hero-payload.json, which
 * holds the exact characters each page renders today. Because the templates fall
 * back to those same literals, a correct run changes no rendered byte. That is
 * not a hope, it is the acceptance test the payload was built against.
 *
 * WHAT IT TOUCHES, exhaustively: at most five sub-fields of one group on one
 * page — eyebrow, h1, sub, ctas, ratings. Nothing else. Not the hero image, not
 * its alt text, not the photo treatment, not `blocks`, not the six source
 * repeaters, not the post itself, not SEO. vs_hb_writable_fields() is a closed
 * list and a payload key outside it is a hard error, so a hand-edited payload
 * cannot talk this into clearing an editor's hero photo.
 *
 * FIELD ABSENT MEANS DELIBERATELY BLANK, and that is load-bearing rather than
 * lazy. The templates keep their literal exactly while a box is empty, so for
 * the values the payload cannot represent honestly — a `sub` containing a real
 * <a> or <b>, a CTA whose href is site data — blank IS the correct stored value
 * and anything else would publish escaped tags or a broken link. An omitted
 * field is therefore never written, never cleared and never defaulted.
 *
 * HOW THAT IS ENFORCED, since it is the requirement most easily broken by a
 * plausible-looking change. The write is ONE update_field() call against the
 * GROUP, by field key, carrying only the sub-fields being filled:
 *
 *     update_field( 'field_vs_page_hero', [ 'h1' => '…', 'ratings' => 1 ], $id );
 *
 * ACF's group type loops its REGISTERED sub-fields and, for each one, looks for
 * the value under the sub-field's key and then under its name. Finding neither
 * it does `continue` — it does not write, and it does not delete. So omission is
 * enforced by ACF itself rather than by care on our part, which is the only kind
 * of enforcement worth relying on. (includes/fields/class-acf-field-group.php,
 * update_value(); the same loop prefixes each sub-field's name with the group's,
 * which is how `h1` becomes the `hero_h1` meta key.)
 *
 * TWO TRAPS IN THAT SENTENCE, both of which look like improvements:
 *
 *   1. DO NOT write a sub-field by its own key. `update_field(
 *      'field_vs_page_hero_h1', $v, $id )` is the obvious refactor and it is
 *      wrong: update_field() resolves that key to a field whose `name` is still
 *      the bare `h1`, because the group prefix is applied at write time by the
 *      PARENT and nothing else. The value lands in postmeta `h1` / `_h1` — a
 *      pair of orphans invisible to wp-admin and to WPGraphQL — and the hero is
 *      left exactly as empty as it was, with every return value saying success.
 *
 *   2. DO NOT call update_field() on the group with an EMPTY array. ACF guards
 *      its group update with acf_is_array(), which is `is_array() && ! empty()`,
 *      so [] fails it, update_value() returns null, and acf_update_value() reads
 *      null as "delete" and calls the group's delete_value() — which loops every
 *      sub-field and deletes it. An empty write request DELETES THE WHOLE HERO,
 *      including the image and the alt text this script otherwise refuses to
 *      touch. vs_hb_apply_route() therefore returns before writing when there is
 *      nothing to write, and still checks the array is non-empty immediately
 *      before the call. Both, on purpose.
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
 * IT REFUSES A HERO SOMEBODY HAS ALREADY TYPED IN. If any field the payload
 * carries holds a different non-empty value, the whole route is refused and
 * NOTHING is written for it — not even the fields that would have been clean.
 * Half a hero is a state nobody has a procedure for. `force` overrides, and the
 * dry run prints both values first so the decision is made on evidence.
 *
 * THE RATINGS TRAP, which is why a field that is not copy is in a copy payload.
 * The review line renders whenever `showRatings` is true, and that is
 * `! heroOn || h.ratings` (src/lib/page-content.ts:339). While the hero is blank
 * heroOn is false and the line draws unconditionally — 22 of these routes show
 * it today. The moment `h1` is filled heroOn flips true and the line becomes the
 * stored boolean, which is unset, which is false. Filling headlines alone would
 * silently strip the review line from 22 pages. So: a payload entry carrying
 * `h1` and not carrying `ratings` is a hard error here, with the fix named. Say
 * `"ratings": false` if that is genuinely wanted; say nothing and it stops.
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
 *   force            overwrite a hero somebody has already typed into
 *   /a/route/        limit the run to one route; repeatable. Default: every
 *                    route in the payload
 *   payload=<path>   an absolute path to a different hero-payload.json
 *
 * Environment equivalents, for hosts where arguments are awkward to pass:
 * VS_HERO_DRY_RUN, VS_HERO_FORCE, VS_HERO_ROUTE, VS_HERO_PAYLOAD.
 *
 * NO SHELL ON THE CMS HOST. GoDaddy Managed WordPress has no SSH and therefore
 * no WP-CLI, so in practice this runs from Tools → Page content migration,
 * which includes this file as a library and calls the same three functions the
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
 * Deliberately a second copy of vs_bb_page_by_route() rather than a call to it.
 * This file has to stand alone — the Tools screen includes whichever engine the
 * chosen mode needs and not both, and a cross-file dependency would make the
 * hero mode fail whenever backfill-blocks.php happened to be absent.
 */
function vs_hb_page_by_route( $route ) {
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

/** The ACF key of the hero group. Written by KEY; see the docblock. */
function vs_hb_group_key() {
	return 'field_vs_page_hero';
}

/** The group's field name, and therefore the prefix on every sub-field's meta key. */
function vs_hb_group_name() {
	return 'hero';
}

/**
 * The bookkeeping meta a successful run leaves behind.
 *
 * Exposed as a function so the Tools screen reads the name from here rather than
 * declaring its own copy. Two spellings of one meta key is how two runners stop
 * recognising each other's work.
 */
function vs_hb_receipt_meta() {
	return '_vs_hero_backfill';
}

/**
 * The only sub-fields this script may ever write.
 *
 * A closed list, checked against the payload before anything else happens. The
 * hero group also holds `image`, `image_alt` and `media_shape`; those are media
 * decisions rather than copy, nothing in the payload describes them, and a tool
 * that could reach them is a tool that can clear a photo by accident. Naming one
 * in the payload is an error rather than a silent skip, because a silent skip is
 * how somebody spends an afternoon wondering why their key did nothing.
 */
function vs_hb_writable_fields() {
	return [ 'eyebrow', 'h1', 'sub', 'ctas', 'ratings' ];
}

/**
 * The hero group as this install actually registers it: sub-field name => type,
 * with any nested repeater's row fields and row cap alongside.
 *
 * Read off the live registration rather than the source, for the reason
 * backfill-blocks.php gives about layouts and the reason applies harder here:
 * ACF's group loop iterates the fields it KNOWS, so a payload key naming a
 * sub-field this install does not have is not dropped with a complaint — it is
 * never looked at. Nothing would be written and nothing would be said. Checked
 * up front instead, against this.
 */
function vs_hb_group_shape( $field ) {
	$shape = [
		'fields'    => [],
		'repeaters' => [],
	];

	foreach ( (array) ( $field['sub_fields'] ?? [] ) as $sub ) {
		$name = (string) ( $sub['name'] ?? '' );

		if ( '' === $name ) {
			continue;
		}

		$type                     = (string) ( $sub['type'] ?? '' );
		$shape['fields'][ $name ] = $type;

		if ( 'repeater' === $type ) {
			$rows = [];

			foreach ( (array) ( $sub['sub_fields'] ?? [] ) as $row_sub ) {
				if ( '' !== (string) ( $row_sub['name'] ?? '' ) ) {
					$rows[] = (string) $row_sub['name'];
				}
			}

			$shape['repeaters'][ $name ] = [
				'fields' => $rows,
				'max'    => (int) ( $sub['max'] ?? 0 ),
			];
		}
	}

	return $shape;
}

/**
 * What one hero sub-field holds RIGHT NOW, as a single comparable string.
 *
 * '' means empty — never written, or written and cleared. Every non-empty value
 * this script can produce is a non-empty string (a true_false is '1' or '0', a
 * repeater is a JSON array of at least one row), so '' is unambiguous.
 *
 * Read from postmeta rather than through get_field(), the way vs-migrate.php
 * reads `blocks`: this is asking what bytes are in the database, and ACF's
 * formatting layer is a filter between the question and the answer. It also
 * makes the read-back check after a write mean what it says.
 */
function vs_hb_stored( $post_id, $name, $shape ) {
	$prefix = vs_hb_group_name() . '_' . $name;

	if ( isset( $shape['repeaters'][ $name ] ) ) {
		$count = (int) get_post_meta( $post_id, $prefix, true );

		if ( $count < 1 ) {
			return '';
		}

		$rows = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$row = [];

			foreach ( $shape['repeaters'][ $name ]['fields'] as $sub ) {
				$row[ $sub ] = (string) get_post_meta( $post_id, sprintf( '%s_%d_%s', $prefix, $i, $sub ), true );
			}

			$rows[] = $row;
		}

		return (string) wp_json_encode( $rows );
	}

	return (string) get_post_meta( $post_id, $prefix, true );
}

/**
 * A payload value as [ what to hand update_field(), the same thing as a
 * comparable string ].
 *
 * The two have to agree or the read-back check is theatre, so they are built
 * here together and never separately.
 *
 * Repeater rows are keyed by sub-field NAME, which is the convention every
 * working importer in this repo uses — import-sections.php and
 * import-images.php both write rows that way and both round-trip. The row array
 * is rebuilt in the registration's own field order so its JSON compares equal to
 * the JSON vs_hb_stored() builds from postmeta.
 */
function vs_hb_intended( $name, $value, $shape ) {
	if ( isset( $shape['repeaters'][ $name ] ) ) {
		$rows = [];

		foreach ( (array) $value as $row ) {
			$out = [];

			foreach ( $shape['repeaters'][ $name ]['fields'] as $sub ) {
				$out[ $sub ] = (string) ( is_array( $row ) && isset( $row[ $sub ] ) ? $row[ $sub ] : '' );
			}

			$rows[] = $out;
		}

		return [ $rows, (string) wp_json_encode( $rows ) ];
	}

	if ( 'true_false' === (string) ( $shape['fields'][ $name ] ?? '' ) ) {
		$on = (bool) $value;

		return [ $on ? 1 : 0, $on ? '1' : '0' ];
	}

	return [ (string) $value, (string) $value ];
}

/**
 * One value as a short line, with its edges marked.
 *
 * The markers are not decoration. These values are compared byte for byte and
 * several of them legitimately contain newlines and runs of indentation the
 * built page reproduces; a trailing space is the difference between a write and
 * a refusal, and it is invisible without something either side of it.
 */
function vs_hb_preview( $text, $limit = 160 ) {
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
function vs_hb_plan_route( $route, $entry, $shape ) {
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

	$post_id = vs_hb_page_by_route( $route );

	if ( ! $post_id ) {
		$plan['errors'][] = "no WordPress page has _vs_route = {$route} — run: cd cms && npm run import:pages";

		return $plan;
	}

	$plan['post_id'] = $post_id;

	$writable = vs_hb_writable_fields();

	// Every key the payload names, before any of them is acted on. A key this
	// script will not write, or one this install does not register, is a fault in
	// the payload and not a thing to route around: ACF's group loop would never
	// look at it, so the run would report success over a field that was never
	// written.
	foreach ( array_keys( $entry ) as $name ) {
		$name = (string) $name;

		if ( ! in_array( $name, $writable, true ) ) {
			$plan['errors'][] = sprintf(
				'`%s` is not a field this script writes. It writes exactly: %s. Anything else on the hero '
					. '— the photo, its alt text, the photo treatment — is out of scope on purpose.',
				$name,
				implode( ', ', $writable )
			);
			continue;
		}

		if ( ! isset( $shape['fields'][ $name ] ) ) {
			$plan['errors'][] = sprintf(
				'`%s` is not registered on the hero group on this install. It is declared in '
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
		$faults = vs_hb_validate_value( $name, $value, $type, $shape );

		if ( $faults ) {
			$plan['errors'] = array_merge( $plan['errors'], $faults );
			continue;
		}

		list( $write_value, $canonical ) = vs_hb_intended( $name, $value, $shape );

		$stored = vs_hb_stored( $post_id, $name, $shape );

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

	// THE REVIEW-LINE RULE. Filling `h1` is what flips heroOn, and at that moment
	// showRatings stops being unconditional and becomes the stored boolean —
	// unset, which reads as false. 22 of these pages draw a review line today and
	// would quietly stop. So a payload that fills a headline has to say, in
	// writing, what the review line should do. `false` is an acceptable answer;
	// silence is not.
	if ( isset( $carried['h1'] ) && ! isset( $carried['ratings'] ) ) {
		$plan['errors'][] = 'carries `h1` but not `ratings`. Filling the headline turns the hero on, and from '
			. 'then on the review line follows the stored `ratings` switch instead of drawing '
			. 'unconditionally — so an unset switch removes it. Add "ratings": true to this route in the '
			. 'payload (or "ratings": false if it genuinely should go).';
	}

	// A hero that cannot render. The templates read eyebrow, sub and the buttons
	// only while heroOn is true, and heroOn is `h1 !== ""`. Writing a sub onto a
	// page with no headline stores a value nothing will ever draw, which looks
	// like success and is not.
	$needs_h1 = array_values(
		array_filter(
			[ 'eyebrow', 'sub', 'ctas' ],
			static function ( $name ) use ( $carried ) {
				return isset( $carried[ $name ] );
			}
		)
	);

	if ( $needs_h1 ) {
		$h1_after_run = isset( $carried['h1'] )
			? (string) $carried['h1']['canonical']
			: vs_hb_stored( $post_id, 'h1', $shape );

		if ( '' === $h1_after_run ) {
			$plan['errors'][] = sprintf(
				'carries %s, but this page would still have no `h1` afterwards. The hero only renders when '
					. 'the headline is filled (page-content.ts: heroOn = h1 !== ""), so those values would be '
					. 'stored and never drawn. Add the headline, or drop these.',
				implode( ' and ', array_map(
					static function ( $name ) {
						return '`' . $name . '`';
					},
					$needs_h1
				) )
			);
		}
	}

	// Not an error, and not silent either. wp-admin runs a logged-in user's
	// textarea save through wp_kses unless they hold unfiltered_html, and a
	// stripped class would take the accent styling off the headline. Nothing this
	// script does causes that — update_field() does not filter — but the first
	// time the owner opens this page and presses Update, it is the save path the
	// value goes through, and that is worth knowing BEFORE 24 pages are filled.
	if ( isset( $carried['h1'] ) && false !== strpos( (string) $carried['h1']['canonical'], 'class=' ) ) {
		$plan['warnings'][] = 'the headline carries a class attribute (<em class="vs-italic-word">). Check that '
			. 'saving this page in wp-admin afterwards leaves it intact — if the editor strips it, this '
			. 'headline should be left to its template literal instead.';
	}

	return $plan;
}

/**
 * Everything that is wrong with one payload value, as plain sentences.
 *
 * Strict on purpose. Each of these has a specific way of going wrong quietly,
 * and the alternative to refusing here is finding out from the live site.
 */
function vs_hb_validate_value( $name, $value, $type, $shape ) {
	$faults = [];

	if ( isset( $shape['repeaters'][ $name ] ) ) {
		if ( ! is_array( $value ) || ! $value ) {
			return [ sprintf( '`%s` must be a non-empty list of rows.', $name ) ];
		}

		$max = (int) $shape['repeaters'][ $name ]['max'];

		if ( $max > 0 && count( $value ) > $max ) {
			$faults[] = sprintf(
				'`%s` has %d rows and the field is capped at %d. The cap is a measurement of the design, '
					. 'not a guess — there is no third hero button. ACF does not enforce a max on a '
					. 'programmatic write, so the extra row would be stored and then dropped on read.',
				$name,
				count( $value ),
				$max
			);
		}

		foreach ( $value as $index => $row ) {
			$where = sprintf( '`%s` row %d', $name, (int) $index + 1 );

			if ( ! is_array( $row ) ) {
				$faults[] = $where . ' is not an object.';
				continue;
			}

			foreach ( array_keys( $row ) as $key ) {
				if ( ! in_array( (string) $key, $shape['repeaters'][ $name ]['fields'], true ) ) {
					$faults[] = sprintf(
						'%s names `%s`, which the row does not have. It has: %s.',
						$where,
						(string) $key,
						implode( ', ', $shape['repeaters'][ $name ]['fields'] )
					);
				}
			}

			foreach ( $shape['repeaters'][ $name ]['fields'] as $sub ) {
				$cell = isset( $row[ $sub ] ) ? $row[ $sub ] : null;

				if ( ! is_string( $cell ) || '' === trim( $cell ) ) {
					// The loader drops any CTA row missing either half
					// (pages.ts:646) and the templates then read the survivors
					// POSITIONALLY, so a half-filled row does not degrade — it
					// shifts the next button into the solid slot.
					$faults[] = sprintf( '%s needs a non-empty `%s`.', $where, $sub );
					continue;
				}

				$faults = array_merge( $faults, vs_hb_check_string( $where . ' ' . $sub, $cell ) );
			}

			// Site data, which src/blocks/cta.ts keeps out of stored content: a
			// booking URL or a phone number stored in a page's copy is a second
			// copy of a fact that lives in settings, and it goes stale silently.
			$href = isset( $row['href'] ) && is_string( $row['href'] ) ? trim( $row['href'] ) : '';

			if ( '' !== $href && preg_match( '/^(tel:|mailto:)/i', $href ) ) {
				$faults[] = sprintf(
					'%s points at %s. A phone number or address is site data and does not belong in a '
						. 'page\'s stored copy — leave the whole `ctas` list out for this route and let the '
						. 'template keep its own buttons.',
					$where,
					$href
				);
			}
		}

		return $faults;
	}

	if ( 'true_false' === $type ) {
		if ( ! is_bool( $value ) ) {
			$faults[] = sprintf( '`%s` must be true or false, not %s.', $name, gettype( $value ) );
		}

		return $faults;
	}

	if ( ! is_string( $value ) ) {
		return [ sprintf( '`%s` must be a string, not %s.', $name, gettype( $value ) ) ];
	}

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

	return array_merge( $faults, vs_hb_check_string( '`' . $name . '`', $value ) );
}

/**
 * The one character that does not survive the trip into postmeta.
 *
 * update_metadata() calls wp_unslash() on the value it is handed, so a lone
 * backslash is stripped between here and the database and the stored value stops
 * matching the payload — by one invisible character, on a page whose whole
 * acceptance test is byte equality. wp_slash() before the write would round-trip
 * it correctly; no importer in this repo does that, and today's payload contains
 * no backslash at all, so the write path is left identical to its siblings and
 * the case is reported rather than guessed at.
 */
function vs_hb_check_string( $where, $value ) {
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
function vs_hb_counts( $plan ) {
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
 * Act on one plan. THE ONLY FUNCTION IN THIS PROJECT THAT WRITES A HERO.
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
function vs_hb_apply_route( $plan, $shape, $write, $force, $payload_sha = '' ) {
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

	// ALL OR NOTHING PER ROUTE. A conflict on one field stops the whole hero,
	// including the fields that were clean. A hero half from the payload and half
	// from an editor is a state with no procedure and no way to tell by looking
	// which half came from where.
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
				vs_hb_preview( $field['stored'] )
			);
			$result['messages'][] = sprintf(
				'  %s — would write %s',
				str_repeat( ' ', strlen( (string) $field['name'] ) ),
				vs_hb_preview( $field['canonical'] )
			);
		}

		$result['messages'][] = 'Either somebody has typed into this hero, or the payload has changed since '
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
	// over EVERY sub-field — the photo and the alt text included. The branch above
	// already returns when there is nothing to write; this is the second lock.
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
	// mention, so `image`, `image_alt`, `media_shape` and any field the payload
	// left out come through untouched. That is the omission contract, and it is
	// enforced by ACF rather than by this line being careful.
	update_field( vs_hb_group_key(), $values, $post_id );

	// Read back rather than trust the return value, exactly as vs-migrate.php
	// does. update_field() on a group returns the parent's own meta write and says
	// nothing useful about the sub-fields; the receipt below is what a later
	// reader treats as proof, and stamping it over a write that did not land is
	// how a page ends up permanently recorded as done while holding nothing.
	$mismatched = [];

	foreach ( $todo as $field ) {
		$now = vs_hb_stored( $post_id, (string) $field['name'], $shape );

		if ( $now !== (string) $field['canonical'] ) {
			$mismatched[] = sprintf(
				'  %s — sent %s, stored %s',
				$field['name'],
				vs_hb_preview( $field['canonical'] ),
				vs_hb_preview( $now )
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
		$recorded[ (string) $field['name'] ] = vs_hb_stored( $post_id, (string) $field['name'], $shape );
	}

	update_post_meta(
		$post_id,
		vs_hb_receipt_meta(),
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

	$result['messages'][] = 'Nothing else on this page was touched: not the hero photo or its alt text, not '
		. 'the page sections, not the six source repeaters, not the SEO fields.';
	$result['messages'][] = 'To undo: open the page, Page content, Hero, and empty the boxes. The template '
		. 'renders its own wording again on the next build, with no deploy and no code change.';

	// update_field() fires neither transition_post_status nor acf/save_post, so
	// vs-deploy.php never hears about this and the front end keeps serving the
	// pre-write page indefinitely. Telling the operator to open each page and
	// press Update is a step nobody remembers on page nineteen of twenty-four.
	if ( function_exists( 'VividSmiles\\Deploy\\queue' ) ) {
		call_user_func( 'VividSmiles\\Deploy\\queue', sprintf( 'hero copy back-filled for %s', $plan['route'] ) );
		$result['messages'][] = 'A front-end rebuild has been queued; the live site picks this up in a few '
			. 'minutes. It should look identical — that is the point.';
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
// reason wp-admin can reuse this. backfill-blocks.php learned it the hard way
// and this file starts where that one ended up.
//
// At the top, a `! WP_CLI` guard makes the file unusable from anywhere else:
// including it to reach the planner exits the request and blanks the including
// screen — on a must-use plugin, a screen that cannot then be deactivated from
// wp-admin. Deleting the guard instead is worse: the driver below would run on
// include, so merely OPENING the Tools screen would write to 24 pages.
//
// Splitting the difference. Under WP-CLI the condition is false and execution
// falls through to the arguments, so the command line behaves exactly as
// documented. Anywhere else the file defines its functions, announces itself as
// a library, and returns before the driver can do anything at all.
//
// `return` rather than `exit`, because this file is also run through
// `wp eval-file`, which eval()s it — and there, return simply ends the eval.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	if ( ! defined( 'VS_HERO_BACKFILL_LIBRARY' ) ) {
		define( 'VS_HERO_BACKFILL_LIBRARY', true );
	}

	return;
}

// ---------------------------------------------------------------------------
// Arguments.
// ---------------------------------------------------------------------------

$dry_run      = ! empty( getenv( 'VS_HERO_DRY_RUN' ) );
$force        = ! empty( getenv( 'VS_HERO_FORCE' ) );
$only         = [];
$payload_path = (string) getenv( 'VS_HERO_PAYLOAD' );

if ( (string) getenv( 'VS_HERO_ROUTE' ) !== '' ) {
	$only[] = (string) getenv( 'VS_HERO_ROUTE' );
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
	$payload_path = WP_CONTENT_DIR . '/vs-import/bin/hero-payload.json';
}

if ( ! file_exists( $payload_path ) ) {
	WP_CLI::error( "hero-payload.json not found at {$payload_path}. Pass payload=<absolute path> if it lives elsewhere." );
}

$payload_raw = (string) file_get_contents( $payload_path );
$payload     = json_decode( $payload_raw, true );

if ( ! is_array( $payload ) || empty( $payload['routes'] ) || ! is_array( $payload['routes'] ) ) {
	WP_CLI::error( "hero-payload.json at {$payload_path} is empty or malformed — it has no `routes` object." );
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
					'hero-payload.json has no entry for %s. It knows: %s',
					$route,
					implode( ', ', array_keys( $routes ) )
				)
			);
		}

		$selected[ $route ] = $routes[ $route ];
	}

	$routes = $selected;
}

WP_CLI::log( 'Vivid Smiles — hero copy backfill' );
WP_CLI::log( sprintf( '  payload %s (sha1 %s)', $payload_path, $payload_sha ) );
WP_CLI::log( sprintf( '  routes  %d', count( $routes ) ) );
WP_CLI::log(
	sprintf(
		'  mode    %s',
		$dry_run
			? 'DRY RUN — nothing is written'
			: ( $force ? 'WRITE, FORCED — a hero somebody has typed into will be overwritten' : 'write' )
	)
);
WP_CLI::log( '' );

// ---------------------------------------------------------------------------
// Preflight. The group has to exist before anything else is worth checking — it
// lives in a must-use plugin somebody hand-deploys to the CMS host.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'acf_get_field' ) || ! function_exists( 'update_field' ) ) {
	WP_CLI::error(
		"Secure Custom Fields is not active on this install, so there is no `hero` group to write into.\n"
			. 'Install and activate it (cms/bin/setup.sh does this for the local environment), then re-run.'
	);
}

$hero_field = acf_get_field( vs_hb_group_key() );

if ( ! $hero_field || empty( $hero_field['sub_fields'] ) ) {
	WP_CLI::error(
		"The `hero` group is not registered on this WordPress install, so there is nothing to fill.\n"
			. "\n"
			. "It is defined in cms/mu-plugins/vs-content-model.php and has to be on the HOST before this runs.\n"
			. "Deploy it, confirm it, then re-run:\n"
			. "\n"
			. "  php -l cms/mu-plugins/vs-content-model.php\n"
			. "  bash cms/bin/deploy-mu-plugins.sh vs-content-model.php\n"
			. "\n"
			. "Confirm from outside: `hero` should appear on PageFields in GraphQL."
	);
}

$shape = vs_hb_group_shape( $hero_field );

WP_CLI::log( 'Preflight' );
WP_CLI::log(
	sprintf(
		'  ok    hero group registered — %d sub-fields: %s',
		count( $shape['fields'] ),
		implode( ', ', array_keys( $shape['fields'] ) )
	)
);
WP_CLI::log( sprintf( '  ok    writable by this script: %s', implode( ', ', vs_hb_writable_fields() ) ) );
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
	$plan    = vs_hb_plan_route( (string) $route, (array) $entry, $shape );
	$plans[] = $plan;

	foreach ( $plan['errors'] as $error ) {
		$errors[] = sprintf( '%s — %s', $route, $error );
	}
}

foreach ( $plans as $plan ) {
	$counts = vs_hb_counts( $plan );

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

	foreach ( $plan['fields'] as $field ) {
		WP_CLI::log(
			sprintf(
				'  %-8s %-8s %s',
				$field['name'],
				$field['action'],
				vs_hb_preview( $field['canonical'] )
			)
		);

		if ( 'conflict' === $field['action'] ) {
			WP_CLI::log( sprintf( '  %-8s %-8s %s', '', 'stored', vs_hb_preview( $field['stored'] ) ) );
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
// Write. Through vs_hb_apply_route(), which is the same function the Tools
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
	$result = vs_hb_apply_route( $plan, $shape, ! $dry_run, $force, $payload_sha );

	$tally[ $result['outcome'] ]++;

	foreach ( $result['messages'] as $message ) {
		WP_CLI::log( sprintf( '%s — %s', $result['route'], $message ) );
	}
}

WP_CLI::log( '' );
WP_CLI::log( 'Only the hero sub-fields listed above were written. The hero photo, its alt text, the photo' );
WP_CLI::log( 'treatment, the page sections and the six source repeaters were not read from and not touched.' );

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
