<?php
/**
 * NV oOS Docs Hub — Indexer
 *
 * Processes scanned file entries into a manifest and per-page payloads.
 * Handles frontmatter extraction, slug derivation, TOC building,
 * internal link resolution, and breadcrumb generation.
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the documentation manifest and per-page payloads.
 *
 * @since 1.0.0
 */
class NV_oOS_Docs_Hub_Indexer {

	/**
	 * Flat map of slug => entry data.
	 *
	 * @var array
	 */
	private $slug_map = array();

	/**
	 * Tree structure for navigation.
	 *
	 * @var array
	 */
	private $tree = array();

	/**
	 * Recorded broken internal links.
	 *
	 * @var array
	 */
	private $broken_links = array();

	/**
	 * Map of slug => MD5 content hash for suggestion engine.
	 *
	 * Built during scan and persisted in the manifest so the next rebuild
	 * can detect renamed files via content-hash matching.
	 *
	 * @since 1.3.0
	 * @var array
	 */
	private $content_hashes = array();

	/**
	 * Reference to the scanned entries array, stored for suggestion lookups.
	 *
	 * @since 1.3.0
	 * @var array
	 */
	private $entries = array();

	/**
	 * Levenshtein distance threshold for fuzzy filename matching.
	 *
	 * Filenames with a Levenshtein distance ≤ this value are considered
	 * potential corrections for broken link targets.
	 *
	 * @since 1.3.0
	 * @var int
	 */
	const FUZZY_THRESHOLD = 3;

	/**
	 * Source priority for slug-collision and tree ordering.
	 *
	 * Lower = higher priority. The plugin-root README/CHANGELOG (`root`)
	 * outrank addon READMEs so they own the canonical `readme` /
	 * `changelog` slugs in the SPA.
	 *
	 * Filter via `nvoos_docs_hub_source_priority` to override.
	 *
	 * @var array
	 */
	const SOURCE_PRIORITY = array(
		'root'    => 0,
		'base'    => 1,
		'addons'  => 2,
		'context' => 3,
		'remote'  => 4,
	);

	/**
	 * Build the full manifest from scanned entries.
	 *
	 * @since 1.0.0
	 *
	 * @param array $entries           Entries from NV_oOS_Docs_Hub_Scanner::scan().
	 * @param bool  $detect_broken     When false, skip the broken-link pass
	 *                                 (each file is read twice during it).
	 *                                 The chunked rebuild pipeline runs link
	 *                                 detection in a separate phase to spread
	 *                                 the I/O across requests.
	 * @return array
	 */
	public function build_manifest( $entries, $detect_broken = true ) {
		$this->slug_map       = array();
		$this->tree           = array();
		$this->broken_links   = array();
		$this->content_hashes = array();
		$this->entries        = $entries;

		// Sort by source priority FIRST so the slug-collision suffix
		// (`-1`, `-2`, …) lands on the lower-priority entries — the plugin
		// root README owns `readme`, addon READMEs become `readme-1`, etc.
		$entries = $this->sort_entries_by_priority( $entries );

		// First pass: derive slugs and extract metadata.
		$indexed = array();
		foreach ( $entries as $entry ) {
			$content     = $this->read_file( $entry['path'] );
			$frontmatter = $this->extract_frontmatter( $content );
			$slug        = isset( $frontmatter['slug'] ) && $frontmatter['slug']
				? sanitize_title( $frontmatter['slug'] )
				: $this->derive_slug( $entry['relative_path'] );
			$title       = $this->extract_title( $content, $frontmatter, $entry['relative_path'] );
			$order       = isset( $frontmatter['order'] ) ? (int) $frontmatter['order'] : 999;

			// Avoid duplicate slugs by appending a counter.
			$base_slug    = $slug;
			$slug_counter = 1;
			while ( isset( $this->slug_map[ $slug ] ) ) {
				$slug = $base_slug . '-' . $slug_counter;
				++$slug_counter;
			}

			$this->slug_map[ $slug ] = array(
				'path'          => $entry['path'],
				'title'         => $title,
				'source'        => $entry['source'],
				'plugin_name'   => $entry['plugin_name'],
				'order'         => $order,
				'frontmatter'   => $frontmatter,
				'relative_path' => $entry['relative_path'],
			);

			// For remote entries, carry the GitHub blob URL through so the
			// SPA can rewrite relative links to point at GitHub.
			if ( 'remote' === $entry['source'] && ! empty( $entry['remote_url'] ) ) {
				$this->slug_map[ $slug ]['remote_url'] = (string) $entry['remote_url'];
			}

			$indexed[] = array_merge(
				$entry,
				array(
					'slug'  => $slug,
					'title' => $title,
					'order' => $order,
				)
			);
		}

		// Second pass: assign prev/next, build tree.
		$this->assign_prev_next( $indexed );
		$this->build_tree( $indexed );

		// Build content-hash map BEFORE broken-link detection so the
		// suggestion engine can use it for hash matching.
		$this->content_hashes = $this->build_content_hash_map();

		// Third pass: detect broken internal links (skip when chunked
		// rebuild will run this in its own phase).
		if ( $detect_broken ) {
			foreach ( $this->slug_map as $slug => $data ) {
				$content            = $this->read_file( $data['path'] );
				$broken             = $this->detect_broken_links( $content, $data['path'], $data['relative_path'] );
				$this->broken_links = array_merge( $this->broken_links, $broken );
			}
		}

		$built_at = time();
		$manifest = array(
			'version'        => NVOOS_DOCS_HUB_VERSION,
			'built_at'       => $built_at,
			'cache_version'  => md5( (string) $built_at . '|' . wp_json_encode( array_keys( $this->slug_map ) ) ),
			'tree'           => $this->tree,
			'slug_map'       => $this->slug_map,
			'total_pages'    => count( $this->slug_map ),
			'broken_links'   => $this->broken_links,
			'content_hashes' => $this->content_hashes,
		);

		/**
		 * Filter the documentation manifest before it is cached.
		 *
		 * @since 1.0.0
		 *
		 * @param array $manifest The manifest array.
		 */
		return apply_filters( 'nvoos_docs_hub_manifest', $manifest );
	}

	/**
	 * Build a page payload for the given slug.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Page slug.
	 * @return array|false False when slug not found.
	 */
	public function build_page_payload( $slug ) {
		if ( ! isset( $this->slug_map[ $slug ] ) ) {
			return false;
		}

		$data        = $this->slug_map[ $slug ];
		$content     = $this->read_file( $data['path'] );
		$frontmatter = $data['frontmatter'];
		$toc         = $this->extract_heading_tree( $content );
		$word_count  = str_word_count( wp_strip_all_tags( $content ) );
		$languages   = $this->extract_code_languages( $content );
		$breadcrumbs = $this->build_breadcrumbs( $data );

		$prev = isset( $data['prev_slug'] ) && $data['prev_slug']
			? array(
				'slug'  => $data['prev_slug'],
				'title' => isset( $this->slug_map[ $data['prev_slug'] ] )
					? $this->slug_map[ $data['prev_slug'] ]['title'] : '',
			) : null;

		$next = isset( $data['next_slug'] ) && $data['next_slug']
			? array(
				'slug'  => $data['next_slug'],
				'title' => isset( $this->slug_map[ $data['next_slug'] ] )
					? $this->slug_map[ $data['next_slug'] ]['title'] : '',
			) : null;

		$payload = array(
			'slug'          => $slug,
			'title'         => $data['title'],
			'content'       => $content,
			'toc'           => $toc,
			'frontmatter'   => $frontmatter,
			'breadcrumbs'   => $breadcrumbs,
			'prev'          => $prev,
			'next'          => $next,
			'source'        => $data['source'],
			'plugin_name'   => $data['plugin_name'],
			'last_modified' => filemtime( $data['path'] ),
			'relative_path' => isset( $data['relative_path'] ) ? (string) $data['relative_path'] : '',
			'word_count'    => $word_count,
			'languages'     => $languages,
		);

		// Include the GitHub blob URL for remote-sourced pages so the SPA
		// can resolve relative links to absolute GitHub URLs.
		if ( ! empty( $data['remote_url'] ) ) {
			$payload['remote_url'] = (string) $data['remote_url'];
		}

		/**
		 * Filter a page payload before it is returned or cached.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $payload Page payload.
		 * @param string $slug    Page slug.
		 */
		return apply_filters( 'nvoos_docs_hub_page_payload', $payload, $slug );
	}

	/**
	 * Derive a URL-safe slug from a relative file path.
	 *
	 * Examples:
	 *   docs/README.md         → readme
	 *   docs/features/chat.md  → features/chat
	 *   README.md              → readme
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative_path Relative path to the file.
	 * @return string
	 */
	public function derive_slug( $relative_path ) {
		// Strip leading 'docs/' prefix.
		$path = preg_replace( '#^docs/#i', '', $relative_path );
		// Strip file extension.
		$path = preg_replace( '/\.[a-z]+$/i', '', $path );
		// Lowercase.
		$path = strtolower( $path );
		// Replace spaces and non-alphanumeric/slash/hyphen characters with hyphens.
		$path = preg_replace( '/[^a-z0-9\/\-]/', '-', $path );
		// Collapse multiple hyphens.
		$path = preg_replace( '/-{2,}/', '-', $path );
		// Trim leading/trailing slashes and hyphens.
		$path = trim( $path, '/-' );
		// Normalize path separators.
		$path = str_replace( DIRECTORY_SEPARATOR, '/', $path );

		return $path;
	}

	/**
	 * Extract YAML frontmatter from Markdown content.
	 *
	 * Parses a leading `---\n...\n---` block using regex. No external library.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content Markdown content.
	 * @return array Extracted frontmatter values.
	 */
	public function extract_frontmatter( $content ) {
		$frontmatter = array();

		if ( ! preg_match( '/^-{3}\r?\n(.*?)\r?\n-{3}/s', $content, $matches ) ) {
			return $frontmatter;
		}

		$yaml_block = $matches[1];
		$lines      = explode( "\n", $yaml_block );

		foreach ( $lines as $line ) {
			if ( ! preg_match( '/^([a-zA-Z_][a-zA-Z0-9_]*):\s*(.*)$/', trim( $line ), $pair ) ) {
				continue;
			}
			$key   = trim( $pair[1] );
			$value = trim( $pair[2], " \t\"'" );

			// Coerce basic types.
			if ( 'true' === $value ) {
				$value = true;
			} elseif ( 'false' === $value ) {
				$value = false;
			} elseif ( is_numeric( $value ) ) {
				$value = strpos( $value, '.' ) !== false ? (float) $value : (int) $value;
			}

			$frontmatter[ $key ] = $value;
		}

		return $frontmatter;
	}

	/**
	 * Strip inline Markdown syntax from a heading or title string.
	 *
	 * Removes bold/italic markers, inline links, and inline code spans so that
	 * titles stored in the manifest and sidebar are plain readable text.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Raw heading text that may contain Markdown.
	 * @return string Plain text.
	 */
	private function strip_inline_markdown( $text ) {
		// Links and images: [text](url) → text  |  ![alt](url) → alt.
		$text = preg_replace( '/!?\[([^\]]*)\]\([^)]*\)/', '$1', $text );
		// Reference-style links: [text][ref] → text.
		$text = preg_replace( '/\[([^\]]*)\]\[[^\]]*\]/', '$1', $text );
		// Bold+italic: ***text*** / ___text___ → text.
		$text = preg_replace( '/\*{3}(.+?)\*{3}/s', '$1', $text );
		$text = preg_replace( '/_{3}(.+?)_{3}/s', '$1', $text );
		// Bold: **text** / __text__ → text.
		$text = preg_replace( '/\*{2}(.+?)\*{2}/s', '$1', $text );
		$text = preg_replace( '/_{2}(.+?)_{2}/s', '$1', $text );
		// Italic: *text* / _text_ → text (avoid mangling snake_case by requiring spaces).
		$text = preg_replace( '/(?<!\w)\*([^*\n]+?)\*(?!\w)/', '$1', $text );
		// Inline code: `text` → text.
		$text = preg_replace( '/`([^`]+)`/', '$1', $text );
		// Strip any remaining raw HTML tags.
		$text = wp_strip_all_tags( $text );
		return trim( $text );
	}

	/**
	 * Extract the page title from frontmatter, first H1, or filename.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content       Markdown content.
	 * @param array  $frontmatter   Extracted frontmatter.
	 * @param string $relative_path Relative file path.
	 * @return string
	 */
	public function extract_title( $content, $frontmatter, $relative_path ) {
		if ( ! empty( $frontmatter['title'] ) ) {
			return sanitize_text_field( $frontmatter['title'] );
		}

		// First H1 heading — strip inline Markdown before returning.
		if ( preg_match( '/^#\s+(.+)$/m', $content, $m ) ) {
			return sanitize_text_field( $this->strip_inline_markdown( trim( $m[1] ) ) );
		}

		// Filename without extension, title-cased.
		$basename = pathinfo( $relative_path, PATHINFO_FILENAME );
		$basename = str_replace( array( '-', '_' ), ' ', $basename );
		return ucwords( strtolower( $basename ) );
	}

	/**
	 * Extract the heading tree (TOC) from Markdown content.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content Markdown content.
	 * @return array Array of [ 'level' => int, 'text' => string, 'anchor' => string ].
	 */
	public function extract_heading_tree( $content ) {
		$toc = array();

		if ( ! preg_match_all( '/^(#{1,4})\s+(.+)$/m', $content, $matches, PREG_SET_ORDER ) ) {
			return $toc;
		}

		foreach ( $matches as $match ) {
			$level = strlen( $match[1] );
			$raw   = trim( $match[2] );
			// Keep the anchor based on the raw text (rehype-slug does the same),
			// but strip inline Markdown from the display text.
			$anchor = $this->slugify_heading( $raw );
			$text   = $this->strip_inline_markdown( $raw );
			$toc[]  = array(
				'level'  => $level,
				'text'   => $text,
				'anchor' => $anchor,
			);
		}

		return $toc;
	}

	/**
	 * Convert a heading text to an anchor-safe slug.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Heading text.
	 * @return string
	 */
	private function slugify_heading( $text ) {
		$slug = strtolower( $text );
		$slug = preg_replace( '/[^a-z0-9\s\-]/', '', $slug );
		$slug = preg_replace( '/[\s]+/', '-', trim( $slug ) );
		return $slug;
	}

	/**
	 * Extract programming languages used in fenced code blocks.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content Markdown content.
	 * @return string[]
	 */
	public function extract_code_languages( $content ) {
		if ( ! preg_match_all( '/```(\w+)/', $content, $matches ) ) {
			return array();
		}
		return array_values( array_unique( $matches[1] ) );
	}

	/**
	 * Detect broken internal Markdown links in the content.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content       Markdown content.
	 * @param string $file_path     Absolute path to the file being checked.
	 * @param string $relative_path Relative path for reporting.
	 * @return array List of [ 'source' => '...', 'target' => '...' ] entries.
	 */
	public function detect_broken_links( $content, $file_path, $relative_path ) {
		$broken = array();

		if ( ! preg_match_all( '/\[([^\]]+)\]\(([^)]+)\)/', $content, $matches, PREG_SET_ORDER ) ) {
			return $broken;
		}

		$base_dir = dirname( $file_path );

		foreach ( $matches as $match ) {
			$href = $match[2];

			// Skip absolute URLs and anchors.
			if ( preg_match( '#^https?://#', $href ) || '#' === $href[0] ) {
				continue;
			}

			// Only check .md links.
			if ( ! preg_match( '/\.md(#[^)]*)?$/', $href ) ) {
				continue;
			}

			// Strip anchor fragment.
			$href_path = preg_replace( '/#[^)]*$/', '', $href );

			$resolved = realpath( $base_dir . DIRECTORY_SEPARATOR . $href_path );
			if ( false === $resolved || ! file_exists( $resolved ) ) {
				$suggestions = $this->suggest_fix( $href_path, $file_path );

				$entry = array(
					'source' => $relative_path,
					'target' => $href,
				);

				if ( ! empty( $suggestions ) ) {
					$entry['suggestions'] = $suggestions;
				}

				$broken[] = $entry;
			}
		}

		return $broken;
	}

	/**
	 * Build the tree structure grouped by source → plugin_name → section → page.
	 *
	 * @since 1.0.0
	 *
	 * @param array $indexed Indexed entries with slug and order.
	 * @return void
	 */
	private function build_tree( $indexed ) {
		// Group pages by source + plugin_name into a flat list of groups.
		// This matches the SPA `Manifest.tree: ManifestGroup[]` contract
		// (see addons/docs-hub/src/api/manifest-client.ts), which calls
		// `tree.flatMap(...)` and `tree.map(group => group.pages)`.
		$groups = array();

		foreach ( $indexed as $entry ) {
			$source      = $entry['source'];
			$plugin_name = $entry['plugin_name'];
			$slug        = $entry['slug'];
			$key         = $source . '|' . $plugin_name;

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'source'      => $source,
					'plugin_name' => $plugin_name,
					'pages'       => array(),
				);
			}

			$groups[ $key ]['pages'][] = array(
				'slug'  => $slug,
				'title' => $entry['title'],
				'order' => $entry['order'],
			);
		}

		// Sort pages within each group by order.
		foreach ( $groups as &$group ) {
			usort(
				$group['pages'],
				function ( $a, $b ) {
					return $a['order'] - $b['order'];
				}
			);
		}
		unset( $group );

		// Reindex to a numeric array so JSON encodes as a JS array (not object).
		$this->tree = array_values( $groups );
	}

	/**
	 * Derive a section label from a relative path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative_path Relative file path.
	 * @return string
	 */
	private function derive_section( $relative_path ) {
		// Remove 'docs/' prefix.
		$path = preg_replace( '#^docs/#i', '', $relative_path );
		$dir  = dirname( $path );

		if ( '.' === $dir || '' === $dir ) {
			return 'General';
		}

		$parts = explode( '/', $dir );
		return ucwords( str_replace( array( '-', '_' ), ' ', $parts[0] ) );
	}

	/**
	 * Assign prev/next slugs to each entry based on sorted order.
	 *
	 * @since 1.0.0
	 *
	 * @param array $indexed Indexed entries.
	 * @return void
	 */
	private function assign_prev_next( $indexed ) {
		$count = count( $indexed );
		for ( $i = 0; $i < $count; $i++ ) {
			$slug = $indexed[ $i ]['slug'];
			if ( isset( $this->slug_map[ $slug ] ) ) {
				$this->slug_map[ $slug ]['prev_slug'] = ( $i > 0 )
					? $indexed[ $i - 1 ]['slug'] : null;
				$this->slug_map[ $slug ]['next_slug'] = ( $i < $count - 1 )
					? $indexed[ $i + 1 ]['slug'] : null;
			}
		}
	}

	/**
	 * Build breadcrumbs for a page entry.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Slug map entry data.
	 * @return array
	 */
	private function build_breadcrumbs( $data ) {
		$crumbs = array(
			array(
				'label' => $data['plugin_name'],
				'slug'  => null,
			),
		);

		// Add section if meaningful.
		$section = $this->derive_section( $data['relative_path'] );
		if ( 'General' !== $section ) {
			$crumbs[] = array(
				'label' => $section,
				'slug'  => null,
			);
		}

		$crumbs[] = array(
			'label' => $data['title'],
			'slug'  => null,
		);

		return $crumbs;
	}

	/**
	 * Read file contents safely.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Absolute file path.
	 * @return string
	 */
	public function read_file( $path ) {
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return '';
		}
		$contents = file_get_contents( $path );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false === $contents ? '' : $contents;
	}

	/**
	 * Stable-sort scanned entries by source priority.
	 *
	 * Entries from higher-priority sources come first so that they win
	 * the canonical slugs in the dedupe loop (the duplicate suffix is
	 * applied to whatever arrives later).
	 *
	 * @since 1.2.0
	 *
	 * @param array $entries Scanned entries.
	 * @return array
	 */
	public function sort_entries_by_priority( $entries ) {
		/**
		 * Filter the source-priority map.
		 *
		 * @since 1.2.0
		 *
		 * @param array $priority Map of source key => integer priority.
		 */
		$priority = apply_filters( 'nvoos_docs_hub_source_priority', self::SOURCE_PRIORITY );

		// PHP 7.4 has no native stable sort key parameter; use array_multisort
		// with the original index as the secondary key to guarantee stability.
		$keys  = array();
		$index = array();
		foreach ( $entries as $i => $entry ) {
			$source  = isset( $entry['source'] ) ? (string) $entry['source'] : '';
			$keys[]  = isset( $priority[ $source ] ) ? (int) $priority[ $source ] : 999;
			$index[] = $i;
		}
		array_multisort( $keys, SORT_ASC, SORT_NUMERIC, $index, SORT_ASC, SORT_NUMERIC, $entries );
		return $entries;
	}

	// -------------------------------------------------------------------------
	// Broken-link suggestion engine
	// -------------------------------------------------------------------------

	/**
	 * Compute an MD5 content hash for a file.
	 *
	 * Used by the suggestion engine to detect renamed files — if a broken
	 * target filename doesn't exist but another file has the same content
	 * hash, it's likely a rename.
	 *
	 * @since 1.3.0
	 *
	 * @param string $file_path Absolute path to the file.
	 * @return string MD5 hex hash, or empty string on failure.
	 */
	public function compute_content_hash( $file_path ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return '';
		}

		$contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return '';
		}

		return md5( $contents );
	}

	/**
	 * Build a slug => MD5 content-hash map from the current slug_map.
	 *
	 * Called during build_manifest() so hashes are available during
	 * broken-link detection and persisted in the manifest.
	 *
	 * @since 1.3.0
	 *
	 * @return array Slug => MD5 hash.
	 */
	public function build_content_hash_map() {
		$hashes = array();

		foreach ( $this->slug_map as $slug => $data ) {
			if ( ! isset( $data['path'] ) ) {
				continue;
			}

			$hash = $this->compute_content_hash( $data['path'] );
			if ( '' !== $hash ) {
				$hashes[ $slug ] = $hash;
			}
		}

		return $hashes;
	}

	/**
	 * Suggest a fix for a broken internal link target.
	 *
	 * Employs three strategies in order:
	 *
	 * 1. **Content-hash matching** (confidence: 0.95) — if the filename stem
	 *    of the broken target matches a known slug's content hash (from a
	 *    previous manifest), it's likely a rename.
	 * 2. **Fuzzy filename matching** (confidence: scaled by distance) —
	 *    Levenshtein distance on the filename stem against all slugs.
	 * 3. **Directory-neighbor fallback** (confidence: 0.5) — looks for .md
	 *    files in the same directory as the source file with a similar stem.
	 *
	 * @since 1.3.0
	 *
	 * @param string $broken_target The link target that couldn't be resolved
	 *                              (e.g. "setup.md" or "../api/old-name.md").
	 * @param string $source_path   Absolute path of the file containing the
	 *                              broken link.
	 * @return array List of suggestion arrays, each with keys:
	 *               - 'target'   (string) Suggested corrected target.
	 *               - 'slug'     (string) Suggested slug.
	 *               - 'title'    (string) Page title if known.
	 *               - 'confidence' (float) 0.0–1.0.
	 *               - 'method'   (string) 'content_hash', 'fuzzy', or 'directory_neighbor'.
	 */
	public function suggest_fix( $broken_target, $source_path ) {
		$suggestions = array();

		// Normalize: drop anchors and directory parts to get the filename stem.
		$target_basename = basename( $broken_target );
		$target_stem     = preg_replace( '/\.md$/i', '', $target_basename );

		if ( '' === $target_stem ) {
			return $suggestions;
		}

		// ---- Strategy 1: Content-hash matching ----
		$previous_hashes = $this->load_previous_content_hashes();

		// Try to find a slug in the previous manifest that started with the
		// same stem (before any `-1`, `-2` dedupe suffix).
		foreach ( $previous_hashes as $prev_slug => $prev_hash ) {
			// Does this slug start with our target stem?
			if ( 0 !== strpos( $prev_slug, $target_stem ) ) {
				continue;
			}

			// Find a slug in the *current* slug_map whose content hash matches.
			foreach ( $this->content_hashes as $cur_slug => $cur_hash ) {
				if ( $cur_hash === $prev_hash ) {
					// Found a content-hash match — the file was renamed.
					$title = isset( $this->slug_map[ $cur_slug ]['title'] )
						? (string) $this->slug_map[ $cur_slug ]['title']
						: '';

					$suggestions[] = array(
						'target'     => $cur_slug . '.md',
						'slug'       => $cur_slug,
						'title'      => $title,
						'confidence' => 0.95,
						'method'     => 'content_hash',
					);
					break 2; // Found a match, don't keep searching.
				}
			}
		}

		// ---- Strategy 2: Fuzzy filename matching ----
		// Build two candidate lists:
		//   (a) full slugs (e.g. "features/pro-toolkit-optimization")
		//   (b) basenames of each slug (e.g. "pro-toolkit-optimization")
		$all_slugs     = array_keys( $this->slug_map );
		$slug_basenames = array();
		foreach ( $all_slugs as $slug ) {
			$slug_basenames[] = basename( $slug );
		}

		$matches       = $this->fuzzy_match_slug( $target_stem, $all_slugs );
		$basename_matches = $this->fuzzy_match_slug( $target_stem, $slug_basenames );

		// Merge basename matches into the full-slug results, mapping
		// basenames back to their original slugs for deduplication.
		foreach ( $basename_matches as $basename => $distance ) {
			foreach ( $all_slugs as $slug ) {
				if ( basename( $slug ) === $basename && ! isset( $matches[ $slug ] ) ) {
					$matches[ $slug ] = $distance;
					break;
				}
			}
		}

		// Re-sort merged results by distance ascending.
		asort( $matches, SORT_NUMERIC );

		foreach ( $matches as $match_slug => $distance ) {
			// Avoid suggesting the same file as the source.
			$match_data = isset( $this->slug_map[ $match_slug ] ) ? $this->slug_map[ $match_slug ] : array();
			$match_path = isset( $match_data['relative_path'] ) ? (string) $match_data['relative_path'] : '';

			$title = isset( $match_data['title'] ) ? (string) $match_data['title'] : '';

			// Confidence: 0.8 at distance 1, scaling down to 0.3 at threshold.
			$confidence = max( 0.3, 0.8 - ( ( $distance - 1 ) * 0.25 ) );

			$suggestions[] = array(
				'target'     => $match_slug . '.md',
				'slug'       => $match_slug,
				'title'      => $title,
				'confidence' => round( $confidence, 2 ),
				'method'     => 'fuzzy',
			);
		}

		// ---- Strategy 3: Directory-neighbor fallback ----
		// Look for .md files in the same directory as the source file (and
		// immediate subdirectories) that share a similar stem.
		$source_dir = dirname( $source_path );

		if ( is_dir( $source_dir ) ) {
			// Collect .md files from the source directory and one level of
			// subdirectories so links like "features/target.md" can be
			// resolved when the source file lives in the parent directory.
			$neighbor_files = array_merge(
				glob( $source_dir . DIRECTORY_SEPARATOR . '*.md' ) ?: array(),
				glob( $source_dir . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*.md' ) ?: array()
			);

			if ( is_array( $neighbor_files ) ) {
				foreach ( $neighbor_files as $neighbor_path ) {
					$neighbor_basename = basename( $neighbor_path );
					$neighbor_stem     = preg_replace( '/\.md$/i', '', $neighbor_basename );

					if ( '' === $neighbor_stem || $neighbor_stem === $target_stem ) {
						continue;
					}

					// Skip if we already suggested this via fuzzy match.
					$already_suggested = false;
					foreach ( $suggestions as $s ) {
						if ( isset( $s['slug'] ) && basename( $s['slug'] ) === $neighbor_stem ) {
							$already_suggested = true;
							break;
						}
					}
					if ( $already_suggested ) {
						continue;
					}

					// Simple similarity: check if stems share a common prefix.
					$common_prefix_len = 0;
					$min_len           = min( strlen( $target_stem ), strlen( $neighbor_stem ) );
					for ( $i = 0; $i < $min_len; $i++ ) {
						if ( $target_stem[ $i ] === $neighbor_stem[ $i ] ) {
							++$common_prefix_len;
						} else {
							break;
						}
					}

					// Require at least 3 common characters or 50% similarity.
					$similarity = $min_len > 0 ? $common_prefix_len / $min_len : 0;
					if ( $common_prefix_len >= 3 || $similarity >= 0.5 ) {
						$suggestions[] = array(
							'target'     => $neighbor_basename,
							'slug'       => $neighbor_stem,
							'title'      => '',
							'confidence' => 0.5,
							'method'     => 'directory_neighbor',
						);
					}
				}
			}
		}

		// ---- Strategy 4: Exact basename match in any subdirectory ----
		// If a slug's basename exactly matches the target stem (e.g.
		// "features/pro-toolkit-optimization" → "pro-toolkit-optimization"),
		// the file was likely moved into a subdirectory. Suggest the
		// first match with high confidence.
		foreach ( $this->slug_map as $slug => $data ) {
			if ( basename( $slug ) !== $target_stem ) {
				continue;
			}

			// Skip if this slug was already suggested.
			$already_suggested = false;
			foreach ( $suggestions as $s ) {
				if ( isset( $s['slug'] ) && $s['slug'] === $slug ) {
					$already_suggested = true;
					break;
				}
			}
			if ( $already_suggested ) {
				continue;
			}

			$title = isset( $data['title'] ) ? (string) $data['title'] : '';

			$suggestions[] = array(
				'target'     => $slug . '.md',
				'slug'       => $slug,
				'title'      => $title,
				'confidence' => 0.9,
				'method'     => 'basename_exact',
			);
			break; // First exact match is sufficient.
		}

		// Sort by confidence descending, then deduplicate by slug.
		usort(
			$suggestions,
			static function ( $a, $b ) {
				return $b['confidence'] <=> $a['confidence'];
			}
		);

		$seen   = array();
		$unique = array();
		foreach ( $suggestions as $s ) {
			if ( ! isset( $seen[ $s['slug'] ] ) ) {
				$seen[ $s['slug'] ] = true;
				$unique[]           = $s;
			}
		}

		return $unique;
	}

	/**
	 * Find slugs whose Levenshtein distance from $stem is ≤ FUZZY_THRESHOLD.
	 *
	 * @since 1.3.0
	 *
	 * @param string   $stem      The filename stem to match against.
	 * @param string[] $all_slugs All known slugs.
	 * @return array Slug => distance, sorted by distance ascending.
	 */
	public function fuzzy_match_slug( $stem, $all_slugs ) {
		$results = array();

		foreach ( $all_slugs as $slug ) {
			$distance = levenshtein( $stem, $slug );
			if ( $distance <= self::FUZZY_THRESHOLD ) {
				$results[ $slug ] = $distance;
			}
		}

		asort( $results, SORT_NUMERIC );

		return $results;
	}

	/**
	 * Load content hashes from a previous manifest cache.
	 *
	 * On the first build there is no previous manifest, so this returns an
	 * empty array. On subsequent rebuilds the hashes from the *current*
	 * (pre-rebuild) manifest are used to detect renamed files.
	 *
	 * @since 1.3.0
	 *
	 * @return array Slug => MD5 hash from the previous manifest.
	 */
	public function load_previous_content_hashes() {
		$cache    = new NV_oOS_Docs_Hub_Cache();
		$manifest = $cache->get_manifest();

		if ( ! is_array( $manifest ) || empty( $manifest['content_hashes'] ) ) {
			return array();
		}

		return (array) $manifest['content_hashes'];
	}

	/**
	 * Get the current content-hash map.
	 *
	 * @since 1.3.0
	 *
	 * @return array Slug => MD5 hash.
	 */
	public function get_content_hashes() {
		return $this->content_hashes;
	}

	/**
	 * Restore content hashes (used by chunked pipeline when re-hydrating
	 * the indexer from a staged manifest).
	 *
	 * @since 1.3.0
	 *
	 * @param array $hashes Slug => MD5 hash.
	 * @return void
	 */
	public function set_content_hashes( $hashes ) {
		$this->content_hashes = is_array( $hashes ) ? $hashes : array();
	}

	/**
	 * Get the current slug map.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_slug_map() {
		return $this->slug_map;
	}

	/**
	 * Restore a previously-built slug map (e.g. between chunked rebuild ticks).
	 *
	 * @since 1.2.0
	 *
	 * @param array $slug_map Slug map keyed by slug.
	 * @return void
	 */
	public function set_slug_map( $slug_map ) {
		$this->slug_map = is_array( $slug_map ) ? $slug_map : array();
	}

	/**
	 * Restore a previously-built tree.
	 *
	 * @since 1.2.0
	 *
	 * @param array $tree Tree structure.
	 * @return void
	 */
	public function set_tree( $tree ) {
		$this->tree = is_array( $tree ) ? $tree : array();
	}

	/**
	 * Restore a previously-built broken-links list.
	 *
	 * @since 1.2.0
	 *
	 * @param array $broken Broken-link entries.
	 * @return void
	 */
	public function set_broken_links( $broken ) {
		$this->broken_links = is_array( $broken ) ? $broken : array();
	}

	/**
	 * Append broken-link entries (used by chunked link-checking).
	 *
	 * @since 1.2.0
	 *
	 * @param array $broken Broken-link entries.
	 * @return void
	 */
	public function append_broken_links( $broken ) {
		if ( ! is_array( $broken ) || empty( $broken ) ) {
			return;
		}
		$this->broken_links = array_merge( $this->broken_links, $broken );
	}

	/**
	 * Get current tree (already built when build_manifest() was called).
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public function get_tree() {
		return $this->tree;
	}

	/**
	 * Get the current broken links list.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_broken_links() {
		return $this->broken_links;
	}
}
