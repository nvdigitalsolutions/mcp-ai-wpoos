<?php
/**
 * Pro SPA REST Controller — OKF Knowledge as a Docs Hub surface.
 *
 * Emits the Docs Hub REST contract (manifest / pages / search) from OKF
 * bundles so the vendored Docs Hub island can browse curated OKF knowledge
 * without the standalone Docs Hub plugin (Proposal 087, Phase 2):
 *
 *   GET /mcp-ai-pro/v1/okf/docs/manifest
 *   GET /mcp-ai-pro/v1/okf/docs/pages/{slug}
 *   GET /mcp-ai-pro/v1/okf/docs/search?q=&limit=
 *
 * Slug scheme: `{bundle}/{concept-id}` — bundle-prefixed so concept IDs are
 * globally unique across bundles. Concept bodies are served with internal
 * `.md` cross-links rewritten to `#/{bundle}/{target}` hash routes (the same
 * server-side resolution the Docs Hub plugin performs for its own content).
 *
 * @package NV_oOS_Pro
 * @since   2.1.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/traits/trait-wp-mcp-ai-spa-credential-auth.php';

/**
 * Class WP_MCP_AI_Pro_REST_Okf_Docs
 *
 * @since 2.1.2
 */
class WP_MCP_AI_Pro_REST_Okf_Docs {

	use WP_MCP_AI_SPA_Credential_Auth;

	/**
	 * REST namespace.
	 *
	 * @since 2.1.2
	 * @var string
	 */
	const NAMESPACE = 'mcp-ai-pro/v1';

	/**
	 * Route base — the island is pointed at this base and appends the
	 * Docs Hub route names (/manifest, /pages/{slug}, /search) unchanged.
	 *
	 * @since 2.1.2
	 * @var string
	 */
	const ROUTE = '/okf/docs';

	/**
	 * Maximum search results (mirrors the Docs Hub search default of 20,
	 * capped at 50).
	 *
	 * @since 2.1.2
	 * @var int
	 */
	const MAX_SEARCH_RESULTS = 50;

	/**
	 * Maximum excerpt length for search results.
	 *
	 * @since 2.1.2
	 * @var int
	 */
	const EXCERPT_LENGTH = 200;

	/**
	 * Register REST routes.
	 *
	 * @since 2.1.2
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/manifest',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_manifest' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/pages/(?P<slug>[a-zA-Z0-9_\-\/\.%]{1,200})',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_page' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'args'                => array(
					'slug' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/search',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_search' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'args'                => array(
					'q'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
						'default'           => 20,
						'maximum'           => self::MAX_SEARCH_RESULTS,
					),
				),
			)
		);
	}

	/**
	 * Permission check — authenticated user with `read`, or a valid assistant
	 * credential (matches the OKF drawer and Content Graph read surfaces).
	 *
	 * @since 2.1.2
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error
	 */
	public static function permission_check( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return self::permission_check_spa_drawer( $request, 'read' );
	}

	/**
	 * Handle GET /okf/docs/manifest — the Docs Hub Manifest shape.
	 *
	 * @since 2.1.2
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_manifest( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$ordered = self::ordered_concepts();
		if ( is_wp_error( $ordered ) ) {
			return $ordered;
		}

		$tree     = array();
		$slug_map = array();
		$built    = 0;

		foreach ( $ordered['bundles'] as $bundle_name => $bundle_meta ) {
			$pages = array();
			foreach ( $bundle_meta['concepts'] as $concept ) {
				$slug              = $bundle_name . '/' . $concept['concept_id'];
				$slug_map[ $slug ] = $slug;
				$pages[]           = array(
					'slug'        => $slug,
					'title'       => isset( $concept['title'] ) ? (string) $concept['title'] : $concept['concept_id'],
					'source'      => 'okf',
					'plugin_name' => $bundle_name,
					'order'       => $built,
					'toc'         => array(), // Headings are computed per page (pages/ endpoint).
					'tags'        => isset( $concept['tags'] ) && is_array( $concept['tags'] ) ? array_values( $concept['tags'] ) : array(),
					'description' => isset( $concept['description'] ) ? (string) $concept['description'] : '',
				);
				++$built;
			}

			if ( ! empty( $pages ) ) {
				$tree[] = array(
					'plugin_name' => $bundle_name,
					'source'      => 'okf',
					'pages'       => $pages,
				);
			}
		}

		// The island reads prev/next from the page payload; the manifest keeps
		// the same flattened order so pages/ can compute neighbours cheaply.
		$flat = array();
		foreach ( $tree as $group ) {
			foreach ( $group['pages'] as $page ) {
				$flat[] = $page;
			}
		}

		return new \WP_REST_Response(
			array(
				'version'       => '0.2',
				'built_at'      => time(),
				'cache_version' => (string) $ordered['modified'],
				'tree'          => $tree,
				'slug_map'      => $slug_map,
				'total_pages'   => count( $flat ),
				'broken_links'  => array(),
			),
			200
		);
	}

	/**
	 * Handle GET /okf/docs/pages/{slug} — the Docs Hub DocPage shape.
	 *
	 * @since 2.1.2
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_page( $request ) {
		$slug = rawurldecode( (string) $request->get_param( 'slug' ) );

		$split   = explode( '/', $slug, 2 );
		$bundle  = isset( $split[0] ) ? $split[0] : '';
		$concept = isset( $split[1] ) ? $split[1] : '';

		if ( '' === $bundle || '' === $concept ) {
			return new \WP_Error(
				'wp_mcp_ai_okf_docs_invalid_slug',
				__( 'The requested knowledge slug is invalid.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$reader = self::get_reader( $bundle );
		if ( is_wp_error( $reader ) ) {
			return $reader;
		}

		$concept_data = $reader->get_concept( $concept );
		if ( is_wp_error( $concept_data ) ) {
			return new \WP_Error(
				'wp_mcp_ai_okf_docs_not_found',
				__( 'No knowledge page exists for this slug.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$fm        = isset( $concept_data['frontmatter'] ) && is_array( $concept_data['frontmatter'] ) ? $concept_data['frontmatter'] : array();
		$title     = isset( $fm['title'] ) ? (string) $fm['title'] : $concept;
		$body      = isset( $concept_data['body'] ) ? (string) $concept_data['body'] : '';
		$neighbours = self::neighbours( $slug );
		$last_mod  = self::bundle_modified( $bundle );

		return new \WP_REST_Response(
			array(
				'slug'          => $slug,
				'title'         => $title,
				'content'       => self::rewrite_cross_links( $body, $bundle, $concept ),
				'toc'           => self::extract_toc( $body ),
				'prev'          => $neighbours['prev'],
				'next'          => $neighbours['next'],
				'source'        => 'okf',
				'plugin_name'   => $bundle,
				'tags'          => isset( $fm['tags'] ) && is_array( $fm['tags'] ) ? array_values( $fm['tags'] ) : array(),
				'description'   => isset( $fm['description'] ) ? (string) $fm['description'] : '',
				'last_modified' => $last_mod,
				'relative_path' => $bundle . '/' . $concept . '.md',
			),
			200
		);
	}

	/**
	 * Handle GET /okf/docs/search — the Docs Hub search shape.
	 *
	 * @since 2.1.2
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_search( $request ) {
		$q = (string) $request->get_param( 'q' );
		if ( '' === $q ) {
			return new \WP_REST_Response(
				array(
					'query'   => '',
					'results' => array(),
					'total'   => 0,
				),
				200
			);
		}

		$ordered = self::ordered_concepts();
		if ( is_wp_error( $ordered ) ) {
			return $ordered;
		}

		$needle  = mb_strtolower( $q );
		$results = array();

		foreach ( $ordered['bundles'] as $bundle_name => $bundle_meta ) {
			$reader = self::get_reader( $bundle_name );
			if ( is_wp_error( $reader ) ) {
				continue;
			}

			foreach ( $bundle_meta['concepts'] as $concept ) {
				$title       = isset( $concept['title'] ) ? (string) $concept['title'] : '';
				$description = isset( $concept['description'] ) ? (string) $concept['description'] : '';
				$haystack    = mb_strtolower(
					implode(
						' ',
						array_filter(
							array(
								$concept['concept_id'],
								$title,
								$description,
								isset( $concept['tags'] ) && is_array( $concept['tags'] ) ? implode( ' ', $concept['tags'] ) : '',
							)
						)
					)
				);

				if ( false === mb_strpos( $haystack, $needle ) ) {
					continue;
				}

				$excerpt      = $description;
				$concept_data = $reader->get_concept( $concept['concept_id'] );
				if ( ! is_wp_error( $concept_data ) && isset( $concept_data['body'] ) ) {
					$excerpt = self::make_excerpt( (string) $concept_data['body'] );
					if ( '' === $excerpt ) {
						$excerpt = $description;
					}
				}

				// Simple relevance: title > concept id > body-only matches.
				$score = 3;
				if ( false === mb_strpos( mb_strtolower( $title ), $needle ) ) {
					$score = false !== mb_strpos( mb_strtolower( $concept['concept_id'] ), $needle ) ? 2 : 1;
				}

				$results[] = array(
					'slug'    => $bundle_name . '/' . $concept['concept_id'],
					'title'   => '' !== $title ? $title : $concept['concept_id'],
					'excerpt' => $excerpt,
					'score'   => $score,
				);

				if ( count( $results ) >= self::MAX_SEARCH_RESULTS ) {
					break 2;
				}
			}
		}

		$limit_param = (int) $request->get_param( 'limit' );
		$limit       = $limit_param > 0 ? min( $limit_param, self::MAX_SEARCH_RESULTS ) : 20;
		$results     = array_slice( $results, 0, $limit );

		return new \WP_REST_Response(
			array(
				'query'   => $q,
				'results' => $results,
				'total'   => count( $results ),
			),
			200
		);
	}

	/**
	 * Ordered view over all bundles: bundle metadata + concept metadata rows,
	 * plus the newest bundle modification time across the knowledge root.
	 *
	 * Concept rows come from WP_MCP_AI_OKF_Reader::search() — metadata only,
	 * no bodies — so manifest and search stay cheap.
	 *
	 * @since 2.1.2
	 * @return array{
	 *     bundles: array<string, array{ modified: int, concepts: array<int, array<string, mixed>> }>,
	 *     modified: int
	 * }|\WP_Error
	 */
	private static function ordered_concepts() {
		$manager = self::get_bundle_manager();
		if ( is_wp_error( $manager ) ) {
			return $manager;
		}

		$bundles = $manager->list_bundles();
		if ( is_wp_error( $bundles ) ) {
			return $bundles;
		}

		$out      = array(
			'bundles'  => array(),
			'modified' => 0,
		);
		$modified = 0;

		foreach ( $bundles as $bundle ) {
			$reader = self::get_reader( $bundle['name'] );
			if ( is_wp_error( $reader ) ) {
				continue;
			}

			$concepts = $reader->search( array( 'include_stale' => true ) );
			if ( ! is_array( $concepts ) ) {
				$concepts = array();
			}

			// Concept order comes from a recursive directory iterator and is
			// filesystem-dependent. Sort by concept_id so the flattened
			// manifest order — and the prev/next neighbours that neighbours()
			// derives from it — is deterministic across environments.
			usort(
				$concepts,
				static function ( $a, $b ) {
					return strcmp( $a['concept_id'], $b['concept_id'] );
				}
			);

			$out['bundles'][ $bundle['name'] ] = array(
				'modified' => isset( $bundle['modified'] ) ? (int) $bundle['modified'] : 0,
				'concepts' => $concepts,
			);

			$modified = max( $modified, isset( $bundle['modified'] ) ? (int) $bundle['modified'] : 0 );
		}

		$out['modified'] = $modified;
		return $out;
	}

	/**
	 * Resolve prev/next slugs for a page from the flattened bundle order.
	 *
	 * @since 2.1.2
	 * @param string $slug Full bundle-prefixed slug.
	 * @return array{ prev: array{ slug: string, title: string }|null, next: array{ slug: string, title: string }|null }
	 */
	private static function neighbours( $slug ) {
		$ordered = self::ordered_concepts();
		$flat    = array();

		if ( ! is_wp_error( $ordered ) ) {
			foreach ( $ordered['bundles'] as $bundle_name => $bundle_meta ) {
				foreach ( $bundle_meta['concepts'] as $concept ) {
					$flat[] = array(
						'slug'  => $bundle_name . '/' . $concept['concept_id'],
						'title' => isset( $concept['title'] ) ? (string) $concept['title'] : $concept['concept_id'],
					);
				}
			}
		}

		$prev  = null;
		$next  = null;
		$count = count( $flat );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( $slug !== $flat[ $i ]['slug'] ) {
				continue;
			}
			if ( $i > 0 ) {
				$prev = $flat[ $i - 1 ];
			}
			if ( $i + 1 < $count ) {
				$next = $flat[ $i + 1 ];
			}
			break;
		}

		return array(
			'prev' => $prev,
			'next' => $next,
		);
	}

	/**
	 * Newest modification time for a single bundle.
	 *
	 * @since 2.1.2
	 * @param string $bundle Bundle name.
	 * @return int Unix timestamp (0 when unknown).
	 */
	private static function bundle_modified( $bundle ) {
		$manager = self::get_bundle_manager();
		if ( is_wp_error( $manager ) ) {
			return 0;
		}
		$bundles = $manager->list_bundles();
		if ( is_wp_error( $bundles ) ) {
			return 0;
		}
		foreach ( $bundles as $entry ) {
			if ( $bundle === $entry['name'] ) {
				return isset( $entry['modified'] ) ? (int) $entry['modified'] : 0;
			}
		}
		return 0;
	}

	/**
	 * Rewrite internal `.md` cross-links into island hash routes.
	 *
	 * Resolution order mirrors the Docs Hub server: same-bundle relative
	 * paths first, then a knowledge-root-relative path whose first segment is
	 * a known bundle name. Links that resolve to no known concept are left
	 * untouched so the browser keeps its default behaviour.
	 *
	 * @since 2.1.2
	 * @param string $body    Markdown body.
	 * @param string $bundle  Owning bundle name.
	 * @param string $concept Owning concept id (bundle-relative, may contain '/').
	 * @return string Body with cross-links rewritten to `#/{bundle}/{target}` routes.
	 */
	private static function rewrite_cross_links( $body, $bundle, $concept ) {
		return preg_replace_callback(
			'/\[([^\]]*)\]\(([^)]+\.md)(#[^)]*)?\)/',
			function ( $matches ) use ( $bundle, $concept ) {
				$label  = $matches[1];
				$target = $matches[2];
				$frag   = isset( $matches[3] ) ? $matches[3] : '';

				$resolved = self::resolve_link( $target, $bundle, $concept );
				if ( null === $resolved ) {
					return $matches[0];
				}

				return '[' . $label . '](#/' . $resolved[0] . '/' . $resolved[1] . $frag . ')';
			},
			$body
		);
	}

	/**
	 * Resolve a `.md` link target to a bundle + concept pair, or null.
	 *
	 * Mirrors WP_MCP_AI_OKF_Reader::resolve_link_target() (OKF v0.2 §6.1):
	 * a leading `/` is bundle-root-relative; anything else resolves against
	 * the concept's directory. Paths that escape the bundle root are
	 * interpreted knowledge-root-relative (first segment = bundle name) so
	 * cross-bundle links work too.
	 *
	 * @since 2.1.2
	 * @param string $target  Raw link target (may be relative or start with /).
	 * @param string $bundle  Owning bundle name.
	 * @param string $concept Owning concept id.
	 * @return array{0: string, 1: string}|null Bundle + concept id when the target exists.
	 */
	private static function resolve_link( $target, $bundle, $concept ) {
		$target = trim( $target );

		// External URI schemes are never internal links.
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $target ) ) {
			return null;
		}

		// Strip the .md suffix (fragments are handled by the caller's regex).
		if ( '.md' === substr( $target, -3 ) ) {
			$target = substr( $target, 0, -3 );
		}

		$normal = '';
		if ( 0 === strpos( $target, '/' ) ) {
			// Bundle-root-relative absolute link (OKF v0.2 §6.1).
			$normal = self::normalize_path( ltrim( $target, '/' ) );
		} else {
			// Relative link: resolve against the concept's own directory.
			$base   = dirname( $concept );
			$normal = self::normalize_path( ( '.' === $base ? '' : $base . '/' ) . $target );
		}

		if ( '' === $normal ) {
			return null;
		}

		// Same-bundle candidate when the path stays inside the bundle.
		if ( false === strpos( $normal, '../' ) ) {
			if ( self::concept_exists( $bundle, $normal ) ) {
				return array( $bundle, $normal );
			}
			return null;
		}

		// Escaped the bundle root → knowledge-root-relative interpretation
		// (cross-bundle link): the first remaining segment is the bundle name.
		$segments = explode( '/', $normal );
		while ( isset( $segments[0] ) && '..' === $segments[0] ) {
			array_shift( $segments );
		}
		$root_normal = implode( '/', $segments );
		$split       = explode( '/', $root_normal, 2 );
		if ( isset( $split[0], $split[1] ) && self::concept_exists( $split[0], $split[1] ) ) {
			return array( $split[0], $split[1] );
		}

		return null;
	}

	/**
	 * Whether a bundle + concept pair exists.
	 *
	 * @since 2.1.2
	 * @param string $bundle  Bundle name.
	 * @param string $concept Concept id.
	 * @return bool
	 */
	private static function concept_exists( $bundle, $concept ) {
		$reader = self::get_reader( $bundle );
		if ( is_wp_error( $reader ) ) {
			return false;
		}
		return ! is_wp_error( $reader->get_concept( $concept ) );
	}

	/**
	 * Normalize a relative path (collapse `.` / `..` segments).
	 *
	 * @since 2.1.2
	 * @param string $path Raw path.
	 * @return string Normalized path (may still contain leading `../` when it escapes the root).
	 */
	private static function normalize_path( $path ) {
		$segments = explode( '/', str_replace( '\\', '/', $path ) );
		$out      = array();
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( ! empty( $out ) && '..' !== end( $out ) ) {
					array_pop( $out );
				} else {
					$out[] = '..';
				}
				continue;
			}
			$out[] = $segment;
		}
		return implode( '/', $out );
	}

	/**
	 * Extract the heading tree for the right-hand TOC pane.
	 *
	 * Slug generation mirrors rehype-slug's default urlize (lowercase, spaces
	 * to dashes, non-word characters removed) so in-page anchor clicks hit the
	 * ids the island's markdown pipeline generates.
	 *
	 * @since 2.1.2
	 * @param string $body Markdown body.
	 * @return array<int, array{ level: int, text: string, anchor: string }>
	 */
	private static function extract_toc( $body ) {
		$toc    = array();
		$counts = array();

		if ( ! preg_match_all( '/^(#{1,4})[ \t]+(.+?)[ \t]*$/m', $body, $matches, PREG_SET_ORDER ) ) {
			return $toc;
		}

		foreach ( $matches as $match ) {
			$text = trim( $match[2] );
			$text = preg_replace( '/\[([^\]]*)\]\([^)]*\)/', '$1', $text ); // Strip link syntax.
			$text = trim( preg_replace( '/[*_`~]/', '', $text ) );
			if ( '' === $text ) {
				continue;
			}

			$anchor = self::slugify( $text );
			if ( isset( $counts[ $anchor ] ) ) {
				++$counts[ $anchor ];
				$anchor .= '-' . $counts[ $anchor ];
			} else {
				$counts[ $anchor ] = 0;
			}

			$toc[] = array(
				'level'  => strlen( $match[1] ),
				'text'   => $text,
				'anchor' => $anchor,
			);
		}

		return $toc;
	}

	/**
	 * URL-slugify a heading (rehype-slug urlize equivalent).
	 *
	 * @since 2.1.2
	 * @param string $text Heading text.
	 * @return string Anchor slug.
	 */
	private static function slugify( $text ) {
		$slug = mb_strtolower( $text );
		$slug = preg_replace( '/[^\p{L}\p{N}\s_-]+/u', '', $slug );
		$slug = preg_replace( '/[\s_]+/u', '-', $slug );
		return trim( $slug, '-' );
	}

	/**
	 * Build a plain-text excerpt from a markdown body.
	 *
	 * @since 2.1.2
	 * @param string $body Markdown body.
	 * @return string Plain-text excerpt.
	 */
	private static function make_excerpt( $body ) {
		$text = preg_replace( '/```[\s\S]*?```/', ' ', $body );
		$text = preg_replace( '/\[([^\]]*)\]\([^)]*\)/', '$1', (string) $text );
		$text = preg_replace( '/^#{1,6}[ \t]+/m', '', (string) $text );
		$text = preg_replace( '/[*_`~>|]/', ' ', (string) $text );
		$text = preg_replace( '/\s+/', ' ', (string) $text );
		$text = trim( (string) $text );

		if ( function_exists( 'mb_substr' ) && mb_strlen( $text ) > self::EXCERPT_LENGTH ) {
			$text = mb_substr( $text, 0, self::EXCERPT_LENGTH ) . '…';
		} elseif ( strlen( $text ) > self::EXCERPT_LENGTH ) {
			$text = substr( $text, 0, self::EXCERPT_LENGTH ) . '…';
		}

		return $text;
	}

	/**
	 * Lazily obtain the OKF bundle manager.
	 *
	 * @since 2.1.2
	 * @return WP_MCP_AI_OKF_Bundle_Manager|\WP_Error
	 */
	private static function get_bundle_manager() {
		if ( ! class_exists( 'WP_MCP_AI_OKF_Bundle_Manager' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_okf_unavailable',
				__( 'The OKF engine is not available.', 'mcp-ai-wpoos-pro' )
			);
		}
		return new WP_MCP_AI_OKF_Bundle_Manager();
	}

	/**
	 * Resolve a bundle name to a reader instance.
	 *
	 * @since 2.1.2
	 * @param string $bundle Bundle name.
	 * @return WP_MCP_AI_OKF_Reader|\WP_Error
	 */
	private static function get_reader( $bundle ) {
		$manager = self::get_bundle_manager();
		if ( is_wp_error( $manager ) ) {
			return $manager;
		}

		$root = $manager->resolve_bundle_root( $bundle );
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		return new WP_MCP_AI_OKF_Reader( $root );
	}
}
