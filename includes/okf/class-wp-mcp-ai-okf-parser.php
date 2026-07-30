<?php
/**
 * OKF Parser — Minimal YAML frontmatter parser for OKF concept documents.
 *
 * Parses the lightweight YAML frontmatter subset required by the OKF v0.2
 * specification: scalars, inline mappings ({key: value}), lists of scalars,
 * lists of objects, and nested mapping blocks. Does not handle anchors,
 * aliases, multi-line strings (|, >), or complex flow constructs beyond
 * inline mappings — none of which are needed for OKF frontmatter.
 *
 * @package WP_MCP_AI
 * @since   2.1.0
 * @since   2.5.0 — Extended to parse OKF v0.2 trust-signal fields (generated,
 *                verified, sources, stale_after, status) and nested structures.
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 *
 * @link https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal YAML frontmatter parser for OKF.
 *
 * @since 2.1.0
 */
class WP_MCP_AI_OKF_Parser {

	/**
	 * Supported scalar types.
	 *
	 * @since 2.1.0
	 * @var array
	 */
	const SCALAR_PATTERNS = array(
		'string'  => '/^"(.*)"$/',
		'integer' => '/^-?\d+$/',
		'float'   => '/^-?\d+\.\d+$/',
		'boolean' => '/^(true|false)$/i',
		'null'    => '/^(null|~)$/i',
	);

	/**
	 * Regex for recognising OKF identifier-like keys (letters, digits, underscores).
	 *
	 * @since 2.5.0
	 * @var string
	 */
	const KEY_PATTERN = '/^(\w[\w\s]*?):\s*(.*)$/';

	/**
	 * Temporary line buffer during parsing.
	 *
	 * @since 2.5.0
	 * @var string[]
	 */
	private $parse_lines = array();

	/**
	 * Line count for the current parse run.
	 *
	 * @since 2.5.0
	 * @var int
	 */
	private $parse_count = 0;

	/**
	 * Current line index during parsing.
	 *
	 * @since 2.5.0
	 * @var int
	 */
	private $parse_pos = 0;

	/**
	 * Extract YAML frontmatter and body from a markdown string.
	 *
	 * Returns an array with 'frontmatter' (parsed associative array) and
	 * 'body' (the remaining markdown after the closing `---`).
	 *
	 * Returns null if the document has no frontmatter block.
	 * Returns a WP_Error if the frontmatter is malformed.
	 *
	 * @since 2.1.0
	 *
	 * @param string $content Raw markdown file content.
	 * @return array{frontmatter: array, body: string}|null|WP_Error
	 */
	public function parse( $content ) {
		$content = trim( $content );

		// Frontmatter must start with --- on the first line.
		if ( 0 !== strpos( $content, '---' ) ) {
			return null;
		}

		// Find the closing ---.
		$closing_pos = strpos( $content, '---', 3 );
		if ( false === $closing_pos ) {
			return new WP_Error(
				'okf_unclosed_frontmatter',
				__( 'OKF frontmatter block is not closed with ---.', 'mcp-ai-wpoos' )
			);
		}

		$yaml_block = substr( $content, 3, $closing_pos - 3 );
		$body       = trim( substr( $content, $closing_pos + 3 ) );

		$frontmatter = $this->parse_yaml_block( $yaml_block );
		if ( is_wp_error( $frontmatter ) ) {
			return $frontmatter;
		}

		return array(
			'frontmatter' => $frontmatter,
			'body'        => $body,
		);
	}

	// -------------------------------------------------------------------------
	// Parsing — Indentation-aware recursive descent
	// -------------------------------------------------------------------------

	/**
	 * Parse a full YAML frontmatter block into an associative array.
	 *
	 * Entry point for the recursive descent parser. Sets up shared state
	 * and delegates to the root-level mapping parser.
	 *
	 * @since 2.5.0 — Replaces the flat parse_yaml_lines().
	 *
	 * @param string $yaml Raw YAML content (between the --- delimiters).
	 * @return array|WP_Error
	 */
	private function parse_yaml_block( $yaml ) {
		$this->parse_lines = explode( "\n", $yaml );
		$this->parse_count = count( $this->parse_lines );
		$this->parse_pos   = 0;

		$result = $this->parse_mapping( 0 );

		// Clean up temporary state.
		unset( $this->parse_lines, $this->parse_count, $this->parse_pos );

		return $result;
	}

	/**
	 * Parse a mapping block (key: value pairs) at a given indentation level.
	 *
	 * Advances $this->parse_pos past all consumed lines. Stops when it
	 * encounters a line at lower indentation than $base_indent (signalling
	 * the end of this block).
	 *
	 * @since 2.5.0
	 *
	 * @param int $base_indent Expected indentation for keys in this block.
	 * @return array Associative array of parsed key-value pairs.
	 */
	private function parse_mapping( $base_indent ) {
		$result = array();

		while ( $this->parse_pos < $this->parse_count ) {
			$raw     = $this->parse_lines[ $this->parse_pos ];
			$trimmed = rtrim( $raw );
			$indent  = $this->get_indent( $raw );

			// A line at strictly lower indentation ends this block.
			if ( '' !== $trimmed && '#' !== $trimmed[0] && $indent < $base_indent ) {
				break;
			}

			++$this->parse_pos;

			// Skip blank lines and comment-only lines.
			if ( '' === $trimmed || '#' === $trimmed[0] ) {
				continue;
			}

			// Skip lines not at our expected indentation (they belong to a parent/sibling).
			if ( $indent !== $base_indent ) {
				continue;
			}

			$stripped = ltrim( $trimmed );

			// Match key: value.
			if ( preg_match( self::KEY_PATTERN, $stripped, $m ) ) {
				$key   = trim( $m[1] );
				$value = trim( $m[2] );

				if ( '' === $value ) {
					// Empty value — resolve the indented block (list or nested mapping).
					$result[ $key ] = $this->parse_block_value( $indent );
				} else {
					$result[ $key ] = $this->cast_value( $value );
				}
			}
		}

		return $result;
	}

	/**
	 * Parse the indented value block that follows a `key:` with an empty value.
	 *
	 * Determines whether the block is a YAML list (items start with `- `)
	 * or a nested mapping, then delegates accordingly.
	 *
	 * @since 2.5.0
	 *
	 * @param int $parent_indent Indentation of the parent `key:` line.
	 * @return array Parsed value (list or mapping).
	 */
	private function parse_block_value( $parent_indent ) {
		// Peek at the next content line to determine block type.
		$peek          = $this->parse_pos;
		$next_stripped = '';
		$next_indent   = -1;

		while ( $peek < $this->parse_count ) {
			$raw     = $this->parse_lines[ $peek ];
			$trimmed = rtrim( $raw );
			if ( '' !== $trimmed && '#' !== $trimmed[0] ) {
				$next_stripped = ltrim( $trimmed );
				$next_indent   = $this->get_indent( $raw );
				break;
			}
			++$peek;
		}

		// No content — empty value.
		if ( -1 === $next_indent || $next_indent <= $parent_indent ) {
			return array();
		}

		// List: items start with "- ".
		if ( strpos( $next_stripped, '- ' ) === 0 ) {
			return $this->parse_list( $next_indent, $parent_indent );
		}

		// Otherwise it's a nested mapping.
		return $this->parse_mapping( $next_indent );
	}

	/**
	 * Parse a YAML list at the given indentation level.
	 *
	 * Each list item starts with `- ` at $list_indent. Items can be:
	 * - Scalars: `- value`
	 * - Inline mappings: `- { key: value, ... }`
	 * - Objects: `- key: value` followed by more indented keys
	 *
	 * @since 2.5.0
	 *
	 * @param int $list_indent   Indentation of the `- ` prefix.
	 * @param int $parent_indent Indentation of the parent `key:` line (for boundary detection).
	 * @return array Parsed list.
	 */
	private function parse_list( $list_indent, $parent_indent ) {
		$items = array();

		while ( $this->parse_pos < $this->parse_count ) {
			$raw     = $this->parse_lines[ $this->parse_pos ];
			$trimmed = rtrim( $raw );
			$indent  = $this->get_indent( $raw );

			// End of list: line at or above parent indentation (not a comment/blank).
			if ( '' !== $trimmed && '#' !== $trimmed[0] && $indent <= $parent_indent ) {
				break;
			}

			++$this->parse_pos;

			if ( '' === $trimmed || '#' === $trimmed[0] ) {
				continue;
			}

			$stripped = ltrim( $trimmed );

			// List item must start with "- ".
			if ( 0 !== strpos( $stripped, '- ' ) ) {
				continue;
			}

			$item_value = trim( substr( $stripped, 2 ) );

			// Case 1: inline mapping — `- { key: value, ... }`.
			if ( strlen( $item_value ) > 0 && '{' === $item_value[0] ) {
				$items[] = $this->parse_inline_mapping( $item_value );
				continue;
			}

			// Case 2: inline flow sequence — `- [item, item, ...]`.
			if ( strlen( $item_value ) > 0 && '[' === $item_value[0] ) {
				$items[] = $this->parse_inline_sequence( $item_value );
				continue;
			}

			// Case 3: object start — `- key: value` (may continue on indented lines).
			if ( preg_match( self::KEY_PATTERN, $item_value, $km ) ) {
				$obj_key   = trim( $km[1] );
				$obj_value = trim( $km[2] );

				// Start building a nested object.
				$nested = array();

				if ( '' === $obj_value ) {
					// `- key:` with empty value — the nested value follows on indented lines.
					$nested[ $obj_key ] = $this->parse_block_value( $indent );
				} else {
					$nested[ $obj_key ] = $this->cast_value( $obj_value );
				}

				// Collect continuation keys (same indent as the value portion, deeper than list indent).
				$nested = $this->parse_object_continuation( $nested, $indent );

				$items[] = $nested;
				continue;
			}

			// Case 4: plain scalar — `- value`.
			$items[] = $this->cast_value( $item_value );
		}

		return $items;
	}

	/**
	 * Collect continuation keys for a list item that is a nested object.
	 *
	 * After parsing `- key: value` at $list_indent, subsequent lines at a
	 * deeper indentation are additional keys of the same nested object.
	 * Stops when a line returns to $list_indent or above.
	 *
	 * @since 2.5.0
	 *
	 * @param array $nested      The object built so far.
	 * @param int   $list_indent Indentation of the `- ` prefix.
	 * @return array The completed nested object.
	 */
	private function parse_object_continuation( $nested, $list_indent ) {
		while ( $this->parse_pos < $this->parse_count ) {
			$raw     = $this->parse_lines[ $this->parse_pos ];
			$trimmed = rtrim( $raw );
			$indent  = $this->get_indent( $raw );

			// Stop at list level, parent level, or blank/comment.
			if ( '' === $trimmed || '#' === $trimmed[0] ) {
				++$this->parse_pos;
				continue;
			}

			if ( $indent <= $list_indent ) {
				break;
			}

			++$this->parse_pos;

			$stripped = ltrim( $trimmed );

			if ( preg_match( self::KEY_PATTERN, $stripped, $km ) ) {
				$key   = trim( $km[1] );
				$value = trim( $km[2] );

				if ( '' === $value ) {
					$nested[ $key ] = $this->parse_block_value( $indent );
				} else {
					$nested[ $key ] = $this->cast_value( $value );
				}
			}
		}

		return $nested;
	}

	// -------------------------------------------------------------------------
	// Value casting and inline construct parsing
	// -------------------------------------------------------------------------

	/**
	 * Cast a YAML value to the appropriate PHP type.
	 *
	 * Handles quoted strings, inline mappings ({key: value}), flow sequences
	 * ([a, b]), and standard YAML scalars (bool, null, int, float, string).
	 *
	 * @since 2.5.0 — Extended from cast_scalar() to handle inline mappings and sequences.
	 *
	 * @param string $value Raw value string (already trimmed).
	 * @return mixed
	 */
	private function cast_value( $value ) {
		// Inline mapping: { key: value, ... }.
		if ( strlen( $value ) > 0 && '{' === $value[0] ) {
			return $this->parse_inline_mapping( $value );
		}

		// Inline flow sequence: [ item, item, ... ].
		if ( strlen( $value ) > 0 && '[' === $value[0] ) {
			return $this->parse_inline_sequence( $value );
		}

		return $this->cast_scalar( $value );
	}

	/**
	 * Parse an inline YAML mapping: `{ key1: value1, key2: value2 }`.
	 *
	 * Handles nested inline mappings and sequences within the braces.
	 *
	 * @since 2.5.0
	 *
	 * @param string $raw Raw inline mapping string (with or without outer braces).
	 * @return array Associative array.
	 */
	private function parse_inline_mapping( $raw ) {
		// Strip outer braces if present.
		$raw = trim( $raw );
		if ( strlen( $raw ) > 0 && '{' === $raw[0] ) {
			$raw = substr( $raw, 1 );
		}
		$len = strlen( $raw );
		if ( $len > 0 && '}' === $raw[ $len - 1 ] ) {
			$raw = substr( $raw, 0, -1 );
		}

		if ( '' === trim( $raw ) ) {
			return array();
		}

		$result = array();
		$pairs  = $this->split_inline_pairs( $raw );

		foreach ( $pairs as $pair ) {
			$colon_pos = $this->find_top_level_colon( $pair );
			if ( false === $colon_pos ) {
				continue;
			}

			$key   = trim( substr( $pair, 0, $colon_pos ) );
			$value = trim( substr( $pair, $colon_pos + 1 ) );

			$result[ $key ] = $this->cast_value( $value );
		}

		return $result;
	}

	/**
	 * Parse an inline YAML flow sequence: `[ item1, item2, item3 ]`.
	 *
	 * @since 2.5.0
	 *
	 * @param string $raw Raw inline sequence string (with or without outer brackets).
	 * @return array Indexed array.
	 */
	private function parse_inline_sequence( $raw ) {
		$raw = trim( $raw );
		if ( strlen( $raw ) > 0 && '[' === $raw[0] ) {
			$raw = substr( $raw, 1 );
		}
		$len = strlen( $raw );
		if ( $len > 0 && ']' === $raw[ $len - 1 ] ) {
			$raw = substr( $raw, 0, -1 );
		}

		if ( '' === trim( $raw ) ) {
			return array();
		}

		$items  = $this->split_inline_pairs( $raw );
		$result = array();

		foreach ( $items as $item ) {
			$result[] = $this->cast_value( trim( $item ) );
		}

		return $result;
	}

	/**
	 * Split a comma-separated string respecting nested braces and brackets.
	 *
	 * @since 2.5.0
	 *
	 * @param string $raw String to split on top-level commas.
	 * @return string[] Array of trimmed pair strings.
	 */
	private function split_inline_pairs( $raw ) {
		$pairs  = array();
		$depth  = 0;
		$buffer = '';
		$len    = strlen( $raw );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $raw[ $i ];

			if ( '{' === $ch || '[' === $ch ) {
				++$depth;
				$buffer .= $ch;
			} elseif ( '}' === $ch || ']' === $ch ) {
				--$depth;
				$buffer .= $ch;
			} elseif ( ',' === $ch && 0 === $depth ) {
				$pairs[] = trim( $buffer );
				$buffer  = '';
			} else {
				$buffer .= $ch;
			}
		}

		if ( '' !== trim( $buffer ) ) {
			$pairs[] = trim( $buffer );
		}

		return $pairs;
	}

	/**
	 * Find the first top-level colon in a key:value pair string.
	 *
	 * Ignores colons nested inside braces or brackets.
	 *
	 * @since 2.5.0
	 *
	 * @param string $pair The pair string (e.g. "by: reference_agent/gemini-2.5-pro").
	 * @return int|false Position of the colon, or false if none found.
	 */
	private function find_top_level_colon( $pair ) {
		$depth = 0;
		$len   = strlen( $pair );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $pair[ $i ];

			if ( '{' === $ch || '[' === $ch ) {
				++$depth;
			} elseif ( '}' === $ch || ']' === $ch ) {
				--$depth;
			} elseif ( ':' === $ch && 0 === $depth ) {
				return $i;
			}
		}

		return false;
	}

	/**
	 * Cast a YAML scalar value to the appropriate PHP type.
	 *
	 * Handles quoted strings, booleans, null, integers, and floats.
	 *
	 * @since 2.1.0
	 *
	 * @param string $value Raw scalar string.
	 * @return mixed
	 */
	private function cast_scalar( $value ) {
		// Strip surrounding quotes (single or double).
		if ( preg_match( '/^(["\'])(.*)\1$/', $value, $m ) ) {
			return $m[2];
		}

		// Boolean.
		if ( 'true' === strtolower( $value ) ) {
			return true;
		}
		if ( 'false' === strtolower( $value ) ) {
			return false;
		}

		// Null.
		if ( 'null' === strtolower( $value ) || '~' === $value ) {
			return null;
		}

		// Integer.
		if ( preg_match( '/^-?\d+$/', $value ) ) {
			return intval( $value );
		}

		// Float.
		if ( preg_match( '/^-?\d+\.\d+$/', $value ) ) {
			return floatval( $value );
		}

		// Default: string.
		return $value;
	}

	// -------------------------------------------------------------------------
	// Serialization (YAML output)
	// -------------------------------------------------------------------------

	/**
	 * Serialize an associative array to YAML frontmatter string.
	 *
	 * Produces a `---\n...\n---` block suitable for prepending to markdown.
	 * Handles nested arrays (lists of objects, inline mappings, nested maps).
	 *
	 * @since 2.1.0
	 * @since 2.5.0 — Extended to handle nested structures for OKF v0.2 fields.
	 *
	 * @param array $frontmatter Associative array of frontmatter fields.
	 * @return string YAML frontmatter block.
	 */
	public function serialize( array $frontmatter ) {
		$lines   = array( '---' );
		$lines[] = $this->serialize_mapping( $frontmatter, '' );
		$lines[] = '---';

		return implode( "\n", array_filter( $lines ) ) . "\n";
	}

	/**
	 * Serialize a mapping (associative array) at a given indent prefix.
	 *
	 * @since 2.5.0
	 *
	 * @param array  $map    Associative array.
	 * @param string $indent Indentation prefix string (e.g. "" or "  ").
	 * @return string Serialized YAML lines joined by \n.
	 */
	private function serialize_mapping( $map, $indent ) {
		$parts = array();

		foreach ( $map as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( $this->is_list_of_objects( $value ) ) {
					// List of objects: each item has its own indented keys.
					$parts[] = $indent . $key . ':';
					foreach ( $value as $item ) {
						$parts[] = $this->serialize_list_item_object( $item, $indent . '  ' );
					}
				} elseif ( $this->is_nested_map( $value ) ) {
					// Nested mapping (not a sequential list).
					$parts[] = $indent . $key . ':';
					$parts[] = $this->serialize_mapping( $value, $indent . '  ' );
				} else {
					// Simple list of scalars or inline mappings.
					$parts[] = $indent . $key . ':';
					foreach ( $value as $item ) {
						$parts[] = $indent . '  - ' . $this->value_to_yaml( $item );
					}
				}
			} else {
				$parts[] = $indent . $key . ': ' . $this->value_to_yaml( $value );
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Serialize a list item that is itself an object (hash).
	 *
	 * Outputs `- key1: value1` on the first line, then continuation keys
	 * on subsequent lines at a deeper indent.
	 *
	 * @since 2.5.0
	 *
	 * @param array  $item   Associative array for one list item.
	 * @param string $indent Indentation prefix for the `- ` line.
	 * @return string Serialized YAML lines.
	 */
	private function serialize_list_item_object( $item, $indent ) {
		$lines = array();
		$first = true;

		foreach ( $item as $k => $v ) {
			if ( $first ) {
				if ( is_array( $v ) ) {
					$lines[] = $indent . '- ' . $k . ':';
					$lines[] = $this->serialize_mapping( $v, $indent . '    ' );
				} else {
					$lines[] = $indent . '- ' . $k . ': ' . $this->value_to_yaml( $v );
				}
				$first = false;
			} elseif ( is_array( $v ) ) {
				// Continuation keys — indented deeper than the `- `.
				$lines[] = $indent . '  ' . $k . ':';
				$lines[] = $this->serialize_mapping( $v, $indent . '    ' );
			} else {
				$lines[] = $indent . '  ' . $k . ': ' . $this->value_to_yaml( $v );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Determine whether an array is a list of objects (indexed array of hashes).
	 *
	 * @since 2.5.0
	 *
	 * @param array $arr The array to inspect.
	 * @return bool
	 */
	private function is_list_of_objects( $arr ) {
		if ( ! $this->is_indexed_array( $arr ) || empty( $arr ) ) {
			return false;
		}
		// All items must be associative arrays.
		foreach ( $arr as $item ) {
			if ( ! is_array( $item ) || $this->is_indexed_array( $item ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Determine whether an array is a nested map (associative, not sequential).
	 *
	 * @since 2.5.0
	 *
	 * @param array $arr The array to inspect.
	 * @return bool
	 */
	private function is_nested_map( $arr ) {
		return is_array( $arr ) && ! $this->is_indexed_array( $arr );
	}

	/**
	 * Check whether an array has sequential integer keys (0, 1, 2, …).
	 *
	 * @since 2.5.0
	 *
	 * @param array $arr The array to check.
	 * @return bool
	 */
	private function is_indexed_array( $arr ) {
		if ( ! is_array( $arr ) || empty( $arr ) ) {
			return false;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}

	/**
	 * Convert any PHP value to its YAML string representation.
	 *
	 * Handles scalars, inline mappings (serialised as `{...}`), and inline
	 * sequences (serialised as `[...]`).
	 *
	 * @since 2.5.0 — Extended from scalar_to_yaml() to handle arrays.
	 *
	 * @param mixed $value The value to convert.
	 * @return string
	 */
	private function value_to_yaml( $value ) {
		if ( is_array( $value ) ) {
			if ( $this->is_indexed_array( $value ) ) {
				// Inline sequence: [a, b, c].
				$parts = array();
				foreach ( $value as $item ) {
					$parts[] = $this->scalar_to_yaml( $item );
				}
				return '[ ' . implode( ', ', $parts ) . ' ]';
			}

			// Inline mapping: { key: value, ... }.
			$parts = array();
			foreach ( $value as $k => $v ) {
				$parts[] = $k . ': ' . $this->scalar_to_yaml( $v );
			}
			return '{ ' . implode( ', ', $parts ) . ' }';
		}

		return $this->scalar_to_yaml( $value );
	}

	/**
	 * Convert a PHP scalar to YAML representation.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $value Scalar value.
	 * @return string
	 */
	private function scalar_to_yaml( $value ) {
		if ( is_string( $value ) && preg_match( '/[:#\{\}\[\],&*?!|>%@`]/', $value ) ) {
			return '"' . str_replace( '"', '\\"', $value ) . '"';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_null( $value ) ) {
			return 'null';
		}

		return (string) $value;
	}

	// -------------------------------------------------------------------------
	// Utility
	// -------------------------------------------------------------------------

	/**
	 * Get the leading whitespace count (indentation) of a line.
	 *
	 * @since 2.5.0
	 *
	 * @param string $line Raw line (with leading whitespace).
	 * @return int Number of leading space characters.
	 */
	private function get_indent( $line ) {
		return strlen( $line ) - strlen( ltrim( $line ) );
	}
}
