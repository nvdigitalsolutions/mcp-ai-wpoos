<?php
/**
 * CI gate — every settings field in a sub-tabbed section must be registered.
 *
 * Why this exists: a settings section that splits its fields across sub-tabs
 * renders and saves ONLY the keys named in `get_subtab_groups()`. `render()`
 * iterates the active group's `fields`, and `sanitize_with_subtabs()`
 * whitelists submitted input to that same list. A field declared in
 * `get_fields()` but absent from every group is therefore invisible in the
 * admin AND silently dropped on save — with no PHP error, no PHPCS finding and
 * no failing test, because both methods are individually well-formed.
 *
 * That is exactly how `sse_offload_elapsed_seconds` shipped defined but
 * unregistered (#7905, fixed in #7908). This gate mechanises the invariant for
 * every sub-tabbed section, base and Pro.
 *
 * Scope: only sections that define `get_subtab_groups()` are checked. Sections
 * without sub-tabs render and save every field (`sanitize_fields()`), so they
 * cannot hide a field. A class that declares fields but inherits its groups
 * resolves them through its parent within the same scan.
 *
 * The analysis is static (token_get_all), so it needs no WordPress bootstrap and
 * can run on a bare `setup-php` action. Two consequences, both deliberate:
 *
 *  - Fields generated in a loop are invisible to the scan. They cannot produce a
 *    false "orphan", so a dynamic section never fails the gate spuriously.
 *  - A group key that resolves only at runtime looks "stale". Stale entries are
 *    reported as warnings, never as failures, so a dynamic key cannot break CI.
 *
 * Usage: php .github/scripts/check-settings-field-registration.php
 * Exit 0 = every field in a sub-tabbed section is registered; exit 1 = violation.
 *
 * @package WP_MCP_AI
 */

declare( strict_types=1 );

$root       = dirname( __DIR__, 2 );
$scan_roots = array(
	$root . '/includes/admin/sections',
	$root . '/addons/pro/includes/admin/sections',
);
$allow_file = __DIR__ . '/settings-field-registration.txt';

/**
 * Unquote a T_CONSTANT_ENCAPSED_STRING token value.
 *
 * @param string $literal Quoted literal as tokenized.
 * @return string
 */
function sfrg_literal( string $literal ): string {
	$quote = '' !== $literal ? $literal[0] : '';
	$body  = strlen( $literal ) >= 2 ? substr( $literal, 1, -1 ) : $literal;
	if ( "'" === $quote ) {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $body );
	}
	return stripcslashes( $body );
}

/**
 * Drop whitespace and comments so token offsets are stable.
 *
 * @param array<int, array|string> $tokens Raw token stream.
 * @return array<int, array|string>
 */
function sfrg_strip( array $tokens ): array {
	$out = array();
	foreach ( $tokens as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$out[] = $token;
	}
	return array_values( $out );
}

/**
 * Return the significant tokens inside a method body.
 *
 * @param array<int, array|string> $sig    Significant tokens.
 * @param string                   $method Method name.
 * @return array<int, array|string>|null Body tokens, or null when absent.
 */
function sfrg_method_body( array $sig, string $method ): ?array {
	$count = count( $sig );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $sig[ $i ] ) || T_FUNCTION !== $sig[ $i ][0] ) {
			continue;
		}
		$name = $i + 1;
		if ( ! isset( $sig[ $name ] ) || ! is_array( $sig[ $name ] ) || T_STRING !== $sig[ $name ][0] ) {
			continue;
		}
		if ( $method !== $sig[ $name ][1] ) {
			continue;
		}

		// Walk to the body brace; `;` means an abstract/interface declaration.
		$open = null;
		for ( $j = $name + 1; $j < $count; $j++ ) {
			if ( is_array( $sig[ $j ] ) ) {
				continue;
			}
			if ( '{' === $sig[ $j ] ) {
				$open = $j;
				break;
			}
			if ( ';' === $sig[ $j ] ) {
				return null;
			}
		}
		if ( null === $open ) {
			return null;
		}

		$body  = array();
		$depth = 0;
		for ( $k = $open; $k < $count; $k++ ) {
			$t = $sig[ $k ];
			if ( is_array( $t ) ) {
				if ( $k > $open ) {
					$body[] = $t;
				}
				continue;
			}
			if ( '{' === $t ) {
				++$depth;
				if ( 1 === $depth ) {
					continue;
				}
				$body[] = $t;
				continue;
			}
			if ( '}' === $t ) {
				--$depth;
				if ( 0 === $depth ) {
					break;
				}
				$body[] = $t;
				continue;
			}
			$body[] = $t;
		}
		return $body;
	}
	return null;
}

/**
 * Collect the keys of an array literal, at its top level only.
 *
 * @param array<int, array|string> $sig  Significant tokens.
 * @param int                      $open Offset of the opening `(` or `[`.
 * @return array<int, string>
 */
function sfrg_array_keys( array $sig, int $open ): array {
	$close   = '(' === $sig[ $open ] ? ')' : ']';
	$count   = count( $sig );
	$keys    = array();
	$depth   = 0;
	$pending = null;

	for ( $i = $open; $i < $count; $i++ ) {
		$t = $sig[ $i ];
		if ( is_array( $t ) ) {
			if ( 1 === $depth && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
				$pending = sfrg_literal( $t[1] );
			} elseif ( 1 === $depth && T_DOUBLE_ARROW === $t[0] && null !== $pending ) {
				$keys[]  = $pending;
				$pending = null;
			}
			continue;
		}
		if ( '(' === $t || '[' === $t ) {
			++$depth;
			continue;
		}
		if ( ')' === $t || ']' === $t ) {
			--$depth;
			if ( 0 === $depth ) {
				break;
			}
			continue;
		}
		if ( ',' === $t && 1 === $depth ) {
			$pending = null;
		}
	}
	return $keys;
}

/**
 * Collect bare string values of an array literal, at its top level only.
 *
 * Group field lists are written as `'fields' => array( 'a', 'b' )`, so a
 * top-level string that is NOT followed by `=>` is a member key.
 *
 * @param array<int, array|string> $sig  Significant tokens.
 * @param int                      $open Offset of the opening `(` or `[`.
 * @return array<int, string>
 */
function sfrg_array_values( array $sig, int $open ): array {
	$close   = '(' === $sig[ $open ] ? ')' : ']';
	$count   = count( $sig );
	$vals    = array();
	$depth   = 0;
	$pending = null;

	for ( $i = $open; $i < $count; $i++ ) {
		$t = $sig[ $i ];
		if ( is_array( $t ) ) {
			if ( 1 !== $depth ) {
				continue;
			}
			if ( T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
				$pending = sfrg_literal( $t[1] );
			} elseif ( T_DOUBLE_ARROW === $t[0] ) {
				$pending = null;
			}
			continue;
		}
		if ( '(' === $t || '[' === $t ) {
			++$depth;
			continue;
		}
		if ( ')' === $t || ']' === $t ) {
			--$depth;
			if ( 0 === $depth ) {
				if ( null !== $pending ) {
					$vals[] = $pending;
				}
				break;
			}
			continue;
		}
		if ( ',' === $t && 1 === $depth ) {
			if ( null !== $pending ) {
				$vals[] = $pending;
			}
			$pending = null;
		}
	}
	return $vals;
}

/**
 * Offset of the array literal that follows a `return` or `$fields =` keyword.
 *
 * @param array<int, array|string> $body  Method body tokens.
 * @param int                      $from  Offset to search from.
 * @return int|null Offset of the opening bracket.
 */
function sfrg_next_array( array $body, int $from ): ?int {
	$count = count( $body );
	for ( $i = $from; $i < $count; $i++ ) {
		$t = $body[ $i ];
		if ( is_array( $t ) && T_ARRAY === $t[0] ) {
			if ( isset( $body[ $i + 1 ] ) && ! is_array( $body[ $i + 1 ] ) && '(' === $body[ $i + 1 ] ) {
				return $i + 1;
			}
			continue;
		}
		if ( ! is_array( $t ) && '[' === $t ) {
			return $i;
		}
	}
	return null;
}

/**
 * Field keys declared by a section's get_fields() body.
 *
 * Handles both shapes in this repo: a single `return array( ... )`, and
 * `$fields['key'] = array( ... )` assignments before a `return $fields`.
 *
 * @param array<int, array|string> $body Method body tokens.
 * @return array<int, string>
 */
function sfrg_field_keys( array $body ): array {
	$keys  = array();
	$count = count( $body );

	// Shape 1: $fields['key'] = ...
	for ( $i = 0; $i + 4 < $count; $i++ ) {
		$t = $body[ $i ];
		if ( ! is_array( $t ) || T_VARIABLE !== $t[0] || '$fields' !== $t[1] ) {
			continue;
		}
		if ( '[' !== $body[ $i + 1 ] ) {
			continue;
		}
		$lit = $body[ $i + 2 ];
		if ( ! is_array( $lit ) || T_CONSTANT_ENCAPSED_STRING !== $lit[0] ) {
			continue;
		}
		if ( ']' !== $body[ $i + 3 ] || '=' !== $body[ $i + 4 ] ) {
			continue;
		}
		$keys[] = sfrg_literal( $lit[1] );
	}

	// Shape 2: return array( ... ) / return array(...) / return [ ... ].
	for ( $i = 0; $i < $count; $i++ ) {
		$t = $body[ $i ];
		if ( is_array( $t ) && T_RETURN === $t[0] ) {
			$open = sfrg_next_array( $body, $i + 1 );
			if ( null !== $open ) {
				$keys = array_merge( $keys, sfrg_array_keys( $body, $open ) );
			}
			continue;
		}
		if ( is_array( $t ) && T_VARIABLE === $t[0] && '$fields' === $t[1] ) {
			if ( isset( $body[ $i + 1 ] ) && ! is_array( $body[ $i + 1 ] ) && '=' === $body[ $i + 1 ] ) {
				$open = sfrg_next_array( $body, $i + 2 );
				if ( null !== $open ) {
					$keys = array_merge( $keys, sfrg_array_keys( $body, $open ) );
				}
			}
		}
	}

	return array_values( array_unique( $keys ) );
}

/**
 * Collect every string literal inside a non-literal expression.
 *
 * A group may build its field list with `array_merge( array( ... ), COND ?
 * array( ... ) : array() )`. The conditional branch is invisible to
 * sfrg_array_values(), so a plain-array read would report those keys as
 * orphans. Collecting every literal in the expression is deliberately
 * conservative: over-collecting can only hide an orphan, never invent one.
 * The argument of `defined()` is skipped because it names a constant rather
 * than a field.
 *
 * @param array<int, array|string> $sig  Significant tokens.
 * @param int                      $from Offset of the first token of the expression.
 * @return array<int, string>
 */
function sfrg_expression_literals( array $sig, int $from ): array {
	$count = count( $sig );
	$lits  = array();
	$depth = 0;

	for ( $i = $from; $i < $count; $i++ ) {
		$t = $sig[ $i ];
		if ( is_array( $t ) ) {
			if ( T_CONSTANT_ENCAPSED_STRING !== $t[0] ) {
				continue;
			}
			$is_defined_arg = isset( $sig[ $i - 2 ] ) && is_array( $sig[ $i - 2 ] )
				&& T_STRING === $sig[ $i - 2 ][0] && 'defined' === $sig[ $i - 2 ][1]
				&& ! is_array( $sig[ $i - 1 ] ) && '(' === $sig[ $i - 1 ];
			if ( ! $is_defined_arg ) {
				$lits[] = sfrg_literal( $t[1] );
			}
			continue;
		}
		if ( '(' === $t || '[' === $t ) {
			++$depth;
			continue;
		}
		if ( ')' === $t || ']' === $t ) {
			--$depth;
			if ( $depth < 0 ) {
				break;
			}
			continue;
		}
		if ( ',' === $t && 0 === $depth ) {
			break;
		}
	}

	return $lits;
}

/**
 * Members of every `'fields' => ...` list in a get_subtab_groups() body.
 *
 * @param array<int, array|string> $body Method body tokens.
 * @return array<int, string>
 */
function sfrg_group_members( array $body ): array {
	$members = array();
	$count   = count( $body );

	for ( $i = 0; $i + 3 < $count; $i++ ) {
		$t = $body[ $i ];
		if ( ! is_array( $t ) || T_CONSTANT_ENCAPSED_STRING !== $t[0] ) {
			continue;
		}
		if ( 'fields' !== sfrg_literal( $t[1] ) ) {
			continue;
		}
		$arrow = $body[ $i + 1 ];
		if ( ! is_array( $arrow ) || T_DOUBLE_ARROW !== $arrow[0] ) {
			continue;
		}
		$first = $body[ $i + 2 ] ?? null;
		if ( null === $first ) {
			continue;
		}

		// Plain array literal: `array( 'a', 'b' )` or `[ 'a', 'b' ]`.
		$is_literal = ( is_array( $first ) && T_ARRAY === $first[0] ) || ( ! is_array( $first ) && '[' === $first );
		if ( $is_literal ) {
			$open = sfrg_next_array( $body, $i + 2 );
			if ( null !== $open ) {
				$members = array_merge( $members, sfrg_array_values( $body, $open ) );
			}
			continue;
		}

		// Built expression (`array_merge( ... )`, a variable): collect literals.
		$members = array_merge( $members, sfrg_expression_literals( $body, $i + 2 ) );
	}

	return array_values( array_unique( $members ) );
}

/**
 * Class name, parent class and declared field/group sets for one file.
 *
 * @param string $src PHP source.
 * @return array<string, array{parent: ?string, fields: ?array<int, string>, groups: ?array<int, string>}>
 */
function sfrg_parse_file( string $src ): array {
	$sig     = sfrg_strip( token_get_all( $src ) );
	$count   = count( $sig );
	$classes = array();
	$current = null;

	for ( $i = 0; $i < $count; $i++ ) {
		$t = $sig[ $i ];
		if ( ! is_array( $t ) || T_CLASS !== $t[0] ) {
			continue;
		}
		// Skip anonymous classes (T_NEW ... T_CLASS).
		if ( $i > 0 && is_array( $sig[ $i - 1 ] ) && T_NEW === $sig[ $i - 1 ][0] ) {
			continue;
		}
		$name = $sig[ $i + 1 ] ?? null;
		if ( ! is_array( $name ) || T_STRING !== $name[0] ) {
			continue;
		}
		$current = $name[1];

		$parent = null;
		for ( $j = $i + 2; $j < $count; $j++ ) {
			if ( is_array( $sig[ $j ] ) ) {
				if ( T_EXTENDS === $sig[ $j ][0] ) {
					$parent_token = $sig[ $j + 1 ] ?? null;
					if ( is_array( $parent_token ) && T_STRING === $parent_token[0] ) {
						$parent = $parent_token[1];
					}
					break;
				}
				if ( T_IMPLEMENTS === $sig[ $j ][0] || '{' === ( $sig[ $j ][0] ?? null ) ) {
					break;
				}
				continue;
			}
			if ( '{' === $sig[ $j ] ) {
				break;
			}
		}

		$classes[ $current ] = array(
			'parent' => $parent,
			'fields' => null,
			'groups' => null,
		);
	}

	// Method bodies are scanned per file; attribute them to the class that owns
	// the method by tracking the last class declaration before each function.
	$owner = null;
	for ( $i = 0; $i < $count; $i++ ) {
		$t = $sig[ $i ];
		if ( is_array( $t ) && T_CLASS === $t[0] ) {
			$name = $sig[ $i + 1 ] ?? null;
			if ( is_array( $name ) && T_STRING === $name[0] ) {
				$owner = $name[1];
			}
			continue;
		}
		if ( ! is_array( $t ) || T_FUNCTION !== $t[0] || null === $owner || ! isset( $classes[ $owner ] ) ) {
			continue;
		}

		// Find the token offset of this function so sfrg_method_body can reuse
		// the scan without re-tokenizing.
		$body = null;
		$open = null;
		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( is_array( $sig[ $j ] ) ) {
				continue;
			}
			if ( '{' === $sig[ $j ] ) {
				$open = $j;
				break;
			}
			if ( ';' === $sig[ $j ] ) {
				break;
			}
		}
		if ( null === $open ) {
			continue;
		}
		$body  = array();
		$depth = 0;
		for ( $k = $open; $k < $count; $k++ ) {
			$tok = $sig[ $k ];
			if ( is_array( $tok ) ) {
				if ( $k > $open ) {
					$body[] = $tok;
				}
				continue;
			}
			if ( '{' === $tok ) {
				++$depth;
				if ( 1 === $depth ) {
					continue;
				}
				$body[] = $tok;
				continue;
			}
			if ( '}' === $tok ) {
				--$depth;
				if ( 0 === $depth ) {
					break;
				}
				$body[] = $tok;
				continue;
			}
			$body[] = $tok;
		}

		$name_token = $sig[ $i + 1 ] ?? null;
		if ( ! is_array( $name_token ) || T_STRING !== $name_token[0] ) {
			continue;
		}
		if ( 'get_fields' === $name_token[1] ) {
			$classes[ $owner ]['fields'] = sfrg_field_keys( $body );
		} elseif ( 'get_subtab_groups' === $name_token[1] ) {
			$classes[ $owner ]['groups'] = sfrg_group_members( $body );
		}
	}

	return $classes;
}

$allowed = array();
if ( is_readable( $allow_file ) ) {
	foreach ( file( $allow_file, FILE_IGNORE_NEW_LINES ) as $line ) {
		$line = trim( (string) preg_replace( '/#.*$/', '', (string) $line ) );
		if ( '' === $line ) {
			continue;
		}
		$allowed[] = (string) preg_replace( '/\s+/', ' ', $line );
	}
}

$sections = array();
foreach ( $scan_roots as $scan_root ) {
	if ( ! is_dir( $scan_root ) ) {
		continue;
	}
	$files = glob( $scan_root . '/*.php' );
	if ( false === $files ) {
		$files = array();
	}
	foreach ( $files as $file_path ) {
		$parsed = sfrg_parse_file( (string) file_get_contents( $file_path ) );
		foreach ( $parsed as $class => $info ) {
			$info['file']       = ltrim( str_replace( $root, '', $file_path ), '/\\' );
			$sections[ $class ] = $info;
		}
	}
}

$checked    = array();
$orphans    = array();
$ghosts     = array();
$violations = array();

foreach ( $sections as $class => $info ) {
	// Resolve sub-tab membership; a class may inherit its groups.
	$members = $info['groups'];
	$parent  = $info['parent'];
	$depth   = 0;
	while ( null === $members && null !== $parent && isset( $sections[ $parent ] ) && $depth < 5 ) {
		$members = $sections[ $parent ]['groups'];
		$parent  = $sections[ $parent ]['parent'];
		++$depth;
	}
	if ( null === $members || array() === $members ) {
		continue; // No sub-tabs: every field is rendered and saved.
	}
	if ( null === $info['fields'] ) {
		continue; // Declares no fields of its own.
	}

	$checked[] = $class . ' (' . count( $info['fields'] ) . ' fields)';

	foreach ( array_diff( $info['fields'], $members ) as $orphan ) {
		$key          = $info['file'] . ':' . $orphan;
		$orphans[]    = $key;
		$violations[] = $key;
	}
	foreach ( array_diff( $members, $info['fields'] ) as $ghost ) {
		$ghosts[] = $info['file'] . ':' . $ghost;
	}
}

$unreviewed = array_values( array_diff( $violations, $allowed ) );
$stale      = array_values( array_diff( $allowed, $violations ) );

fwrite( STDOUT, 'Sub-tabbed sections checked: ' . count( $checked ) . "\n" );
foreach ( $checked as $label ) {
	fwrite( STDOUT, '  - ' . $label . "\n" );
}

if ( array() !== $ghosts ) {
	echo "\nGrouped keys with no matching field (warning only — may be resolved at runtime):\n";
	foreach ( $ghosts as $ghost ) {
		fwrite( STDOUT, '  ~ ' . $ghost . "\n" );
	}
}

if ( array() !== $unreviewed ) {
	foreach ( $unreviewed as $key ) {
		fwrite( STDERR, '::error::Field is defined but in no sub-tab (renders nowhere, dropped on save): ' . $key . "\n" );
	}
	fwrite( STDERR, "\nAdd the field key to a sub-tab group in get_subtab_groups(), or review why it\nis intentionally hidden and list it in .github/scripts/settings-field-registration.txt.\n" );
	exit( 1 );
}

if ( array() !== $stale ) {
	foreach ( $stale as $key ) {
		fwrite( STDERR, '::error::Stale allowlist entry (field is now registered): ' . $key . "\n" );
	}
	fwrite( STDERR, "\nRemove the stale entry from .github/scripts/settings-field-registration.txt.\n" );
	exit( 1 );
}

echo "\nOK — every field in a sub-tabbed section is registered.\n";
exit( 0 );
