<?php
/**
 * OKF reader (Wave E6, sub-cluster 4).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_OKF_Reader`
 * (`includes/okf/class-wp-mcp-ai-okf-reader.php`): byte-identical
 * bundle navigation — lazy concept reading with the per-reader cache,
 * the `okf_no_frontmatter` / `okf_not_found` / `okf_unreadable` /
 * `okf_read_error` / `okf_path_traversal` error codes, the lexical +
 * symlink-aware realpath containment on concept resolution, the
 * `browse()` index.md-or-scan fallback, the depth-clamped recursive
 * `traverse()` with cross-link extraction, the OKF v0.2 trust-tier
 * and staleness helpers, the multi-criteria `search()` summary shape,
 * `get_types()`, the public `file_to_concept_id()` path utility, and
 * the advisory `find_broken_links()` report.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - `WP_Error` is fully qualified.
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\Okf
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\Okf;

/**
 * OKF bundle reader and navigator.
 *
 * @since 1.1.0
 */
class OkfReader {

	/**
	 * Parser instance.
	 *
	 * @var OkfParser
	 */
	private $parser;

	/**
	 * Bundle root path on disk.
	 *
	 * @var string
	 */
	private $bundle_root;

	/**
	 * Cache of parsed concepts keyed by concept ID.
	 *
	 * @var array<string, array{frontmatter: array, body: string}>
	 */
	private $concept_cache = array();

	/**
	 * Constructor.
	 *
	 * @param string $bundle_root Absolute path to the bundle root directory.
	 */
	public function __construct( $bundle_root ) {
		$this->parser      = new OkfParser();
		$this->bundle_root = \untrailingslashit( $bundle_root );
	}

	/**
	 * Get the bundle root path.
	 *
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
	 * @param string $concept_id Concept ID (path without .md).
	 * @return array|\WP_Error
	 */
	public function get_concept( $concept_id ) {
		$concept_id = $this->normalize_concept_id( $concept_id );

		// Return cached if available.
		if ( isset( $this->concept_cache[ $concept_id ] ) ) {
			return \array_merge(
				array( 'concept_id' => $concept_id ),
				$this->concept_cache[ $concept_id ]
			);
		}

		$file_path = $this->resolve_file_path( $concept_id );
		if ( \is_wp_error( $file_path ) ) {
			return $file_path;
		}

		$content = $this->read_file( $file_path );
		if ( \is_wp_error( $content ) ) {
			return $content;
		}

		$parsed = $this->parser->parse( $content );
		if ( \is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( null === $parsed ) {
			return new \WP_Error(
				'okf_no_frontmatter',
				\sprintf(
					/* translators: %s: concept ID */
					__( 'Concept "%s" has no YAML frontmatter block.', 'nvoos-content-graph-ai' ),
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
	 * @param string $path Directory path relative to bundle root (empty string for root).
	 * @return array|\WP_Error Array of entries with 'title', 'path', and 'description' keys.
	 */
	public function browse( $path = '' ) {
		$dir_path = $this->bundle_root;
		if ( '' !== $path ) {
			$dir_path .= '/' . \ltrim( $path, '/' );
		}

		if ( ! \is_dir( $dir_path ) ) {
			return new \WP_Error(
				'okf_not_found',
				\sprintf(
					/* translators: %s: directory path */
					__( 'OKF directory not found: %s', 'nvoos-content-graph-ai' ),
					$path
				)
			);
		}

		// Try index.md first.
		$index_path = $dir_path . '/index.md';
		if ( \file_exists( $index_path ) ) {
			$content = $this->read_file( $index_path );
			if ( ! \is_wp_error( $content ) ) {
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
	 * @param string $concept_id Starting concept ID.
	 * @param int    $max_depth  Maximum link-following depth (default 2).
	 * @return array|\WP_Error
	 */
	public function traverse( $concept_id, $max_depth = 2 ) {
		$visited   = array();
		$max_depth = \max( 1, \min( 5, \absint( $max_depth ) ) );

		return $this->traverse_recursive( $concept_id, $max_depth, $visited );
	}

	/**
	 * Recursive traversal helper.
	 *
	 * @param string   $concept_id Current concept ID.
	 * @param int      $depth      Remaining depth.
	 * @param string[] $visited    Set of already-visited concept IDs.
	 * @return array|null
	 */
	private function traverse_recursive( $concept_id, $depth, &$visited ) {
		if ( \in_array( $concept_id, $visited, true ) || $depth < 0 ) {
			return null;
		}

		$visited[] = $concept_id;

		$concept = $this->get_concept( $concept_id );
		if ( \is_wp_error( $concept ) ) {
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
	 * @param array $frontmatter Parsed frontmatter of a concept.
	 * @return string One of 'human-reviewed', 'machine-confirmed', 'unverified'.
	 */
	public function get_trust_tier( array $frontmatter ) {
		if ( ! isset( $frontmatter['verified'] ) || ! \is_array( $frontmatter['verified'] ) ) {
			return 'unverified';
		}

		foreach ( $frontmatter['verified'] as $verification ) {
			if ( ! \is_array( $verification ) ) {
				continue;
			}
			$by = isset( $verification['by'] ) ? (string) $verification['by'] : '';
			if ( 0 === \strpos( $by, 'human:' ) ) {
				return 'human-reviewed';
			}
		}

		return 'machine-confirmed';
	}

	/**
	 * Check whether a concept is stale (past its `stale_after` date).
	 *
	 * @param array $frontmatter Parsed frontmatter.
	 * @return bool True if the concept has a stale_after date in the past.
	 */
	public function is_stale( array $frontmatter ) {
		if ( empty( $frontmatter['stale_after'] ) ) {
			return false;
		}

		$deadline = \strtotime( (string) $frontmatter['stale_after'] );
		return false !== $deadline && $deadline < \time();
	}

	/**
	 * Search concepts by type, tag, status, trust tier, and staleness.
	 *
	 * Scans the bundle directory tree for concepts matching the criteria.
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
		$want_type     = isset( $criteria['type'] ) ? \sanitize_text_field( $criteria['type'] ) : null;
		$want_tag      = isset( $criteria['tag'] ) ? \sanitize_text_field( $criteria['tag'] ) : null;
		$want_status   = isset( $criteria['status'] ) ? \sanitize_text_field( $criteria['status'] ) : null;
		$want_tier     = isset( $criteria['trust_tier'] ) ? \sanitize_text_field( $criteria['trust_tier'] ) : null;
		$include_stale = isset( $criteria['include_stale'] ) ? (bool) $criteria['include_stale'] : true;

		$files = $this->find_all_concept_files( $this->bundle_root );
		foreach ( $files as $file_path ) {
			$concept_id = $this->file_to_concept_id( $file_path );
			$concept    = $this->get_concept( $concept_id );

			if ( \is_wp_error( $concept ) ) {
				continue;
			}

			$fm = $concept['frontmatter'];

			// Filter by type.
			if ( null !== $want_type ) {
				$concept_type = isset( $fm['type'] ) ? $fm['type'] : '';
				if ( \strtolower( $concept_type ) !== \strtolower( $want_type ) ) {
					continue;
				}
			}

			// Filter by tag.
			if ( null !== $want_tag ) {
				$tags = isset( $fm['tags'] ) ? $fm['tags'] : array();
				if ( ! \is_array( $tags ) ) {
					$tags = array( $tags );
				}
				$found = false;
				foreach ( $tags as $tag ) {
					if ( \strtolower( $tag ) === \strtolower( $want_tag ) ) {
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
				if ( \strtolower( $concept_status ) !== \strtolower( $want_status ) ) {
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
	 * @return array Array of type strings.
	 */
	public function get_types() {
		$types = array();
		$files = $this->find_all_concept_files( $this->bundle_root );

		foreach ( $files as $file_path ) {
			$concept_id = $this->file_to_concept_id( $file_path );
			$concept    = $this->get_concept( $concept_id );

			if ( \is_wp_error( $concept ) || ! isset( $concept['frontmatter']['type'] ) ) {
				continue;
			}

			$type = $concept['frontmatter']['type'];
			if ( ! \in_array( $type, $types, true ) ) {
				$types[] = $type;
			}
		}

		\sort( $types );
		return $types;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalize a concept ID (strip .md suffix, trim slashes).
	 *
	 * @param string $concept_id Raw concept ID.
	 * @return string
	 */
	private function normalize_concept_id( $concept_id ) {
		$concept_id = \trim( $concept_id, '/' );
		// Strip .md suffix if present.
		if ( '.md' === \substr( $concept_id, -3 ) ) {
			$concept_id = \substr( $concept_id, 0, -3 );
		}
		return $concept_id;
	}

	/**
	 * Resolve a concept ID to an absolute file path.
	 *
	 * @param string $concept_id Concept ID.
	 * @return string|\WP_Error
	 */
	private function resolve_file_path( $concept_id ) {
		// Handle bundle-relative absolute links: /tables/orders → tables/orders.
		$concept_id = \ltrim( $concept_id, '/' );

		$file_path = $this->bundle_root . '/' . $concept_id . '.md';

		// Normalize path separators and prevent directory traversal.
		$file_path = \wp_normalize_path( $file_path );

		// Fast lexical check: must be inside the bundle root with a '/'
		// boundary (a sibling directory like `bundle-root-other` must not
		// pass). Both sides are normalized to forward slashes.
		$normalized_root = \wp_normalize_path( $this->bundle_root );
		if ( 0 !== \strpos( $file_path, $normalized_root . '/' ) ) {
			return new \WP_Error(
				'okf_path_traversal',
				__( 'Concept path escapes the bundle root.', 'nvoos-content-graph-ai' )
			);
		}

		if ( ! \file_exists( $file_path ) ) {
			return new \WP_Error(
				'okf_not_found',
				\sprintf(
					/* translators: %s: concept ID */
					__( 'Concept not found: %s', 'nvoos-content-graph-ai' ),
					$concept_id
				)
			);
		}

		// Symlink-aware containment: realpath() resolves `..` segments and
		// symlinks, so a file that resolves outside the bundle (even one that
		// passes the lexical check) is rejected.
		$real_root = \realpath( $normalized_root );
		$real_file = \realpath( $file_path );
		if (
			false === $real_root
			|| false === $real_file
			|| ( $real_file !== $real_root && 0 !== \strpos( $real_file, $real_root . DIRECTORY_SEPARATOR ) )
		) {
			return new \WP_Error(
				'okf_path_traversal',
				__( 'Concept path escapes the bundle root.', 'nvoos-content-graph-ai' )
			);
		}

		return $file_path;
	}

	/**
	 * Read a file with error handling.
	 *
	 * @param string $path Absolute file path.
	 * @return string|\WP_Error
	 */
	private function read_file( $path ) {
		if ( ! \is_readable( $path ) ) {
			return new \WP_Error(
				'okf_unreadable',
				\sprintf(
					/* translators: %s: file path */
					__( 'OKF file is not readable: %s', 'nvoos-content-graph-ai' ),
					\basename( $path )
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read for OKF bundle; no remote URL involved.
		$content = \file_get_contents( $path );
		if ( false === $content ) {
			return new \WP_Error(
				'okf_read_error',
				\sprintf(
					/* translators: %s: file path */
					__( 'Failed to read OKF file: %s', 'nvoos-content-graph-ai' ),
					\basename( $path )
				)
			);
		}

		return $content;
	}

	/**
	 * Convert an absolute file path to a concept ID.
	 *
	 * Public (like the base since 1.1.62): OkfWriter::validate_bundle()
	 * calls it, and it is a pure path utility with no state side effects.
	 *
	 * @param string $file_path Absolute file path.
	 * @return string
	 */
	public function file_to_concept_id( $file_path ) {
		$relative = \str_replace( \wp_normalize_path( $this->bundle_root ) . '/', '', \wp_normalize_path( $file_path ) );
		if ( '.md' === \substr( $relative, -3 ) ) {
			$relative = \substr( $relative, 0, -3 );
		}
		return $relative;
	}

	/**
	 * Extract concept cross-links from markdown body text.
	 *
	 * Matches `[text](path.md)` patterns where the path ends in `.md`.
	 *
	 * @param string $body Markdown body text.
	 * @return string[] Array of concept IDs (without .md suffix).
	 */
	private function extract_concept_links( $body ) {
		$links = array();
		if ( \preg_match_all( '/\[([^\]]*)\]\(([^)]+\.md)\)/', $body, $matches ) ) {
			foreach ( $matches[2] as $link_path ) {
				// Remove anchor fragments.
				$link_path = \preg_replace( '/#.*$/', '', $link_path );

				// Resolve relative paths against the bundle root.
				$concept_id = \ltrim( $link_path, '/' );
				$concept_id = $this->normalize_concept_id( $concept_id );

				// Remove leading ../ sequences.
				$concept_id = \preg_replace( '#^(\.\./)+#', '', $concept_id );

				$links[] = $concept_id;
			}
		}
		return \array_unique( $links );
	}

	/**
	 * Find broken bundle-internal cross-links in a concept's body.
	 *
	 * OKF v0.2 §6.1: consumers MUST tolerate broken links — this is an
	 * advisory report for validators and UIs, not a read gate.
	 *
	 * @param string $concept_id Concept ID to inspect.
	 * @return array<int, array{concept_id: string, target: string, resolved: string}>
	 *               Broken-link descriptors (empty when all links resolve).
	 */
	public function find_broken_links( $concept_id ) {
		$concept = $this->get_concept( $concept_id );
		if ( \is_wp_error( $concept ) ) {
			return array();
		}

		$targets = array();
		if ( \preg_match_all( '/\[([^\]]*)\]\(([^)]+\.md)(#[^)]*)?\)/', $concept['body'], $matches ) ) {
			$targets = $matches[2];
		}

		$broken = array();
		$root   = \wp_normalize_path( $this->bundle_root );

		foreach ( $targets as $target ) {
			$resolved = $this->resolve_link_target( $concept_id, $target );
			if ( '' === $resolved ) {
				continue; // External URI or unparseable target — not bundle-internal.
			}

			$file_path = \wp_normalize_path( $root . '/' . $resolved . '.md' );

			// Containment guard: resolved IDs must stay inside the bundle root.
			// $root is normalized to forward slashes, so the boundary is '/'.
			if ( 0 !== \strpos( $file_path, $root . '/' ) ) {
				continue;
			}

			if ( ! \file_exists( $file_path ) ) {
				$broken[] = array(
					'concept_id' => $concept_id,
					'target'     => $target,
					'resolved'   => $resolved,
				);
			}
		}

		return $broken;
	}

	/**
	 * Resolve a markdown link target against a concept's location.
	 *
	 * Handles bundle-relative absolute links (`/tables/orders.md`), relative
	 * links (`./other.md`, `../up.md`), and external URI schemes (returned
	 * as an empty string so callers can skip them).
	 *
	 * @param string $concept_id The concept containing the link.
	 * @param string $target     Raw link target.
	 * @return string Resolved concept ID ('' for external targets).
	 */
	private function resolve_link_target( $concept_id, $target ) {
		$target = \trim( $target );

		if ( 0 === \strpos( $target, '/' ) ) {
			// Bundle-relative absolute link (OKF v0.2 §6.1 recommended form).
			return $this->normalize_concept_id( $target );
		}

		// External URI schemes are out of scope for broken-link checks.
		if ( \preg_match( '#^[a-z][a-z0-9+.-]*://#i', $target ) || \preg_match( '#^[a-z][a-z0-9+.-]*:#i', $target ) ) {
			return '';
		}

		// Relative link: resolve against the concept's own directory.
		$base = \dirname( $concept_id );
		if ( '.' === $base ) {
			$base = '';
		}

		$resolved = $this->normalize_concept_id( $base . '/' . $target );

		// Clamp above-root references to the bundle root.
		$resolved = \preg_replace( '#^(?:\.\./)+#', '', $resolved );

		return \ltrim( $resolved, '/' );
	}

	/**
	 * Parse an index.md file into a browse result.
	 *
	 * @param string $content Raw index.md content.
	 * @return array
	 */
	private function parse_index_content( $content ) {
		$entries = array();
		$lines   = \explode( "\n", $content );

		foreach ( $lines as $line ) {
			$line = \trim( $line );
			// Match bullet list items with links: `* [Title](path) - description`.
			if ( \preg_match( '/^\*?\s*\[([^\]]+)\]\(([^)]+)\)\s*-?\s*(.*)$/', $line, $m ) ) {
				$entries[] = array(
					'title'       => \trim( $m[1] ),
					'path'        => \trim( $m[2] ),
					'description' => \trim( $m[3] ),
				);
			}
		}

		return $entries;
	}

	/**
	 * Scan a directory for concepts and subdirectories (fallback when no index.md).
	 *
	 * @param string $dir_path Absolute directory path.
	 * @param string $rel_path Relative directory path for output.
	 * @return array
	 */
	private function scan_directory( $dir_path, $rel_path ) {
		$entries = array();
		$items   = \scandir( $dir_path );

		if ( false === $items ) {
			return $entries;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$full_path = $dir_path . '/' . $item;
			$rel_item  = ( '' === $rel_path ) ? $item : $rel_path . '/' . $item;

			if ( \is_dir( $full_path ) ) {
				$entries[] = array(
					'title'       => \basename( $item ),
					'path'        => $rel_item . '/',
					'description' => '',
				);
			} elseif ( '.md' === \substr( $item, -3 )
				&& 'index.md' !== $item
				&& 'log.md' !== $item ) {
				// Try to read the title from frontmatter.
				$concept_id = $this->file_to_concept_id( $full_path );
				$concept    = $this->get_concept( $concept_id );
				$title      = $item;
				$desc       = '';

				if ( ! \is_wp_error( $concept ) ) {
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
	 * @param string $dir Absolute directory path.
	 * @return string[] Array of absolute file paths.
	 */
	private function find_all_concept_files( $dir ) {
		$files    = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info->isFile() ) {
				continue;
			}

			$filename = $file_info->getFilename();
			if ( '.md' !== \substr( $filename, -3 ) ) {
				continue;
			}

			// Skip reserved filenames.
			if ( 'index.md' === $filename || 'log.md' === $filename ) {
				continue;
			}

			$files[] = \wp_normalize_path( $file_info->getPathname() );
		}

		return $files;
	}

	/**
	 * Clear the concept cache.
	 *
	 * @return void
	 */
	public function clear_cache() {
		$this->concept_cache = array();
	}
}
