<?php
/**
 * OKF Reader — Navigate and read OKF knowledge bundles.
 *
 * Provides deterministic, link-based navigation of OKF v0.2 bundles.
 * Reads concepts on demand (lazy loading), resolves cross-links, and
 * supports traversal of the knowledge graph up to a configurable depth.
 *
 * @package WP_MCP_AI
 * @since   2.1.0
 * @since   2.5.0 — Extended to support OKF v0.2 trust-signal fields
 *                (status, stale_after, generated, verified) in search.
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
 * OKF bundle reader and navigator.
 *
 * @since 2.1.0
 */
class WP_MCP_AI_OKF_Reader {

	/**
	 * Parser instance.
	 *
	 * @since 2.1.0
	 * @var WP_MCP_AI_OKF_Parser
	 */
	private $parser;

	/**
	 * Bundle root path on disk.
	 *
	 * @since 2.1.0
	 * @var string
	 */
	private $bundle_root;

	/**
	 * Cache of parsed concepts keyed by concept ID.
	 *
	 * @since 2.1.0
	 * @var array<string, array{frontmatter: array, body: string}>
	 */
	private $concept_cache = array();

	/**
	 * Constructor.
	 *
	 * @since 2.1.0
	 *
	 * @param string $bundle_root Absolute path to the bundle root directory.
	 */
	public function __construct( $bundle_root ) {
		$this->parser      = new WP_MCP_AI_OKF_Parser();
		$this->bundle_root = untrailingslashit( $bundle_root );
	}

	/**
	 * Get the bundle root path.
	 *
	 * @since 2.1.0
	 * @return string
	 */
	public function get_bundle_root() {
		return $this->bundle_root;
	}

	/**
	 * Read a single concept by its concept ID.
	 *
	 * The concept ID is the file path relative to the bundle root, with the
	 * `.md` suffix removed (e.g. `tables/orders` for `tables/orders.md`).
	 *
	 * Returns an array with 'concept_id', 'frontmatter', and 'body' keys.
	 * Returns a WP_Error if the concept is not found or cannot be parsed.
	 *
	 * @since 2.1.0
	 *
	 * @param string $concept_id Concept ID (path without .md).
	 * @return array|WP_Error
	 */
	public function get_concept( $concept_id ) {
		$concept_id = $this->normalize_concept_id( $concept_id );

		// Return cached if available.
		if ( isset( $this->concept_cache[ $concept_id ] ) ) {
			return array_merge(
				array( 'concept_id' => $concept_id ),
				$this->concept_cache[ $concept_id ]
			);
		}

		$file_path = $this->resolve_file_path( $concept_id );
		if ( is_wp_error( $file_path ) ) {
			return $file_path;
		}

		$content = $this->read_file( $file_path );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$parsed = $this->parser->parse( $content );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( null === $parsed ) {
			return new WP_Error(
				'okf_no_frontmatter',
				sprintf(
					/* translators: %s: concept ID */
					__( 'Concept "%s" has no YAML frontmatter block.', 'mcp-ai-wpoos' ),
					$concept_id
				)
			);
		}

		// Cache the result.
		$this->concept_cache[ $concept_id ] = array(
			'frontmatter' => $parsed['frontmatter'],
			'body'        => $parsed['body'],
		);

		return array(
			'concept_id'  => $concept_id,
			'frontmatter' => $parsed['frontmatter'],
			'body'        => $parsed['body'],
		);
	}

	/**
	 * Browse a directory in the bundle via its index.md.
	 *
	 * Returns a list of entries (concepts and subdirectories) from the index
	 * file at the given path. Falls back to scanning the directory if no
	 * index.md exists.
	 *
	 * @since 2.1.0
	 *
	 * @param string $path Directory path relative to bundle root (empty string for root).
	 * @return array|WP_Error Array of entries with 'title', 'path', and 'description' keys.
	 */
	public function browse( $path = '' ) {
		$dir_path = $this->bundle_root;
		if ( '' !== $path ) {
			$dir_path .= '/' . ltrim( $path, '/' );
		}

		if ( ! is_dir( $dir_path ) ) {
			return new WP_Error(
				'okf_not_found',
				sprintf(
					/* translators: %s: directory path */
					__( 'OKF directory not found: %s', 'mcp-ai-wpoos' ),
					$path
				)
			);
		}

		// Try index.md first.
		$index_path = $dir_path . '/index.md';
		if ( file_exists( $index_path ) ) {
			$content = $this->read_file( $index_path );
			if ( ! is_wp_error( $content ) ) {
				return $this->parse_index_content( $content );
			}
		}

		// Fallback: scan directory.
		return $this->scan_directory( $dir_path, $path );
	}

	/**
	 * Traverse the knowledge graph starting from a concept, following
	 * cross-links up to the specified depth.
	 *
	 * Returns a nested array representing the subgraph of traversed concepts.
	 *
	 * @since 2.1.0
	 *
	 * @param string $concept_id Starting concept ID.
	 * @param int    $max_depth  Maximum link-following depth (default 2).
	 * @return array|WP_Error
	 */
	public function traverse( $concept_id, $max_depth = 2 ) {
		$visited   = array();
		$max_depth = max( 1, min( 5, absint( $max_depth ) ) );

		return $this->traverse_recursive( $concept_id, $max_depth, $visited );
	}

	/**
	 * Recursive traversal helper.
	 *
	 * @since 2.1.0
	 *
	 * @param string   $concept_id Current concept ID.
	 * @param int      $depth      Remaining depth.
	 * @param string[] $visited    Set of already-visited concept IDs.
	 * @return array
	 */
	private function traverse_recursive( $concept_id, $depth, &$visited ) {
		if ( in_array( $concept_id, $visited, true ) || $depth < 0 ) {
			return null;
		}

		$visited[] = $concept_id;

		$concept = $this->get_concept( $concept_id );
		if ( is_wp_error( $concept ) ) {
			return array(
				'concept_id' => $concept_id,
				'error'      => $concept->get_error_message(),
				'links'      => array(),
			);
		}

		$concept['links'] = array();

		if ( $depth > 0 ) {
			$linked_ids = $this->extract_concept_links( $concept['body'] );
			foreach ( $linked_ids as $linked_id ) {
				$child = $this->traverse_recursive( $linked_id, $depth - 1, $visited );
				if ( null !== $child ) {
					$concept['links'][] = $child;
				}
			}
		}

		return $concept;
	}

	/**
	 * Derive a trust tier from a concept's `verified` field.
	 *
	 * OKF v0.2 defines three advisory tiers:
	 * - `human-reviewed` — at least one `verified` entry with a `human:…` actor.
	 * - `machine-confirmed` — `verified` entries exist but none are human.
	 * - `unverified` — no `verified` key present.
	 *
	 * @since 2.5.0
	 *
	 * @param array $frontmatter Parsed frontmatter of a concept.
	 * @return string One of 'human-reviewed', 'machine-confirmed', 'unverified'.
	 */
	public function get_trust_tier( array $frontmatter ) {
		if ( ! isset( $frontmatter['verified'] ) || ! is_array( $frontmatter['verified'] ) ) {
			return 'unverified';
		}

		foreach ( $frontmatter['verified'] as $verification ) {
			if ( ! is_array( $verification ) ) {
				continue;
			}
			$by = isset( $verification['by'] ) ? (string) $verification['by'] : '';
			if ( 0 === strpos( $by, 'human:' ) ) {
				return 'human-reviewed';
			}
		}

		return 'machine-confirmed';
	}

	/**
	 * Check whether a concept is stale (past its `stale_after` date).
	 *
	 * @since 2.5.0
	 *
	 * @param array $frontmatter Parsed frontmatter.
	 * @return bool True if the concept has a stale_after date in the past.
	 */
	public function is_stale( array $frontmatter ) {
		if ( empty( $frontmatter['stale_after'] ) ) {
			return false;
		}

		$deadline = strtotime( (string) $frontmatter['stale_after'] );
		return false !== $deadline && $deadline < time();
	}

	/**
	 * Search concepts by type, tag, status, trust tier, and staleness.
	 *
	 * Scans the bundle directory tree for concepts matching the criteria.
	 *
	 * @since 2.1.0
	 * @since 2.5.0 — Added 'status', 'trust_tier', and 'include_stale' criteria.
	 *
	 * @param array $criteria Search criteria with optional keys:
	 *                        'type'         — concept type string.
	 *                        'tag'          — single tag string.
	 *                        'status'       — 'draft', 'stable', or 'deprecated' (absent = 'stable').
	 *                        'trust_tier'   — 'unverified', 'machine-confirmed', or 'human-reviewed'.
	 *                        'include_stale' — bool, include concepts past stale_after (default true).
	 * @return array Array of matching concept summaries.
	 */
	public function search( array $criteria = array() ) {
		$results       = array();
		$want_type     = isset( $criteria['type'] ) ? sanitize_text_field( $criteria['type'] ) : null;
		$want_tag      = isset( $criteria['tag'] ) ? sanitize_text_field( $criteria['tag'] ) : null;
		$want_status   = isset( $criteria['status'] ) ? sanitize_text_field( $criteria['status'] ) : null;
		$want_tier     = isset( $criteria['trust_tier'] ) ? sanitize_text_field( $criteria['trust_tier'] ) : null;
		$include_stale = isset( $criteria['include_stale'] ) ? (bool) $criteria['include_stale'] : true;

		$files = $this->find_all_concept_files( $this->bundle_root );
		foreach ( $files as $file_path ) {
			$concept_id = $this->file_to_concept_id( $file_path );
			$concept    = $this->get_concept( $concept_id );

			if ( is_wp_error( $concept ) ) {
				continue;
			}

			$fm = $concept['frontmatter'];

			// Filter by type.
			if ( null !== $want_type ) {
				$concept_type = isset( $fm['type'] ) ? $fm['type'] : '';
				if ( strtolower( $concept_type ) !== strtolower( $want_type ) ) {
					continue;
				}
			}

			// Filter by tag.
			if ( null !== $want_tag ) {
				$tags = isset( $fm['tags'] ) ? $fm['tags'] : array();
				if ( ! is_array( $tags ) ) {
					$tags = array( $tags );
				}
				$found = false;
				foreach ( $tags as $tag ) {
					if ( strtolower( $tag ) === strtolower( $want_tag ) ) {
						$found = true;
						break;
					}
				}
				if ( ! $found ) {
					continue;
				}
			}

			// Filter by status (OKF v0.2).
			if ( null !== $want_status ) {
				$concept_status = isset( $fm['status'] ) ? $fm['status'] : 'stable';
				if ( strtolower( $concept_status ) !== strtolower( $want_status ) ) {
					continue;
				}
			}

			// Filter by trust tier (OKF v0.2).
			if ( null !== $want_tier ) {
				$tier = $this->get_trust_tier( $fm );
				if ( $tier !== $want_tier ) {
					continue;
				}
			}

			// Exclude stale concepts when requested (OKF v0.2).
			if ( ! $include_stale && $this->is_stale( $fm ) ) {
				continue;
			}

			$results[] = array(
				'concept_id'  => $concept_id,
				'type'        => isset( $fm['type'] ) ? $fm['type'] : '',
				'title'       => isset( $fm['title'] ) ? $fm['title'] : '',
				'description' => isset( $fm['description'] ) ? $fm['description'] : '',
				'tags'        => isset( $fm['tags'] ) ? $fm['tags'] : array(),
				'status'      => isset( $fm['status'] ) ? $fm['status'] : 'stable',
				'trust_tier'  => $this->get_trust_tier( $fm ),
				'stale'       => $this->is_stale( $fm ),
			);
		}

		return $results;
	}

	/**
	 * Get all unique type values used in the bundle.
	 *
	 * @since 2.1.0
	 * @return array Array of type strings.
	 */
	public function get_types() {
		$types = array();
		$files = $this->find_all_concept_files( $this->bundle_root );

		foreach ( $files as $file_path ) {
			$concept_id = $this->file_to_concept_id( $file_path );
			$concept    = $this->get_concept( $concept_id );

			if ( is_wp_error( $concept ) || ! isset( $concept['frontmatter']['type'] ) ) {
				continue;
			}

			$type = $concept['frontmatter']['type'];
			if ( ! in_array( $type, $types, true ) ) {
				$types[] = $type;
			}
		}

		sort( $types );
		return $types;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalize a concept ID (strip .md suffix, trim slashes).
	 *
	 * @since 2.1.0
	 *
	 * @param string $concept_id Raw concept ID.
	 * @return string
	 */
	private function normalize_concept_id( $concept_id ) {
		$concept_id = trim( $concept_id, '/' );
		// Strip .md suffix if present.
		if ( '.md' === substr( $concept_id, -3 ) ) {
			$concept_id = substr( $concept_id, 0, -3 );
		}
		return $concept_id;
	}

	/**
	 * Resolve a concept ID to an absolute file path.
	 *
	 * @since 2.1.0
	 *
	 * @param string $concept_id Concept ID.
	 * @return string|WP_Error
	 */
	private function resolve_file_path( $concept_id ) {
		// Handle bundle-relative absolute links: /tables/orders → tables/orders.
		$concept_id = ltrim( $concept_id, '/' );

		$file_path = $this->bundle_root . '/' . $concept_id . '.md';

		// Normalize path separators and prevent directory traversal.
		$file_path = wp_normalize_path( $file_path );

		// Security: ensure the resolved path is still within the bundle root.
		$normalized_root = wp_normalize_path( $this->bundle_root );
		if ( 0 !== strpos( $file_path, $normalized_root ) ) {
			return new WP_Error(
				'okf_path_traversal',
				__( 'Concept path escapes the bundle root.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'okf_not_found',
				sprintf(
					/* translators: %s: concept ID */
					__( 'Concept not found: %s', 'mcp-ai-wpoos' ),
					$concept_id
				)
			);
		}

		return $file_path;
	}

	/**
	 * Read a file with error handling.
	 *
	 * @since 2.1.0
	 *
	 * @param string $path Absolute file path.
	 * @return string|WP_Error
	 */
	private function read_file( $path ) {
		if ( ! is_readable( $path ) ) {
			return new WP_Error(
				'okf_unreadable',
				sprintf(
					/* translators: %s: file path */
					__( 'OKF file is not readable: %s', 'mcp-ai-wpoos' ),
					basename( $path )
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read for OKF bundle; no remote URL involved.
		$content = file_get_contents( $path );
		if ( false === $content ) {
			return new WP_Error(
				'okf_read_error',
				sprintf(
					/* translators: %s: file path */
					__( 'Failed to read OKF file: %s', 'mcp-ai-wpoos' ),
					basename( $path )
				)
			);
		}

		return $content;
	}

	/**
	 * Convert an absolute file path to a concept ID.
	 *
	 * @since 2.1.0
	 *
	 * @param string $file_path Absolute file path.
	 * @return string
	 */
	private function file_to_concept_id( $file_path ) {
		$relative = str_replace( wp_normalize_path( $this->bundle_root ) . '/', '', wp_normalize_path( $file_path ) );
		if ( '.md' === substr( $relative, -3 ) ) {
			$relative = substr( $relative, 0, -3 );
		}
		return $relative;
	}

	/**
	 * Extract concept cross-links from markdown body text.
	 *
	 * Matches `[text](path.md)` patterns where the path ends in `.md`.
	 *
	 * @since 2.1.0
	 *
	 * @param string $body Markdown body text.
	 * @return string[] Array of concept IDs (without .md suffix).
	 */
	private function extract_concept_links( $body ) {
		$links = array();
		if ( preg_match_all( '/\[([^\]]*)\]\(([^)]+\.md)\)/', $body, $matches ) ) {
			foreach ( $matches[2] as $link_path ) {
				// Remove anchor fragments.
				$link_path = preg_replace( '/#.*$/', '', $link_path );

				// Resolve relative paths against the bundle root.
				$concept_id = ltrim( $link_path, '/' );
				$concept_id = $this->normalize_concept_id( $concept_id );

				// Remove leading ../ sequences.
				$concept_id = preg_replace( '#^(\.\./)+#', '', $concept_id );

				$links[] = $concept_id;
			}
		}
		return array_unique( $links );
	}

	/**
	 * Parse an index.md file into a browse result.
	 *
	 * @since 2.1.0
	 *
	 * @param string $content Raw index.md content.
	 * @return array
	 */
	private function parse_index_content( $content ) {
		$entries = array();
		$lines   = explode( "\n", $content );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			// Match bullet list items with links: `* [Title](path) - description`.
			if ( preg_match( '/^\*?\s*\[([^\]]+)\]\(([^)]+)\)\s*-?\s*(.*)$/', $line, $m ) ) {
				$entries[] = array(
					'title'       => trim( $m[1] ),
					'path'        => trim( $m[2] ),
					'description' => trim( $m[3] ),
				);
			}
		}

		return $entries;
	}

	/**
	 * Scan a directory for concepts and subdirectories (fallback when no index.md).
	 *
	 * @since 2.1.0
	 *
	 * @param string $dir_path Absolute directory path.
	 * @param string $rel_path Relative directory path for output.
	 * @return array
	 */
	private function scan_directory( $dir_path, $rel_path ) {
		$entries = array();
		$items   = scandir( $dir_path );

		if ( false === $items ) {
			return $entries;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$full_path = $dir_path . '/' . $item;
			$rel_item  = ( '' === $rel_path ) ? $item : $rel_path . '/' . $item;

			if ( is_dir( $full_path ) ) {
				$entries[] = array(
					'title'       => basename( $item ),
					'path'        => $rel_item . '/',
					'description' => '',
				);
			} elseif ( '.md' === substr( $item, -3 )
				&& 'index.md' !== $item
				&& 'log.md' !== $item ) {
				// Try to read the title from frontmatter.
				$concept_id = $this->file_to_concept_id( $full_path );
				$concept    = $this->get_concept( $concept_id );
				$title      = $item;
				$desc       = '';

				if ( ! is_wp_error( $concept ) ) {
					$title = isset( $concept['frontmatter']['title'] )
						? $concept['frontmatter']['title']
						: $item;
					$desc  = isset( $concept['frontmatter']['description'] )
						? $concept['frontmatter']['description']
						: '';
				}

				$entries[] = array(
					'title'       => $title,
					'path'        => $rel_item,
					'description' => $desc,
				);
			}
		}

		return $entries;
	}

	/**
	 * Find all .md concept files recursively in a directory tree.
	 *
	 * Skips index.md and log.md (reserved filenames per spec §3.1).
	 *
	 * @since 2.1.0
	 *
	 * @param string $dir Absolute directory path.
	 * @return string[] Array of absolute file paths.
	 */
	private function find_all_concept_files( $dir ) {
		$files    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info->isFile() ) {
				continue;
			}

			$filename = $file_info->getFilename();
			if ( '.md' !== substr( $filename, -3 ) ) {
				continue;
			}

			// Skip reserved filenames.
			if ( 'index.md' === $filename || 'log.md' === $filename ) {
				continue;
			}

			$files[] = wp_normalize_path( $file_info->getPathname() );
		}

		return $files;
	}

	/**
	 * Clear the concept cache.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	public function clear_cache() {
		$this->concept_cache = array();
	}
}
