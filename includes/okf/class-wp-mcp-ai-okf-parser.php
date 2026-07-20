<?php
/**
 * OKF Parser — Minimal YAML frontmatter parser for OKF concept documents.
 *
 * Parses the lightweight YAML frontmatter subset required by the OKF v0.1
 * specification: scalars, lists, and key-value pairs. Does not handle
 * anchors, aliases, multi-line strings (|, >), or flow mappings — none of
 * which are needed for OKF frontmatter.
 *
 * @package WP_MCP_AI
 * @since   2.1.0
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

		$frontmatter = $this->parse_yaml_lines( $yaml_block );
		if ( is_wp_error( $frontmatter ) ) {
			return $frontmatter;
		}

		return array(
			'frontmatter' => $frontmatter,
			'body'        => $body,
		);
	}

	/**
	 * Parse a YAML block into an associative array.
	 *
	 * Handles the OKF subset: scalars, lists, and flat key-value pairs.
	 *
	 * @since 2.1.0
	 *
	 * @param string $yaml Raw YAML content (between the --- delimiters).
	 * @return array|WP_Error
	 */
	private function parse_yaml_lines( $yaml ) {
		$result   = array();
		$lines    = explode( "\n", $yaml );
		$in_list  = false;
		$list_key = '';

		foreach ( $lines as $line ) {
			$line = rtrim( $line );

			// Skip empty lines and comments.
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}

			// List item continuation.
			if ( $in_list && preg_match( '/^\s*-\s+(.+)$/', $line, $m ) ) {
				$result[ $list_key ][] = $this->cast_scalar( trim( $m[1] ) );
				continue;
			}

			// Key-value pair.
			if ( preg_match( '/^(\w[\w\s]*?):\s*(.*)$/', $line, $m ) ) {
				$in_list = false;
				$key     = trim( $m[1] );
				$value   = trim( $m[2] );

				// Start of a YAML list (value is empty, next lines have `- item`).
				if ( '' === $value ) {
					$in_list  = true;
					$list_key = $key;
					$result[ $key ] = array();
					continue;
				}

				$result[ $key ] = $this->cast_scalar( $value );
				continue;
			}
		}

		return $result;
	}

	/**
	 * Cast a YAML scalar value to the appropriate PHP type.
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

	/**
	 * Serialize an associative array to YAML frontmatter string.
	 *
	 * Produces a `---\n...\n---` block suitable for prepending to markdown.
	 *
	 * @since 2.1.0
	 *
	 * @param array $frontmatter Associative array of frontmatter fields.
	 * @return string YAML frontmatter block.
	 */
	public function serialize( array $frontmatter ) {
		$lines = array( '---' );

		foreach ( $frontmatter as $key => $value ) {
			if ( is_array( $value ) ) {
				// YAML list.
				$lines[] = $key . ':';
				foreach ( $value as $item ) {
					$lines[] = '  - ' . $this->scalar_to_yaml( $item );
				}
			} else {
				$lines[] = $key . ': ' . $this->scalar_to_yaml( $value );
			}
		}

		$lines[] = '---';

		return implode( "\n", $lines ) . "\n";
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
}
