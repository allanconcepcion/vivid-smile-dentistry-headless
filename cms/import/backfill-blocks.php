<?php
/**
 * Turn a page's existing repeater rows into an ordered `blocks` list.
 *
 *   npx wp-env run cli wp eval-file wp-content/vs-import/bin/backfill-blocks.php dry-run
 *   npx wp-env run cli wp eval-file wp-content/vs-import/bin/backfill-blocks.php
 *   npx wp-env run cli wp eval-file wp-content/vs-import/bin/backfill-blocks.php /cosmetic-dentistry/clear-aligners/
 *
 * Reads cms/import/block-map.json — which route, which section_id, which layout,
 * which rows — and writes ONE field: `blocks`. Everything it knows about a page
 * is in that file, so extending this to the next ten routes is a JSON change and
 * not a PHP change. docs/PAGE-BLOCKS.md 1.4.
 *
 * WHAT IT DOES NOT TOUCH, and why that is the whole safety story:
 *
 *   The six repeaters it reads from — sections, cards, images, faqs,
 *   process_steps, toc_links — are left exactly as they are. They are the
 *   source, and a migration that consumed its own source could not be undone.
 *   The rollback for a migrated page is "empty `blocks` in wp-admin" (2.3), no
 *   deploy and no code change, and that only works while the repeaters the
 *   template falls back to are still sitting there intact.
 *
 * IDEMPOTENT, in the way that matters here. `blocks` is written with one
 * update_field() call, a wholesale replace, so a write cannot append or
 * duplicate. And the second run does not write at all: it finds rows already
 * there and stops. Running this twice leaves what the first run left.
 *
 * IT REFUSES TO OVERWRITE A NON-EMPTY `blocks`. Pass `force` to make it, and
 * think first. Everything else here is reversible; an editor's arrangement is
 * the one thing that is not, because nothing anywhere records what the order
 * used to be. When the rows it finds are its own — same map, same page, same
 * hash — it says so and still writes nothing, which is the same outcome by a
 * quieter route.
 *
 * IT FAILS BEFORE WRITING ANYTHING if a cards group, an images slot, a
 * toc_links anchor or a sections row is left over and the map does not name it
 * in `exempt`. Nothing is dropped silently. Planning happens for every route
 * first and a single leftover anywhere aborts the run, so a multi-route pass
 * cannot half-migrate the site.
 *
 * ---------------------------------------------------------------------------
 * IT CANNOT BE RUN YET. `blocks` lives in cms/mu-plugins/vs-content-model.php,
 * which is not on the CMS host — verified: `blocks` is still absent from the
 * live GraphQL schema. The first thing this script does is look for the field
 * and, if it is missing, say what to deploy. Nothing else runs until it is
 * there.
 * ---------------------------------------------------------------------------
 *
 * The importers cannot clobber a page this has touched: none of them writes
 * `blocks`. Re-running `import:sections` on a migrated page rewrites repeaters
 * that page no longer reads — harmless, and stated here so nobody "fixes" it.
 * See import-sections.php:10 for the wholesale-replace contract that makes it
 * harmless. Risk R8, docs/PAGE-BLOCKS.md 6.
 *
 * ARGUMENTS. `wp eval-file` hands positional arguments through in $args, but
 * eats anything beginning with a dash as one of its own global flags — so the
 * words are bare:
 *
 *   dry-run          plan and report, write nothing
 *   force            overwrite a non-empty `blocks` (see above)
 *   /a/route/        limit the run to one route; repeatable. Default: every
 *                    route in the map
 *   map=<path>       an absolute path to a different block-map.json
 *
 * Equivalents exist as environment variables for hosts where passing arguments
 * through is awkward: VS_BACKFILL_DRY_RUN, VS_BACKFILL_FORCE, VS_BACKFILL_ROUTE,
 * VS_BACKFILL_MAP.
 */

// No `declare(strict_types=1)` / `namespace` — see import-reviews.php for why.
// Same reason there is no top-level `const` and no `__DIR__` below: `wp
// eval-file` runs this through eval(), where a const declaration is a parse
// error and __DIR__ resolves to WP-CLI's own source directory, not this file's.
// The map is located the way every other importer locates its payload, off
// WP_CONTENT_DIR.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Run through WP-CLI.\n" );
	exit( 1 );
}

/**
 * The page whose _vs_route meta matches, or 0.
 *
 * Matched on the full route rather than the slug, for the reason
 * import-pages.php gives: slugs repeat across branches.
 */
function vs_bb_page_by_route( $route ) {
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

/**
 * Every layout registered on `blocks`, as name => sub-field names, with the
 * sub-fields of any nested repeater alongside.
 *
 * This is what turns "the map named a field that does not exist" from a silent
 * data loss into a message. ACF drops an unrecognised key in an update_field()
 * row without complaint, so a layout whose `pull` was renamed to `aside` would
 * back-fill a page with the aside missing and nothing said about it. Checked up
 * front, against the registration, for every field the map mentions.
 */
function vs_bb_layout_shapes( $field ) {
	$shapes = [];

	foreach ( (array) ( $field['layouts'] ?? [] ) as $layout ) {
		$name = (string) ( $layout['name'] ?? '' );

		if ( '' === $name ) {
			continue;
		}

		$shape = [
			'fields'    => [],
			'repeaters' => [],
		];

		foreach ( (array) ( $layout['sub_fields'] ?? [] ) as $sub ) {
			$sub_name = (string) ( $sub['name'] ?? '' );

			if ( '' === $sub_name ) {
				continue;
			}

			$shape['fields'][ $sub_name ] = (string) ( $sub['type'] ?? '' );

			if ( 'repeater' === ( $sub['type'] ?? '' ) ) {
				$rows = [];
				foreach ( (array) ( $sub['sub_fields'] ?? [] ) as $row_sub ) {
					if ( '' !== (string) ( $row_sub['name'] ?? '' ) ) {
						$rows[] = (string) $row_sub['name'];
					}
				}
				$shape['repeaters'][ $sub_name ] = $rows;
			}
		}

		$shapes[ $name ] = $shape;
	}

	return $shapes;
}

/**
 * Read a page's six source repeaters into the shapes the planner wants.
 *
 * Formatted values, not raw: the images repeater declares
 * `return_format => 'array'`, so the formatted read is the one that yields an
 * attachment ID without re-deriving it from postmeta.
 */
function vs_bb_read_sources( $post_id ) {
	$sources = [
		'sections'      => [],
		'cards'         => [],
		'images'        => [],
		'toc_links'     => [],
		'process_steps' => [],
		'faqs'          => [],
	];

	// get_field() answers `false` for an empty repeater, and (array) false is
	// [ false ] — a one-row array. Left uncast that way an empty process_steps
	// would count as one step and a page with none would migrate carrying a
	// blank one.
	$rows_of = static function ( $name ) use ( $post_id ) {
		$value = get_field( $name, $post_id );

		return is_array( $value ) ? $value : [];
	};

	foreach ( $rows_of( 'sections' ) as $row ) {
		$id = (string) ( $row['section_id'] ?? '' );
		if ( '' !== $id ) {
			$sources['sections'][ $id ] = $row;
		}
	}

	foreach ( $rows_of( 'cards' ) as $row ) {
		$group = (string) ( $row['group'] ?? '' );
		if ( '' !== $group ) {
			$sources['cards'][ $group ][] = $row;
		}
	}

	foreach ( $rows_of( 'images' ) as $row ) {
		$slot = (string) ( $row['slot'] ?? '' );
		if ( '' !== $slot ) {
			$sources['images'][ $slot ] = $row;
		}
	}

	foreach ( $rows_of( 'toc_links' ) as $row ) {
		$anchor = ltrim( (string) ( $row['anchor'] ?? '' ), '#' );
		if ( '' !== $anchor ) {
			$sources['toc_links'][ $anchor ] = (string) ( $row['label'] ?? '' );
		}
	}

	$sources['process_steps'] = array_values( $rows_of( 'process_steps' ) );
	$sources['faqs']          = array_values( $rows_of( 'faqs' ) );

	return $sources;
}

/**
 * The attachment ID out of an images-repeater row, whatever shape it came back in.
 *
 * `return_format => 'array'` gives an array with an ID; a row saved before that
 * setting, or read through a filter that changed it, can be a bare id or a URL.
 * Returns 0 for anything else, which the caller reports rather than writing.
 */
function vs_bb_attachment_id( $value ) {
	if ( is_array( $value ) ) {
		return (int) ( $value['ID'] ?? $value['id'] ?? 0 );
	}

	if ( is_numeric( $value ) ) {
		return (int) $value;
	}

	return 0;
}

/**
 * Resolve one `fields` entry of the map against a page's rows.
 *
 * Returns [ value, error ]. An unknown source kind is an error, never a guess:
 * a typo in the map that resolved to an empty string would back-fill a page
 * with a heading missing and nothing said about it.
 */
function vs_bb_resolve_field( $spec, $section, $sources, $where ) {
	if ( ! is_array( $spec ) ) {
		return [ null, "{$where}: expected an object like { \"literal\": … }, got " . gettype( $spec ) ];
	}

	if ( array_key_exists( 'literal', $spec ) ) {
		return [ $spec['literal'], null ];
	}

	if ( array_key_exists( 'section', $spec ) ) {
		$name = (string) $spec['section'];

		if ( ! is_array( $section ) || ! array_key_exists( $name, $section ) ) {
			return [ null, "{$where}: the sections row has no sub-field \"{$name}\"" ];
		}

		return [ (string) $section[ $name ], null ];
	}

	if ( array_key_exists( 'image', $spec ) ) {
		$slot = (string) $spec['image'];
		$part = (string) ( $spec['part'] ?? 'id' );

		if ( ! isset( $sources['images'][ $slot ] ) ) {
			return [ null, "{$where}: no images row with slot \"{$slot}\"" ];
		}

		$row = $sources['images'][ $slot ];

		if ( 'alt' === $part ) {
			return [ (string) ( $row['alt'] ?? '' ), null ];
		}

		$id = vs_bb_attachment_id( $row['image'] ?? null );

		if ( $id <= 0 ) {
			return [ null, "{$where}: images slot \"{$slot}\" holds no attachment" ];
		}

		return [ $id, null ];
	}

	if ( ! empty( $spec['toc_label'] ) ) {
		return [ null, "{$where}: toc_label belongs on nav_label, which is filled automatically" ];
	}

	return [ null, "{$where}: no source named — expected literal, section, image or toc_label" ];
}

/**
 * Build the rows for one repeater inside a block.
 *
 * Returns [ rows, error, provenance ] — provenance being the one line the
 * report prints so a reader can see where each list came from without opening
 * the map.
 */
function vs_bb_resolve_rows( $spec, $sources, &$claimed, $where ) {
	if ( ! is_array( $spec ) ) {
		return [ null, "{$where}: expected an object", '' ];
	}

	$map = (array) ( $spec['fields'] ?? [] );

	// Rows written out in full in the map. Content the template hardcodes today
	// and the CMS has never held — see the map's own rule about what may appear
	// as a literal.
	if ( isset( $spec['literal'] ) && ! isset( $spec['cards'] ) ) {
		$rows = array_values( (array) $spec['literal'] );

		return [ $rows, null, sprintf( '%d from the map', count( $rows ) ) ];
	}

	if ( isset( $spec['cards'] ) ) {
		$group = (string) $spec['cards'];

		if ( ! isset( $sources['cards'][ $group ] ) ) {
			return [ null, "{$where}: no cards rows in group \"{$group}\"", '' ];
		}

		$source_rows = $sources['cards'][ $group ];
		$literal     = array_values( (array) ( $spec['literal'] ?? [] ) );

		// Zipped positionally, so a length mismatch would silently pair the wrong
		// label with the wrong sentence. Refuse instead.
		if ( $literal && count( $literal ) !== count( $source_rows ) ) {
			return [
				null,
				sprintf(
					'%s: cards group "%s" has %d rows but the map supplies %d literal rows to zip onto them',
					$where,
					$group,
					count( $source_rows ),
					count( $literal )
				),
				'',
			];
		}

		$rows = [];

		foreach ( $source_rows as $i => $card ) {
			$row = [];

			foreach ( $map as $target => $from ) {
				$row[ $target ] = (string) ( $card[ (string) $from ] ?? '' );
			}

			if ( isset( $literal[ $i ] ) ) {
				$row = array_merge( $row, (array) $literal[ $i ] );
			}

			$rows[] = $row;
		}

		$claimed['cards'][ $group ] = true;

		return [
			$rows,
			null,
			sprintf(
				'%d from cards.%s%s',
				count( $rows ),
				$group,
				$literal ? ', labels from the map' : ''
			),
		];
	}

	foreach ( [ 'process_steps', 'faqs' ] as $kind ) {
		if ( empty( $spec[ $kind ] ) ) {
			continue;
		}

		$source_rows = (array) $sources[ $kind ];

		if ( ! $source_rows ) {
			return [ null, "{$where}: the page has no {$kind} rows", '' ];
		}

		$rows = [];

		foreach ( $source_rows as $i => $source_row ) {
			$row = [];

			foreach ( $map as $target => $from ) {
				// The one derivation the map may name. The legacy process_steps
				// repeater has no `num`; src/loaders/pages.ts:537 derives it as
				// String(i+1).padStart(2,'0') and the page prints that, so anything
				// else here renumbers the steps.
				$row[ $target ] = ( '@index2' === $from )
					? sprintf( '%02d', $i + 1 )
					: ( $source_row[ (string) $from ] ?? '' );
			}

			$rows[] = $row;
		}

		$claimed[ $kind ] = true;

		return [ $rows, null, sprintf( '%d from %s', count( $rows ), $kind ) ];
	}

	return [ null, "{$where}: no source named — expected literal, cards, process_steps or faqs", '' ];
}

/**
 * Plan one route: the rows that would be written, and everything wrong with them.
 *
 * Planning is separated from writing so that a fault on the tenth route stops
 * the first one from being half-migrated. Nothing here writes.
 */
function vs_bb_plan_route( $route, $config, $shapes ) {
	$plan = [
		'route'    => $route,
		'post_id'  => 0,
		'rows'     => [],
		'report'   => [],
		'errors'   => [],
		'warnings' => [],
	];

	$post_id = vs_bb_page_by_route( $route );

	if ( ! $post_id ) {
		$plan['errors'][] = "no WordPress page has _vs_route = {$route} — run: cd cms && npm run import:pages";

		return $plan;
	}

	$plan['post_id'] = $post_id;

	$sources = vs_bb_read_sources( $post_id );
	$claimed = [
		'sections'      => [],
		'cards'         => [],
		'images'        => [],
		'toc_links'     => [],
		'process_steps' => false,
		'faqs'          => false,
	];

	foreach ( (array) ( $config['blocks'] ?? [] ) as $index => $block ) {
		$section_id = (string) ( $block['section_id'] ?? '' );
		$layout     = (string) ( $block['layout'] ?? '' );
		$where      = sprintf( 'block %d (%s)', $index + 1, $section_id !== '' ? $section_id : $layout );

		if ( '' === $section_id || '' === $layout ) {
			$plan['errors'][] = "{$where}: needs both section_id and layout";
			continue;
		}

		if ( ! isset( $shapes[ $layout ] ) ) {
			$plan['errors'][] = sprintf(
				'%s: layout "%s" is not registered on the blocks field. Add it to the `layouts` array '
					. 'in cms/mu-plugins/vs-content-model.php and deploy, then re-run.',
				$where,
				$layout
			);
			continue;
		}

		$shape = $shapes[ $layout ];

		if ( ! isset( $sources['sections'][ $section_id ] ) ) {
			$plan['errors'][] = "{$where}: the page has no sections row with section_id \"{$section_id}\"";
			continue;
		}

		$section = $sources['sections'][ $section_id ];
		$claimed['sections'][ $section_id ] = true;

		// The anchor is the whole reason section_id survives ordering. It is a
		// public address — inbound links, the derived rail, and the
		// scroll-margin-top that lands someone below the nav all key off it — so
		// it is carried across verbatim rather than regenerated from the heading.
		$anchor = (string) ( $block['anchor'] ?? $section_id );

		// Claimed on the anchor, not on the label. A rail row with a blank label
		// still belongs to this block — it means "in the page, out of the rail" —
		// and treating it as unclaimed would fail the run over a deliberate blank.
		$nav_label = (string) ( $sources['toc_links'][ $anchor ] ?? '' );

		if ( array_key_exists( $anchor, $sources['toc_links'] ) ) {
			$claimed['toc_links'][ $anchor ] = true;
		}

		$row = [
			'acf_fc_layout' => $layout,
			'anchor'        => $anchor,
			'nav_label'     => $nav_label,
			'band'          => (string) ( $block['band'] ?? '' ),
		];

		if ( '' === $row['band'] ) {
			$plan['errors'][] = "{$where}: no band. Read it off the page's own stylesheet — the class "
				. 'name is not reliable, .alt and .dark mean opposite things on different pages.';
		}

		$filled = [];

		foreach ( (array) ( $block['fields'] ?? [] ) as $name => $spec ) {
			$name = (string) $name;

			if ( ! isset( $shape['fields'][ $name ] ) ) {
				$plan['errors'][] = sprintf(
					'%s: layout "%s" has no sub-field "%s" (it has: %s)',
					$where,
					$layout,
					$name,
					implode( ', ', array_keys( $shape['fields'] ) )
				);
				continue;
			}

			list( $value, $error ) = vs_bb_resolve_field( $spec, $section, $sources, $where . ' field ' . $name );

			if ( $error ) {
				$plan['errors'][] = $error;
				continue;
			}

			if ( isset( $spec['image'] ) ) {
				$claimed['images'][ (string) $spec['image'] ] = true;
			}

			// true_false stores 1/0; a PHP bool round-trips to the same meta, but
			// normalise so the written value and a hand-saved one are the same
			// bytes and the idempotency hash is stable.
			if ( is_bool( $value ) ) {
				$value = $value ? 1 : 0;
			}

			$row[ $name ] = $value;

			if ( '' !== (string) $value ) {
				$filled[] = $name;
			}
		}

		$row_report = [];

		foreach ( (array) ( $block['rows'] ?? [] ) as $name => $spec ) {
			$name = (string) $name;

			if ( ! isset( $shape['repeaters'][ $name ] ) ) {
				$plan['errors'][] = sprintf(
					'%s: layout "%s" has no repeater "%s" (it has: %s)',
					$where,
					$layout,
					$name,
					implode( ', ', array_keys( $shape['repeaters'] ) ) ?: 'none'
				);
				continue;
			}

			list( $rows, $error, $provenance ) = vs_bb_resolve_rows(
				$spec,
				$sources,
				$claimed,
				$where . ' rows ' . $name
			);

			if ( $error ) {
				$plan['errors'][] = $error;
				continue;
			}

			$allowed = $shape['repeaters'][ $name ];

			foreach ( $rows as $r ) {
				foreach ( array_keys( (array) $r ) as $sub ) {
					if ( ! in_array( (string) $sub, $allowed, true ) ) {
						$plan['errors'][] = sprintf(
							'%s: "%s" rows have no sub-field "%s" (they have: %s)',
							$where,
							$name,
							$sub,
							implode( ', ', $allowed )
						);
					}
				}
			}

			// Normalise booleans the same way, and for the same reason, as above.
			foreach ( $rows as $i => $r ) {
				foreach ( (array) $r as $sub => $v ) {
					if ( is_bool( $v ) ) {
						$rows[ $i ][ $sub ] = $v ? 1 : 0;
					}
				}
			}

			$row[ $name ] = $rows;
			$row_report[] = sprintf( '%s: %s', $name, $provenance );
		}

		$plan['rows'][] = $row;

		$plan['report'][] = [
			'position'  => count( $plan['rows'] ),
			'layout'    => $layout,
			'anchor'    => $anchor,
			'band'      => $row['band'],
			'nav_label' => $nav_label,
			'source'    => "sections.{$section_id}",
			'filled'    => $filled,
			'rows'      => $row_report,
		];
	}

	// ------------------------------------------------------------------
	// Nothing is dropped silently. Every row the blocks did not claim has to be
	// named in the map's `exempt` list, with a reason somebody wrote down.
	// docs/PAGE-BLOCKS.md 1.4, step 5.
	// ------------------------------------------------------------------
	$exempt = (array) ( $config['exempt'] ?? [] );

	$exempted = static function ( $list, $key ) {
		$out = [];

		foreach ( (array) $list as $entry ) {
			if ( is_array( $entry ) && isset( $entry[ $key ] ) ) {
				$out[ (string) $entry[ $key ] ] = true;
			}
		}

		return $out;
	};

	$exempt_sections = $exempted( $exempt['sections'] ?? [], 'section_id' );
	$exempt_cards    = $exempted( $exempt['cards'] ?? [], 'group' );
	$exempt_images   = $exempted( $exempt['images'] ?? [], 'slot' );
	$exempt_toc      = $exempted( $exempt['toc_links'] ?? [], 'anchor' );

	$leftovers = [];

	foreach ( array_keys( $sources['sections'] ) as $id ) {
		if ( ! isset( $claimed['sections'][ $id ] ) && ! isset( $exempt_sections[ $id ] ) ) {
			$leftovers[] = "sections row \"{$id}\"";
		}
	}

	foreach ( $sources['cards'] as $group => $rows ) {
		if ( ! isset( $claimed['cards'][ $group ] ) && ! isset( $exempt_cards[ $group ] ) ) {
			$leftovers[] = sprintf( 'cards group "%s" (%d rows)', $group, count( $rows ) );
		}
	}

	foreach ( array_keys( $sources['images'] ) as $slot ) {
		if ( ! isset( $claimed['images'][ $slot ] ) && ! isset( $exempt_images[ $slot ] ) ) {
			$leftovers[] = "images slot \"{$slot}\"";
		}
	}

	foreach ( array_keys( $sources['toc_links'] ) as $anchor ) {
		if ( ! isset( $claimed['toc_links'][ $anchor ] ) && ! isset( $exempt_toc[ $anchor ] ) ) {
			$leftovers[] = "toc_links anchor \"{$anchor}\"";
		}
	}

	foreach ( [ 'process_steps', 'faqs' ] as $kind ) {
		$all_exempt = false;

		foreach ( (array) ( $exempt[ $kind ] ?? [] ) as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['all'] ) ) {
				$all_exempt = true;
			}
		}

		if ( $sources[ $kind ] && ! $claimed[ $kind ] && ! $all_exempt ) {
			$leftovers[] = sprintf( '%s (%d rows)', $kind, count( $sources[ $kind ] ) );
		}
	}

	foreach ( $leftovers as $leftover ) {
		$plan['errors'][] = sprintf(
			'%s is claimed by no block and named in no `exempt` list. Either give it a block, or add it '
				. 'to routes["%s"].exempt with a reason — the point of that list is that somebody looked.',
			$leftover,
			$route
		);
	}

	// Declared exemptions are read out on every run. A reason written once in a
	// pull request is not the same as a reason seen by the person migrating the
	// page, and this list is where a lost rail entry would otherwise hide.
	foreach ( $exempt as $kind => $entries ) {
		if ( '_' === $kind ) {
			continue;
		}

		foreach ( (array) $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$name = (string) ( $entry['slot'] ?? $entry['anchor'] ?? $entry['group'] ?? $entry['section_id'] ?? 'all' );

			$plan['warnings'][] = sprintf(
				'  not migrated — %s "%s": %s',
				$kind,
				$name,
				(string) ( $entry['reason'] ?? 'no reason given' )
			);

			foreach ( (array) ( $entry['read_this_before_running'] ?? [] ) as $line ) {
				$plan['warnings'][] = '      ' . $line;
			}
		}
	}

	return $plan;
}

// ---------------------------------------------------------------------------
// Arguments.
// ---------------------------------------------------------------------------

$dry_run   = ! empty( getenv( 'VS_BACKFILL_DRY_RUN' ) );
$force     = ! empty( getenv( 'VS_BACKFILL_FORCE' ) );
$only      = [];
$map_path  = (string) getenv( 'VS_BACKFILL_MAP' );

if ( (string) getenv( 'VS_BACKFILL_ROUTE' ) !== '' ) {
	$only[] = (string) getenv( 'VS_BACKFILL_ROUTE' );
}

foreach ( (array) ( isset( $args ) ? $args : [] ) as $arg ) {
	$arg = ltrim( (string) $arg, '-' );

	if ( 'dry-run' === $arg || 'dry' === $arg ) {
		$dry_run = true;
	} elseif ( 'force' === $arg ) {
		$force = true;
	} elseif ( 0 === strpos( $arg, 'map=' ) ) {
		$map_path = substr( $arg, 4 );
	} elseif ( '' !== $arg && '/' === $arg[0] ) {
		$only[] = $arg;
	} elseif ( '' !== $arg ) {
		WP_CLI::error( "Unrecognised argument \"{$arg}\". Expected: dry-run, force, map=<path>, or /a/route/." );
	}
}

if ( '' === $map_path ) {
	// Located off WP_CONTENT_DIR like every other importer's payload, because
	// __DIR__ is meaningless inside eval'd code. cms/import is mapped to
	// wp-content/vs-import/bin by cms/.wp-env.json; on the CMS host, upload the
	// map beside this file or pass map=<absolute path>.
	$map_path = WP_CONTENT_DIR . '/vs-import/bin/block-map.json';
}

if ( ! file_exists( $map_path ) ) {
	WP_CLI::error( "block-map.json not found at {$map_path}. Pass map=<absolute path> if it lives elsewhere." );
}

$map_raw = (string) file_get_contents( $map_path );
$map     = json_decode( $map_raw, true );

if ( ! is_array( $map ) || empty( $map['routes'] ) ) {
	WP_CLI::error( "block-map.json at {$map_path} is empty or malformed." );
}

$routes = (array) $map['routes'];

if ( $only ) {
	$selected = [];

	foreach ( $only as $route ) {
		$route = '/' === substr( $route, -1 ) ? $route : $route . '/';

		if ( ! isset( $routes[ $route ] ) ) {
			WP_CLI::error(
				sprintf(
					'block-map.json has no entry for %s. It knows: %s',
					$route,
					implode( ', ', array_keys( $routes ) )
				)
			);
		}

		$selected[ $route ] = $routes[ $route ];
	}

	$routes = $selected;
}

WP_CLI::log( 'Vivid Smiles — blocks backfill' );
WP_CLI::log( sprintf( '  map     %s (sha1 %s)', $map_path, substr( sha1( $map_raw ), 0, 12 ) ) );
WP_CLI::log( sprintf( '  routes  %s', implode( ', ', array_keys( $routes ) ) ) );
WP_CLI::log( sprintf( '  mode    %s', $dry_run ? 'DRY RUN — nothing is written' : ( $force ? 'WRITE, FORCED — a non-empty blocks field will be overwritten' : 'write' ) ) );
WP_CLI::log( '' );

// ---------------------------------------------------------------------------
// Preflight. The field has to exist before anything else is worth checking —
// it lives in a must-use plugin somebody hand-deploys to the CMS host, and as
// of writing it is not there.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'acf_get_field' ) ) {
	WP_CLI::error(
		"Secure Custom Fields is not active on this install, so there is no `blocks` field to write.\n"
			. 'Install and activate it (cms/bin/setup.sh does this for the local environment), then re-run.'
	);
}

$blocks_field = acf_get_field( 'field_vs_blocks' );

if ( ! $blocks_field || empty( $blocks_field['layouts'] ) ) {
	WP_CLI::error(
		"The `blocks` field is not registered on this WordPress install, so there is nothing to back-fill into.\n"
			. "\n"
			. "It is defined in cms/mu-plugins/vs-content-model.php and has to be on the HOST before this runs.\n"
			. "Deploy it, confirm it, then re-run:\n"
			. "\n"
			. "  php -l cms/mu-plugins/vs-content-model.php\n"
			. "  bash cms/bin/deploy-mu-plugins.sh vs-content-model.php\n"
			. "\n"
			. "Confirm from outside: `blocks` should appear on PageFields in GraphQL. While it is missing,\n"
			. "that query answers 'Cannot query field \"blocks\" on type \"PageFields\"' — which is also\n"
			. "exactly what the front end sees, and why it keeps rendering every page from its template."
	);
}

$shapes = vs_bb_layout_shapes( $blocks_field );

WP_CLI::log( 'Preflight' );
WP_CLI::log( sprintf( '  ok    blocks field registered — %d layouts: %s', count( $shapes ), implode( ', ', array_keys( $shapes ) ) ) );

$pending = [];

foreach ( $routes as $route => $config ) {
	foreach ( (array) ( $config['blocks'] ?? [] ) as $block ) {
		$layout = (string) ( $block['layout'] ?? '' );

		if ( '' !== $layout && ! isset( $shapes[ $layout ] ) ) {
			$pending[ $layout ] = true;
		}
	}
}

if ( $pending ) {
	$lines = [];

	foreach ( array_keys( $pending ) as $layout ) {
		$contract = $map['pending_layouts'][ $layout ]['sub_fields'] ?? [];
		$lines[]  = sprintf(
			'  %s — sub-fields the map fills: %s',
			$layout,
			$contract ? implode( ', ', array_keys( (array) $contract ) ) : '(see block-map.json)'
		);
	}

	WP_CLI::error(
		sprintf(
			"The map names %d layout(s) this install does not register:\n\n%s\n\n"
				. "Add them to the `layouts` array in cms/mu-plugins/vs-content-model.php — and their entries in\n"
				. "src/blocks/registry.ts in the same commit — then deploy and re-run. Writing rows for an\n"
				. "unregistered layout would store content ACF cannot show and GraphQL cannot name.",
			count( $pending ),
			implode( "\n", $lines )
		)
	);
}

WP_CLI::log( '  ok    every layout the map names is registered' );
WP_CLI::log( '' );

// ---------------------------------------------------------------------------
// Plan every route before writing any of them. One fault anywhere stops the
// whole run — a half-migrated site is worse than an un-migrated one, and it is
// the state nobody has a procedure for.
// ---------------------------------------------------------------------------

$plans  = [];
$errors = [];

foreach ( $routes as $route => $config ) {
	$plan    = vs_bb_plan_route( $route, (array) $config, $shapes );
	$plans[] = $plan;

	foreach ( $plan['errors'] as $error ) {
		$errors[] = sprintf( '%s — %s', $route, $error );
	}
}

foreach ( $plans as $plan ) {
	WP_CLI::log( sprintf( '%s (post %d)', $plan['route'], $plan['post_id'] ) );

	foreach ( $plan['report'] as $line ) {
		WP_CLI::log(
			sprintf(
				'  %d. %-17s #%-10s %-10s %s',
				$line['position'],
				$line['layout'],
				$line['anchor'],
				$line['band'],
				'' !== $line['nav_label'] ? 'rail: "' . $line['nav_label'] . '"' : 'not in the rail'
			)
		);
		WP_CLI::log(
			sprintf(
				'       copy from %s%s',
				$line['source'],
				$line['filled'] ? ' — ' . implode( ', ', $line['filled'] ) : ''
			)
		);

		foreach ( $line['rows'] as $row_line ) {
			WP_CLI::log( '       ' . $row_line );
		}
	}

	foreach ( $plan['warnings'] as $warning ) {
		WP_CLI::log( $warning );
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
// Write.
// ---------------------------------------------------------------------------

$written   = 0;
$refused   = 0;
$unchanged = 0;

foreach ( $plans as $plan ) {
	$post_id = $plan['post_id'];
	$route   = $plan['route'];
	$rows    = $plan['rows'];
	$hash    = md5( (string) wp_json_encode( $rows ) );

	// The raw meta rather than get_field(): a flexible-content field stores its
	// layout names here, so this answers "are there rows" without ACF formatting
	// a value we are about to replace.
	$existing       = get_post_meta( $post_id, 'blocks', true );
	$existing_count = is_array( $existing ) ? count( $existing ) : 0;

	if ( $existing_count > 0 && ! $force ) {
		$previous = json_decode( (string) get_post_meta( $post_id, '_vs_blocks_backfill', true ), true );
		$is_ours  = is_array( $previous )
			&& ( $previous['hash'] ?? '' ) === $hash
			&& (int) ( $previous['rows'] ?? -1 ) === $existing_count;

		if ( $is_ours ) {
			$unchanged++;
			WP_CLI::log(
				sprintf(
					'%s — already back-filled, %d rows, same map and same content. Nothing to do.',
					$route,
					$existing_count
				)
			);
			continue;
		}

		$refused++;
		WP_CLI::warning(
			sprintf(
				"%s — REFUSED. `blocks` already holds %d row(s) that this run did not produce.\n"
					. "    That is either an editor's arrangement or an earlier back-fill from a different map,\n"
					. "    and nothing anywhere records what the order used to be — so overwriting it is the one\n"
					. "    thing here that cannot be undone.\n"
					. '    To replace it: empty the Sections list on this page in wp-admin and re-run, or pass `force`.',
				$route,
				$existing_count
			)
		);
		continue;
	}

	if ( $dry_run ) {
		WP_CLI::log( sprintf( '%s — would write %d block(s). Dry run, so nothing was.', $route, count( $rows ) ) );
		continue;
	}

	// One call, a wholesale replace. This is what makes a second run incapable
	// of appending: there is no add_row() anywhere in this script.
	//
	// Written by field KEY, with each row keyed by sub-field NAME plus its
	// acf_fc_layout marker — the convention import-pages.php sets out and the
	// reason it gives holds here too: writing the parent by name leaves SCF
	// without its companion _field reference and the rows invisible to both
	// wp-admin and WPGraphQL.
	update_field( 'field_vs_blocks', $rows, $post_id );

	update_post_meta(
		$post_id,
		'_vs_blocks_backfill',
		(string) wp_json_encode(
			[
				'hash'  => $hash,
				'rows'  => count( $rows ),
				'map'   => substr( sha1( $map_raw ), 0, 12 ),
				'route' => $route,
				'when'  => gmdate( 'c' ),
			]
		)
	);

	$written++;
	WP_CLI::log( sprintf( '%s — wrote %d block(s).', $route, count( $rows ) ) );
}

WP_CLI::log( '' );
WP_CLI::log( 'The six source repeaters were read and not modified. Nothing else on these pages was touched.' );

if ( ! $dry_run && $written > 0 ) {
	WP_CLI::log( '' );
	WP_CLI::log( 'To un-migrate a page: open it in wp-admin, Page content, Page sections, and empty the list.' );
	WP_CLI::log( 'The template renders it as it always has on the next build. No deploy, no code change.' );
	WP_CLI::log( '(The _vs_blocks_backfill meta this leaves behind is bookkeeping for this script alone;' );
	WP_CLI::log( ' it is safe to delete and nothing renders from it.)' );
}

if ( $refused > 0 ) {
	WP_CLI::error(
		sprintf(
			'%d written, %d unchanged, %d REFUSED — see above.',
			$written,
			$unchanged,
			$refused
		)
	);
}

WP_CLI::success(
	$dry_run
		? sprintf( 'Dry run clean across %d route(s). Nothing was written.', count( $plans ) )
		: sprintf( '%d written, %d already back-filled, 0 refused.', $written, $unchanged )
);
