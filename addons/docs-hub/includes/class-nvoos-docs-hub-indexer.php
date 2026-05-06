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
	 * Build the full manifest from scanned entries.
	 *
	 * @since 1.0.0
	 *
	 * @param array $entries Entries from NV_oOS_Docs_Hub_Scanner::scan().
	 * @return array
	 */
	public function build_manifest( $entries ) {
		$this->slug_map     = array();
		$this->tree         = array();
		$this->broken_links = array();

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
				$slug_counter++;
			}

			$this->slug_map[ $slug ] = array(
				'path'         => $entry['path'],
				'title'        => $title,
				'source'       => $entry['source'],
				'plugin_name'  => $entry['plugin_name'],
				'order'        => $order,
				'frontmatter'  => $frontmatter,
				'relative_path' => $entry['relative_path'],
			);

			$indexed[] = array_merge( $entry, array(
				'slug'  => $slug,
				'title' => $title,
				'order' => $order,
			) );
		}

		// Second pass: assign prev/next, build tree.
		$this->assign_prev_next( $indexed );
		$this->build_tree( $indexed );

		// Third pass: detect broken internal links.
		foreach ( $this->slug_map as $slug => $data ) {
			$content             = $this->read_file( $data['path'] );
			$broken              = $this->detect_broken_links( $content, $data['path'], $data['relative_path'] );
			$this->broken_links  = array_merge( $this->broken_links, $broken );
		}

		$manifest = array(
			'version'      => NVOOS_DOCS_HUB_VERSION,
			'built_at'     => time(),
			'tree'         => $this->tree,
			'slug_map'     => $this->slug_map,
			'total_pages'  => count( $this->slug_map ),
			'broken_links' => $this->broken_links,
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
			'markdown'      => $content,
			'toc'           => $toc,
			'frontmatter'   => $frontmatter,
			'breadcrumbs'   => $breadcrumbs,
			'prev'          => $prev,
			'next'          => $next,
			'source'        => $data['source'],
			'plugin_name'   => $data['plugin_name'],
			'last_modified' => filemtime( $data['path'] ),
			'word_count'    => $word_count,
			'languages'     => $languages,
		);

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

		// First H1 heading.
		if ( preg_match( '/^#\s+(.+)$/m', $content, $m ) ) {
			return sanitize_text_field( trim( $m[1] ) );
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
			$level  = strlen( $match[1] );
			$text   = trim( $match[2] );
			$anchor = $this->slugify_heading( $text );
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
	private function detect_broken_links( $content, $file_path, $relative_path ) {
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
				$broken[] = array(
					'source' => $relative_path,
					'target' => $href,
				);
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
		$tree = array();

		foreach ( $indexed as $entry ) {
			$source      = $entry['source'];
			$plugin_name = $entry['plugin_name'];
			$slug        = $entry['slug'];

			// Derive section from relative path.
			$section = $this->derive_section( $entry['relative_path'] );

			if ( ! isset( $tree[ $source ] ) ) {
				$tree[ $source ] = array();
			}
			if ( ! isset( $tree[ $source ][ $plugin_name ] ) ) {
				$tree[ $source ][ $plugin_name ] = array();
			}
			if ( ! isset( $tree[ $source ][ $plugin_name ][ $section ] ) ) {
				$tree[ $source ][ $plugin_name ][ $section ] = array();
			}

			$tree[ $source ][ $plugin_name ][ $section ][] = array(
				'slug'  => $slug,
				'title' => $entry['title'],
				'order' => $entry['order'],
			);
		}

		// Sort entries within each section by order.
		foreach ( $tree as $src => &$plugins ) {
			foreach ( $plugins as $pname => &$sections ) {
				foreach ( $sections as $sec => &$pages ) {
					usort(
						$pages,
						function ( $a, $b ) {
							return $a['order'] - $b['order'];
						}
					);
				}
			}
		}

		$this->tree = $tree;
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
			array( 'label' => $data['plugin_name'], 'slug' => null ),
		);

		// Add section if meaningful.
		$section = $this->derive_section( $data['relative_path'] );
		if ( 'General' !== $section ) {
			$crumbs[] = array( 'label' => $section, 'slug' => null );
		}

		$crumbs[] = array( 'label' => $data['title'], 'slug' => null );

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
	private function read_file( $path ) {
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return '';
		}
		$contents = file_get_contents( $path );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false === $contents ? '' : $contents;
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
