<?php
/**
 * Pro SPA REST Controller — OKF Skills & Knowledge.
 *
 * Read-only browse surface for the Pro SPA v2 Skills/OKF drawer:
 *
 *   GET /mcp-ai-pro/v1/okf/bundles
 *   GET /mcp-ai-pro/v1/okf/bundles/{bundle}/concepts?q=&type=&status=&trust_tier=&include_stale=&limit=
 *   GET /mcp-ai-pro/v1/okf/bundles/{bundle}/concepts/{concept}
 *   GET /mcp-ai-pro/v1/okf/search?q=
 *   GET /mcp-ai-pro/v1/okf/skills?assistant_id=N
 *
 * All routes require a logged-in user with the `read` capability, matching
 * the Base OKF tool surface. Bundle filesystem paths are never exposed;
 * concept bodies are curated markdown rendered client-side through DOMPurify.
 *
 * @package NV_oOS_Pro
 * @since   2.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Pro_REST_Okf
 *
 * @since 2.1.1
 */
class WP_MCP_AI_Pro_REST_Okf {

	/**
	 * REST namespace.
	 *
	 * @since 2.1.1
	 * @var string
	 */
	const NAMESPACE = 'mcp-ai-pro/v1';

	/**
	 * Route base.
	 *
	 * @since 2.1.1
	 * @var string
	 */
	const ROUTE = '/okf';

	/**
	 * Maximum concepts returned per bundle listing.
	 *
	 * @since 2.1.1
	 * @var int
	 */
	const MAX_CONCEPTS = 200;

	/**
	 * Maximum cross-bundle search results.
	 *
	 * @since 2.1.1
	 * @var int
	 */
	const MAX_SEARCH_RESULTS = 50;

	/**
	 * Register REST routes.
	 *
	 * @since 2.1.1
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/bundles',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_bundles' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/bundles/(?P<bundle>[a-z0-9][a-z0-9_-]{0,99})/concepts',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_concepts' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'args'                => self::get_concept_query_args(),
			)
		);

		// The `%` alternative accepts percent-encoded concept IDs: the SPA
		// encodes IDs containing `/` (e.g. `wp-plugin-cron/SKILL` →
		// `wp-plugin-cron%2FSKILL`), and WordPress core can hand the route
		// matcher the still-encoded path depending on the server stack.
		// `handle_concept()` decodes the captured value before lookup.
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/bundles/(?P<bundle>[a-z0-9][a-z0-9_-]{0,99})/concepts/(?P<concept>[a-zA-Z0-9_\-\/\.%]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_concept' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
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
					'q' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/skills',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_skills' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'args'                => array(
					'assistant_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
				),
			)
		);
	}

	/**
	 * Shared query args for the concept listing route.
	 *
	 * @since 2.1.1
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_concept_query_args() {
		return array(
			'q'             => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'type'          => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'status'        => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'trust_tier'    => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'include_stale' => array(
				'type'     => 'boolean',
				'required' => false,
				'default'  => true,
			),
			'limit'         => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
				'default'           => 100,
				'maximum'           => self::MAX_CONCEPTS,
			),
		);
	}

	/**
	 * Permission check — user must be logged in with `read` capability.
	 *
	 * @since 2.1.1
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error
	 */
	public static function permission_check( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'read' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have sufficient permissions.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle GET /okf/bundles.
	 *
	 * @since 2.1.1
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_bundles( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$manager = self::get_bundle_manager();
		if ( is_wp_error( $manager ) ) {
			return $manager;
		}

		$bundles = $manager->list_bundles();
		if ( is_wp_error( $bundles ) ) {
			return $bundles;
		}

		$escaped = array();
		foreach ( $bundles as $bundle ) {
			$escaped[] = array(
				'name'             => $bundle['name'],
				'protected'        => (bool) $bundle['protected'],
				'concept_count'    => (int) $bundle['concept_count'],
				'stale_count'      => (int) $bundle['stale_count'],
				'deprecated_count' => (int) $bundle['deprecated_count'],
				'conformant'       => (bool) $bundle['conformant'],
				'issue_count'      => (int) $bundle['issue_count'],
				'types'            => array_values( (array) $bundle['types'] ),
				'trust_tiers'      => array_map( 'absint', (array) $bundle['trust_tiers'] ),
				'modified'         => (int) $bundle['modified'],
			);
		}

		return new \WP_REST_Response( array( 'bundles' => $escaped ), 200 );
	}

	/**
	 * Handle GET /okf/bundles/{bundle}/concepts.
	 *
	 * @since 2.1.1
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_concepts( $request ) {
		$bundle = $request->get_param( 'bundle' );

		$reader = self::get_reader( $bundle );
		if ( is_wp_error( $reader ) ) {
			return $reader;
		}

		$criteria = array();
		$type     = $request->get_param( 'type' );
		$status   = $request->get_param( 'status' );
		$tier     = $request->get_param( 'trust_tier' );

		if ( $type ) {
			$criteria['type'] = $type;
		}
		if ( $status ) {
			$criteria['status'] = $status;
		}
		if ( $tier ) {
			$criteria['trust_tier'] = $tier;
		}
		$include_stale_param       = $request->get_param( 'include_stale' );
		$criteria['include_stale'] = null !== $include_stale_param ? (bool) $include_stale_param : true;

		$concepts = $reader->search( $criteria );

		// Free-text filter across title, description, tags, and concept ID.
		$q = (string) $request->get_param( 'q' );
		if ( '' !== $q ) {
			$needle   = mb_strtolower( $q );
			$concepts = array_values(
				array_filter(
					$concepts,
					function ( $concept ) use ( $needle ) {
						$haystack = mb_strtolower(
							implode(
								' ',
								array_filter(
									array(
										$concept['concept_id'],
										$concept['title'],
										$concept['description'],
										is_array( $concept['tags'] ) ? implode( ' ', $concept['tags'] ) : '',
									)
								)
							)
						);
						return false !== mb_strpos( $haystack, $needle );
					}
				)
			);
		}

		$limit_param = (int) $request->get_param( 'limit' );
		$limit       = $limit_param > 0 ? min( $limit_param, self::MAX_CONCEPTS ) : 100;
		$concepts    = array_slice( $concepts, 0, $limit );

		return new \WP_REST_Response(
			array(
				'bundle'   => $bundle,
				'concepts' => $concepts,
				'total'    => count( $concepts ),
			),
			200
		);
	}

	/**
	 * Handle GET /okf/bundles/{bundle}/concepts/{concept}.
	 *
	 * @since 2.1.1
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_concept( $request ) {
		$bundle = $request->get_param( 'bundle' );

		// Concept IDs arrive URL-encoded from the SPA (slashes become %2F).
		// rawurldecode() restores the bundle-relative path without turning
		// literal `+` characters into spaces.
		$concept_id = rawurldecode( (string) $request->get_param( 'concept' ) );

		$reader = self::get_reader( $bundle );
		if ( is_wp_error( $reader ) ) {
			return $reader;
		}

		$concept = $reader->get_concept( $concept_id );
		if ( is_wp_error( $concept ) ) {
			return $concept;
		}

		$fm = $concept['frontmatter'];

		return new \WP_REST_Response(
			array(
				'bundle'      => $bundle,
				'concept_id'  => $concept['concept_id'],
				'frontmatter' => self::allowlisted_frontmatter( $fm ),
				'body'        => $concept['body'],
				'links'       => self::extract_cross_links( $concept['body'] ),
				'trust_tier'  => $reader->get_trust_tier( $fm ),
				'stale'       => $reader->is_stale( $fm ),
			),
			200
		);
	}

	/**
	 * Handle GET /okf/search — cross-bundle concept search.
	 *
	 * @since 2.1.1
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_search( $request ) {
		$q = $request->get_param( 'q' );
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

		$manager = self::get_bundle_manager();
		if ( is_wp_error( $manager ) ) {
			return $manager;
		}

		$bundles = $manager->list_bundles();
		if ( is_wp_error( $bundles ) ) {
			return $bundles;
		}

		$needle  = mb_strtolower( $q );
		$results = array();

		foreach ( $bundles as $bundle ) {
			$reader = self::get_reader( $bundle['name'] );
			if ( is_wp_error( $reader ) ) {
				continue;
			}

			$concepts = $reader->search( array( 'include_stale' => true ) );
			foreach ( $concepts as $concept ) {
				$haystack = mb_strtolower(
					implode(
						' ',
						array_filter(
							array(
								$concept['concept_id'],
								$concept['title'],
								$concept['description'],
								is_array( $concept['tags'] ) ? implode( ' ', $concept['tags'] ) : '',
							)
						)
					)
				);
				if ( false === mb_strpos( $haystack, $needle ) ) {
					continue;
				}

				$results[] = array_merge(
					array( 'bundle' => $bundle['name'] ),
					$concept
				);

				if ( count( $results ) >= self::MAX_SEARCH_RESULTS ) {
					break 2;
				}
			}
		}

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
	 * Handle GET /okf/skills — the assistant's granted OKF concepts, skill-shaped.
	 *
	 * Each grant is resolved through WP_MCP_AI_OKF_Skill_Bridge so the exact
	 * same allow-list / lifecycle / trust gates the `load_skill` tool enforces
	 * determine what the drawer reports as loadable.
	 *
	 * @since 2.1.1
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_skills( $request ) {
		$assistant_id = (int) $request->get_param( 'assistant_id' );

		if ( ! $assistant_id ) {
			$user_id = get_current_user_id();
			if ( class_exists( 'WP_MCP_AI_Assistant_Manager' ) ) {
				$assistant_id = (int) \WP_MCP_AI_Assistant_Manager::get_default_assistant( $user_id );
			}
		}

		if ( ! $assistant_id ) {
			return new \WP_REST_Response(
				array(
					'assistant_id' => 0,
					'skills'       => array(),
				),
				200
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_OKF_Skill_Bridge' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_okf_bridge_missing',
				__( 'The OKF skill bridge is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$grants = get_post_meta( $assistant_id, WP_MCP_AI_OKF_Skill_Bridge::META_GRANTS, true );
		if ( ! is_array( $grants ) ) {
			$grants = array();
		}

		$skills = array();
		foreach ( $grants as $grant ) {
			$grant = (string) $grant;
			if ( '' === $grant ) {
				continue;
			}

			list( $bundle, $concept_id ) = self::parse_grant( $grant );
			if ( '' === $bundle || '' === $concept_id ) {
				continue;
			}

			$entry = array(
				'name'        => $grant,
				'bundle'      => $bundle,
				'concept_id'  => $concept_id,
				'title'       => $grant,
				'description' => '',
				'type'        => '',
				'status'      => 'stable',
				'trust_tier'  => 'unverified',
				'stale'       => false,
				'loadable'    => false,
				'error'       => '',
			);

			// Authoritative gate: the same resolver the load_skill tool uses.
			$resolved = WP_MCP_AI_OKF_Skill_Bridge::resolve( null, $grant, $assistant_id );
			if ( is_wp_error( $resolved ) ) {
				$entry['error'] = $resolved->get_error_message();
				$skills[]       = $entry;
				continue;
			}
			if ( ! is_array( $resolved ) ) {
				continue; // Deferred to another source; not OKF content.
			}

			$entry['loadable']    = true;
			$entry['description'] = isset( $resolved['description'] ) ? (string) $resolved['description'] : '';

			// Read-only metadata enrichment for the drawer badges.
			$reader = self::get_reader( $bundle );
			if ( ! is_wp_error( $reader ) ) {
				$concept = $reader->get_concept( $concept_id );
				if ( ! is_wp_error( $concept ) ) {
					$fm                  = $concept['frontmatter'];
					$entry['title']      = isset( $fm['title'] ) ? (string) $fm['title'] : $entry['title'];
					$entry['type']       = isset( $fm['type'] ) ? (string) $fm['type'] : '';
					$entry['status']     = isset( $fm['status'] ) ? strtolower( (string) $fm['status'] ) : 'stable';
					$entry['trust_tier'] = $reader->get_trust_tier( $fm );
					$entry['stale']      = $reader->is_stale( $fm );
				}
			}

			$skills[] = $entry;
		}

		return new \WP_REST_Response(
			array(
				'assistant_id' => $assistant_id,
				'skills'       => $skills,
			),
			200
		);
	}

	/**
	 * Lazily obtain the OKF bundle manager.
	 *
	 * @since 2.1.1
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
	 * @since 2.1.1
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

	/**
	 * Split a `bundle:concept_id` grant reference.
	 *
	 * @since 2.1.1
	 * @param string $grant Grant reference.
	 * @return array{0: string, 1: string} Bundle and concept ID ('' = malformed).
	 */
	private static function parse_grant( $grant ) {
		$colon = strpos( $grant, ':' );
		if ( false === $colon || 0 === $colon || strlen( $grant ) - 1 === $colon ) {
			return array( '', '' );
		}
		return array( substr( $grant, 0, $colon ), substr( $grant, $colon + 1 ) );
	}

	/**
	 * Allowlist the frontmatter keys exposed over REST.
	 *
	 * Frontmatter is curated metadata, but only the display-relevant subset is
	 * forwarded so unexpected/private keys never leave the server.
	 *
	 * @since 2.1.1
	 * @param array $frontmatter Parsed frontmatter.
	 * @return array<string, mixed>
	 */
	private static function allowlisted_frontmatter( $frontmatter ) {
		$allowed = array( 'title', 'description', 'type', 'tags', 'status', 'verified', 'stale_after', 'generated', 'usage_window' );

		$out = array();
		foreach ( $allowed as $key ) {
			if ( isset( $frontmatter[ $key ] ) ) {
				$out[ $key ] = $frontmatter[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Extract cross-links (concept IDs) from a concept body.
	 *
	 * Mirrors WP_MCP_AI_OKF_Reader::extract_concept_links (private) so the
	 * drawer can render navigable links without exposing the reader internals.
	 *
	 * @since 2.1.1
	 * @param string $body Markdown body text.
	 * @return string[] Concept IDs (without .md suffix), de-duplicated.
	 */
	private static function extract_cross_links( $body ) {
		$links = array();
		if ( preg_match_all( '/\[([^\]]*)\]\(([^)]+\.md)\)/', $body, $matches ) ) {
			foreach ( $matches[2] as $link_path ) {
				$link_path = preg_replace( '/#.*$/', '', $link_path );
				$link_path = ltrim( $link_path, '/' );
				// Strip the .md suffix (mirrors WP_MCP_AI_OKF_Reader::normalize_concept_id).
				if ( '.md' === substr( $link_path, -3 ) ) {
					$link_path = substr( $link_path, 0, -3 );
				}
				$link_path = rtrim( $link_path, '/' );
				if ( '' !== $link_path && ! in_array( $link_path, $links, true ) ) {
					$links[] = $link_path;
				}
			}
		}
		return $links;
	}
}
