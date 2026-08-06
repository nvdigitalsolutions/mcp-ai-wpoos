<?php
/**
 * Paper Markdown + YAML Driver — Read/write .md files with YAML frontmatter.
 *
 * Parses Grav-style Markdown files with `---` delimited YAML frontmatter.
 * Uses symfony/yaml (vendored in Pro) for parsing, with a regex fallback.
 * PHP 8.1+ only (Pro addon).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return;
}

/**
 * Class WP_MCP_AI_Paper_Markdown_Yaml_Driver
 *
 * Implements the Paper Driver interface for `.md` files.
 * Frontmatter keys map to record fields: id, title, tags, status, type, etc.
 * Body becomes `body.markdown`.
 */
class WP_MCP_AI_Paper_Markdown_Yaml_Driver implements WP_MCP_AI_Paper_Driver_Interface {

	/**
	 * Required top-level keys.
	 *
	 * @var string[]
	 */
	private array $required_fields = array( 'id', 'title' );

	/**
	 * {@inheritdoc}
	 */
	public function get_extension(): string {
		return '.md';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $file_path Absolute path to the .md record file.
	 * @return array|WP_Error  Normalized record array, or WP_Error on failure.
	 */
	public function read( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'paper_file_not_found',
				sprintf(
					/* translators: %s: file path */
					__( 'Paper Store record file not found: %s', 'mcp-ai-wpoos-pro' ),
					basename( $file_path )
				)
			);
		}

		if ( ! is_readable( $file_path ) ) {
			return new WP_Error(
				'paper_file_unreadable',
				sprintf(
					/* translators: %s: file path */
					__( 'Paper Store record file is not readable: %s', 'mcp-ai-wpoos-pro' ),
					basename( $file_path )
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local plugin-managed flat file.
		$raw = file_get_contents( $file_path );

		if ( false === $raw ) {
			return new WP_Error(
				'paper_read_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Failed to read Paper Store record: %s', 'mcp-ai-wpoos-pro' ),
					basename( $file_path )
				)
			);
		}

		$parsed = $this->parse_frontmatter( $raw );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		// Validate required fields.
		$missing = array();
		foreach ( $this->required_fields as $field ) {
			if ( ! isset( $parsed['meta'][ $field ] ) || '' === $parsed['meta'][ $field ] ) {
				$missing[] = $field;
			}
		}

		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'paper_missing_fields',
				sprintf(
					/* translators: 1: file path, 2: comma-separated field names */
					__( 'Record "%1$s" is missing required fields: %2$s', 'mcp-ai-wpoos-pro' ),
					basename( $file_path ),
					implode( ', ', $missing )
				)
			);
		}

		// Build normalized record.
		$record = $parsed['meta'];

		// Auto-set type from filename directory if not in frontmatter.
		if ( empty( $record['type'] ) ) {
			$parent_dir     = basename( dirname( $file_path ) );
			$record['type'] = sanitize_key( $parent_dir );
		}

		// Parse tags from comma-separated string or YAML list.
		if ( isset( $record['tags'] ) && is_string( $record['tags'] ) ) {
			$tags           = array_map( 'trim', explode( ',', $record['tags'] ) );
			$record['tags'] = array_filter( $tags );
		}

		// Set body as markdown content.
		if ( ! empty( $parsed['body'] ) ) {
			$record['body'] = array( 'markdown' => $parsed['body'] );
		}

		return $record;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $file_path Absolute path to the .md record file.
	 * @param array  $record    Normalized record array.
	 * @return bool|WP_Error    True on success, WP_Error on failure.
	 */
	public function write( $file_path, array $record ) {
		// Validate required fields.
		$missing = array();
		foreach ( $this->required_fields as $field ) {
			if ( ! isset( $record[ $field ] ) || '' === $record[ $field ] ) {
				$missing[] = $field;
			}
		}

		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'paper_missing_fields',
				sprintf(
					/* translators: %s: comma-separated field names */
					__( 'Record is missing required fields: %s', 'mcp-ai-wpoos-pro' ),
					implode( ', ', $missing )
				)
			);
		}

		$markdown = $this->build_markdown( $record );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Managed flat-file store.
		$result = file_put_contents( $file_path, $markdown, LOCK_EX );

		if ( false === $result ) {
			return new WP_Error(
				'paper_write_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Failed to write Paper Store record: %s', 'mcp-ai-wpoos-pro' ),
					basename( $file_path )
				)
			);
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $file_path Absolute path to the .md record file.
	 * @return bool|WP_Error    True on success, WP_Error on failure.
	 */
	public function delete( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'paper_file_not_found',
				sprintf(
					/* translators: %s: file path */
					__( 'Paper Store record file not found: %s', 'mcp-ai-wpoos-pro' ),
					basename( $file_path )
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Managed flat-file store deletion.
		if ( ! unlink( $file_path ) ) {
			return new WP_Error(
				'paper_delete_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Failed to delete Paper Store record: %s', 'mcp-ai-wpoos-pro' ),
					basename( $file_path )
				)
			);
		}

		return true;
	}

	/**
	 * Parse YAML frontmatter from markdown content.
	 *
	 * Extracts `---` delimited frontmatter and body.
	 * Uses symfony/yaml when available, with a regex fallback.
	 *
	 * @param string $content Raw markdown content.
	 * @return array|WP_Error  array( 'meta' => array, 'body' => string ) or WP_Error.
	 */
	private function parse_frontmatter( string $content ) {
		// Match frontmatter between --- delimiters.
		if ( ! preg_match( '/^---\s*\n(.*?)\n---\s*\n?(.*)$/s', $content, $matches ) ) {
			return new WP_Error(
				'paper_no_frontmatter',
				__( 'No YAML frontmatter found in markdown file.', 'mcp-ai-wpoos-pro' )
			);
		}

		$yaml_string = $matches[1];
		$body        = trim( $matches[2] );

		// Try Symfony YAML first.
		$meta = $this->parse_yaml_symfony( $yaml_string );

		// Fallback to regex parser if Symfony fails or isn't available.
		if ( is_wp_error( $meta ) || null === $meta ) {
			$meta = $this->parse_yaml_regex( $yaml_string );
		}

		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		return array(
			'meta' => $meta,
			'body' => $body,
		);
	}

	/**
	 * Parse YAML using symfony/yaml if available.
	 *
	 * @param string $yaml YAML content.
	 * @return array|WP_Error|null Parsed array, WP_Error on failure, or null if not available.
	 */
	private function parse_yaml_symfony( string $yaml ) {
		if ( ! class_exists( 'Symfony\\Component\\Yaml\\Yaml' ) ) {
			return null;
		}

		try {
			$parsed = Symfony\Component\Yaml\Yaml::parse( $yaml );

			if ( ! is_array( $parsed ) ) {
				return new WP_Error(
					'paper_yaml_parse_failed',
					__( 'YAML frontmatter did not parse to an array.', 'mcp-ai-wpoos-pro' )
				);
			}

			return $parsed;
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'paper_yaml_parse_error',
				sprintf(
					/* translators: %s: error message */
					__( 'YAML parse error: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Parse YAML using the built-in regex fallback parser.
	 *
	 * Handles basic YAML structures: scalars, lists, nested maps,
	 * inline arrays, booleans, and numbers. Used when symfony/yaml
	 * is not available.
	 *
	 * @param string $yaml YAML content.
	 * @return array|WP_Error
	 */
	private function parse_yaml_regex( string $yaml ) {
		return $this->parse_yaml_map( $yaml );
	}

	/**
	 * Parse a YAML mapping from a string.
	 *
	 * @param string $yaml YAML content.
	 * @return array|WP_Error
	 */
	private function parse_yaml_map( string $yaml ) {
		$result = array();
		$lines  = explode( "\n", $yaml );
		$i      = 0;
		$count  = count( $lines );

		while ( $i < $count ) {
			$line = $lines[ $i ];

			// Skip empty lines and comments.
			if ( '' === trim( $line ) || '#' === ltrim( $line )[0] ) {
				++$i;
				continue;
			}

			// Match key: value.
			if ( preg_match( '/^(\s*)([\w-]+)\s*:\s*(.*)$/', $line, $m ) ) {
				$indent = strlen( $m[1] );
				$key    = sanitize_key( $m[2] );
				$value  = trim( $m[3] );

				if ( '' === $value ) {
					// Could be a nested map or list starting on next line.
					$nested_lines = array();
					++$i;
					while ( $i < $count ) {
						$next_line = $lines[ $i ];
						if ( '' === trim( $next_line ) ) {
							++$i;
							continue;
						}
						// Check if this line is indented more than the key.
						if ( preg_match( '/^(\s+)(\S)/', $next_line, $nm ) ) {
							$next_indent = strlen( $nm[1] );
							if ( $next_indent > $indent ) {
								$nested_lines[] = $next_line;
								++$i;
								continue;
							}
						}
						break;
					}

					if ( ! empty( $nested_lines ) ) {
						$nested_yaml = implode( "\n", $nested_lines );

						// Check if this is a list (lines starting with -).
						if ( preg_match( '/^\s*-/', $nested_lines[0] ) ) {
							$result[ $key ] = $this->parse_yaml_list( $nested_yaml );
						} else {
							$nested = $this->parse_yaml_map( $nested_yaml );
							if ( ! is_wp_error( $nested ) ) {
								$result[ $key ] = $nested;
							}
						}
					}
					continue;
				}

				// Inline list: [item1, item2].
				if ( preg_match( '/^\[(.*)\]$/', $value, $lm ) ) {
					$items          = array_map(
						function ( $item ) {
							return trim( trim( $item ), '"\'' );
						},
						explode( ',', $lm[1] )
					);
					$result[ $key ] = array_filter( $items );
				} elseif ( 'true' === strtolower( $value ) ) {
					$result[ $key ] = true;
				} elseif ( 'false' === strtolower( $value ) ) {
					$result[ $key ] = false;
				} elseif ( is_numeric( $value ) ) {
					$result[ $key ] = strpos( $value, '.' ) !== false ? (float) $value : (int) $value;
				} else {
					// Strip surrounding quotes.
					$value          = trim( $value, '"\' ' );
					$result[ $key ] = sanitize_text_field( $value );
				}
			}

			++$i;
		}

		return $result;
	}

	/**
	 * Parse a YAML list (lines starting with -).
	 *
	 * @param string $yaml YAML list content.
	 * @return array
	 */
	private function parse_yaml_list( string $yaml ): array {
		$items = array();
		$lines = explode( "\n", $yaml );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || ! str_starts_with( $line, '-' ) ) {
				continue;
			}
			$value = trim( substr( $line, 1 ) );
			$value = trim( $value, '"\' ' );
			if ( '' !== $value ) {
				$items[] = sanitize_text_field( $value );
			}
		}

		return $items;
	}

	/**
	 * Build markdown content from a normalized record.
	 *
	 * @param array $record Normalized record array.
	 * @return string Markdown with YAML frontmatter.
	 */
	private function build_markdown( array $record ): string {
		$lines = array();

		$lines[] = '---';

		// Frontmatter fields.
		$frontmatter_fields = array( 'id', 'title', 'type', 'description', 'status' );

		foreach ( $frontmatter_fields as $field ) {
			if ( isset( $record[ $field ] ) && '' !== $record[ $field ] ) {
				$lines[] = $field . ': ' . $record[ $field ];
			}
		}

		// Tags as YAML inline list.
		if ( ! empty( $record['tags'] ) && is_array( $record['tags'] ) ) {
			$quoted  = array_map(
				function ( $tag ) {
					return '"' . $tag . '"';
				},
				$record['tags']
			);
			$lines[] = 'tags: [' . implode( ', ', $quoted ) . ']';
		}

		// Timestamps.
		if ( isset( $record['created_at'] ) ) {
			$lines[] = 'created_at: "' . $record['created_at'] . '"';
		}
		if ( isset( $record['updated_at'] ) ) {
			$lines[] = 'updated_at: "' . $record['updated_at'] . '"';
		}

		// Custom meta fields.
		if ( isset( $record['meta'] ) && is_array( $record['meta'] ) ) {
			foreach ( $record['meta'] as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$lines[] = $key . ': ' . $value;
				}
			}
		}

		$lines[] = '---';
		$lines[] = '';

		// Body content.
		if ( isset( $record['body']['markdown'] ) && is_string( $record['body']['markdown'] ) ) {
			$lines[] = $record['body']['markdown'];
		} elseif ( isset( $record['body'] ) && is_string( $record['body'] ) ) {
			$lines[] = $record['body'];
		}

		return implode( "\n", $lines ) . "\n";
	}
}
