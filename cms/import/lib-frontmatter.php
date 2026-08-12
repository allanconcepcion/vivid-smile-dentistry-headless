<?php
/**
 * Minimal YAML front-matter reader for the one-time content import.
 *
 * Deliberately not a general YAML parser. The repo's front matter uses exactly
 * three shapes, and hand-rolling them avoids adding a Composer dependency to a
 * script that runs once per environment:
 *
 *   key: "quoted string"     |  key: bare string  |  key: 5  |  key: true
 *   key:                        (block sequence)
 *     - "item"
 *   key: [a, b]                 (inline sequence)
 *
 * Anything more exotic in a source file should fail loudly rather than be
 * silently half-parsed — see the exception in split_front_matter().
 */

declare( strict_types=1 );

namespace VividSmiles\Import;

/**
 * Split a markdown file into [frontMatterLines, bodyMarkdown].
 *
 * @throws \RuntimeException When the file has no closing front-matter fence.
 */
function split_front_matter( string $raw, string $path ): array {
	// Strip a UTF-8 BOM and normalise line endings before matching the fence.
	$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw ) ?? $raw;
	$raw = str_replace( [ "\r\n", "\r" ], "\n", $raw );

	if ( ! str_starts_with( $raw, "---\n" ) ) {
		throw new \RuntimeException( "No opening front-matter fence in {$path}" );
	}

	$end = strpos( $raw, "\n---\n", 3 );
	if ( $end === false ) {
		throw new \RuntimeException( "No closing front-matter fence in {$path}" );
	}

	$front = substr( $raw, 4, $end - 3 );
	$body  = substr( $raw, $end + 5 );

	return [ explode( "\n", trim( $front, "\n" ) ), trim( $body ) ];
}

/**
 * Strip matching surrounding quotes and unescape the few sequences that appear
 * in the source files.
 */
function scalar( string $value ): string {
	$value = trim( $value );

	if ( strlen( $value ) >= 2 ) {
		$first = $value[0];
		$last  = $value[ strlen( $value ) - 1 ];

		if ( ( $first === '"' && $last === '"' ) || ( $first === "'" && $last === "'" ) ) {
			$value = substr( $value, 1, -1 );

			if ( $first === '"' ) {
				$value = str_replace( [ '\\"', '\\n', '\\\\' ], [ '"', "\n", '\\' ], $value );
			} else {
				$value = str_replace( "''", "'", $value );
			}
		}
	}

	return $value;
}

/**
 * Parse front-matter lines into an associative array.
 *
 * Values are returned as strings, or arrays of strings for sequences. Type
 * coercion (to int, bool, DateTime) is the caller's job — it knows the schema.
 */
function parse_front_matter( array $lines ): array {
	$out     = [];
	$current = null;

	foreach ( $lines as $line ) {
		if ( trim( $line ) === '' || str_starts_with( ltrim( $line ), '#' ) ) {
			continue;
		}

		// Block-sequence item belonging to the previous key.
		if ( preg_match( '/^\s+-\s*(.*)$/', $line, $m ) === 1 ) {
			if ( $current !== null ) {
				$out[ $current ][] = scalar( $m[1] );
			}
			continue;
		}

		if ( preg_match( '/^([A-Za-z_][A-Za-z0-9_]*):\s*(.*)$/', $line, $m ) !== 1 ) {
			continue;
		}

		$key   = $m[1];
		$value = trim( $m[2] );

		if ( $value === '' ) {
			// Key introducing a block sequence; items arrive on later lines.
			$out[ $key ] = [];
			$current     = $key;
			continue;
		}

		// Inline sequence: [a, b, c]
		if ( str_starts_with( $value, '[' ) && str_ends_with( $value, ']' ) ) {
			$inner       = trim( substr( $value, 1, -1 ) );
			$out[ $key ] = $inner === ''
				? []
				: array_map( __NAMESPACE__ . '\\scalar', explode( ',', $inner ) );
			$current     = null;
			continue;
		}

		$out[ $key ] = scalar( $value );
		$current     = null;
	}

	return $out;
}

/**
 * Read and parse one markdown file.
 */
function read_markdown( string $path ): array {
	$raw = file_get_contents( $path );

	if ( $raw === false ) {
		throw new \RuntimeException( "Could not read {$path}" );
	}

	[ $lines, $body ] = split_front_matter( $raw, $path );

	return [ parse_front_matter( $lines ), $body ];
}
