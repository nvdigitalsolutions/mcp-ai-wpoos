<?php
/**
 * NV oOS Docs Hub — REST API Controller
 *
 * Provides REST endpoints for the documentation browser SPA.
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for the Docs Hub addon.
 *
 * @since 1.0.0
 */
class NV_oOS_Docs_Hub_REST {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'nvoos-docs/v1';

	/**
	 * Register all REST routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/manifest',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_manifest' ),
				'permission_callback' => array( __CLASS__, 'public_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/pages/(?P<slug>[a-z0-9_\-\/]{1,200})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_page' ),
				'permission_callback' => array( __CLASS__, 'public_permission' ),
				'args'                => array(
					'slug' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( __CLASS__, 'validate_slug' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'search' ),
				'permission_callback' => array( __CLASS__, 'public_permission' ),
				'args'                => array(
					'q'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/rebuild',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rebuild' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
				'args'                => array(
					'sync' => array(
						'description' => __( 'Run synchronously instead of enqueueing chunks.', 'nvoos-docs-hub' ),
						'type'        => 'boolean',
						'default'     => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/rebuild/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rebuild_status' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/rebuild/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rebuild_cancel' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/rebuild/resume',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rebuild_resume' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'health' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/remote/tree',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'remote_tree' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
				'args'                => array(
					'owner' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'repo'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'ref'   => array(
						'type'              => 'string',
						'default'           => 'HEAD',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'path'  => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'index' => array(
						'description'       => __( 'Index into the saved remote_repos array (so the persisted token can be reused without round-tripping it through the browser).', 'nvoos-docs-hub' ),
						'type'              => 'integer',
						'default'           => -1,
						'sanitize_callback' => static function ( $value ) {
							return (int) $value;
						},
					),
					'force' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * Permission callback for public read endpoints.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public static function public_permission( $request ) {
		$slug = $request->get_param( 'slug' );

		// When public access is disabled, require the user to be logged in.
		$settings = NV_oOS_Docs_Hub_Plugin::get_settings();
		if ( empty( $settings['public_access'] ) && ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to view this documentation.', 'nvoos-docs-hub' ),
				array( 'status' => 401 )
			);
		}

		/**
		 * Filter whether the current user can read a documentation section.
		 *
		 * Return false to deny access to a specific slug.
		 *
		 * @since 1.0.0
		 *
		 * @param bool   $can_read Whether the current user can read.
		 * @param string $slug     The requested page slug (empty for manifest/search).
		 */
		$can_read = apply_filters( 'nvoos_docs_hub_can_read_section', true, (string) $slug );

		if ( ! $can_read ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to view this documentation section.', 'nvoos-docs-hub' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for admin-only endpoints.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public static function admin_permission( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WordPress REST API.
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'Administrator access required.', 'nvoos-docs-hub' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Validate a page slug parameter.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Slug to validate.
	 * @return bool
	 */
	public static function validate_slug( $slug ) {
		return (bool) preg_match( '/^[a-z0-9_\-\/]{1,200}$/', (string) $slug );
	}

	/**
	 * GET /manifest — returns the documentation manifest.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_manifest( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$cache    = new NV_oOS_Docs_Hub_Cache();
		$manifest = $cache->get_manifest();

		// When the cache is empty, schedule an async rebuild instead of
		// running the full rebuild inline — a blocking sync rebuild inside
		// a REST request can exceed the PHP max_execution_time on large
		// repos and cause a critical error / white screen for the visitor.
		// The async path returns quickly; the visitor sees an empty index
		// while the background job populates it.
		if ( false === $manifest ) {
			// Only attempt auto-rebuild when an admin is logged in
			// (avoid triggering rebuilds for every anonymous visitor).
			if ( current_user_can( 'manage_options' ) ) {
				NV_oOS_Docs_Hub_Rebuild_Job::enqueue_async();
			}
		}

		if ( ! is_array( $manifest ) ) {
			$manifest = array(
				'version'       => NVOOS_DOCS_HUB_VERSION,
				'built_at'      => 0,
				'cache_version' => '0',
				'tree'          => array(),
				'slug_map'      => array(),
				'total_pages'   => 0,
				'broken_links'  => array(),
			);
		}

		// Strip .context/ entries for users without manage_options.
		if ( ! current_user_can( 'manage_options' ) && isset( $manifest['tree'] ) && is_array( $manifest['tree'] ) ) {
			$manifest['tree'] = array_values(
				array_filter(
					$manifest['tree'],
					static function ( $group ) {
						return 'context' !== ( $group['source'] ?? '' );
					}
				)
			);
			// Rebuild slug_map to remove context slugs.
			if ( isset( $manifest['slug_map'] ) && is_array( $manifest['slug_map'] ) ) {
				$allowed_slugs = array();
				foreach ( $manifest['tree'] as $group ) {
					foreach ( ( $group['pages'] ?? array() ) as $page ) {
						if ( isset( $page['slug'] ) ) {
							$allowed_slugs[ $page['slug'] ] = true;
						}
					}
				}
				$manifest['slug_map'] = array_intersect_key( $manifest['slug_map'], $allowed_slugs );
			}
			$manifest['total_pages'] = array_sum(
				array_map(
					static function ( $group ) {
						return count( $group['pages'] ?? array() );
					},
					$manifest['tree']
				)
			);
		}

		$response = rest_ensure_response( $manifest );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	/**
	 * GET /pages/{slug} — returns a single page payload.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_page( $request ) {
		$slug = sanitize_text_field( $request->get_param( 'slug' ) );

		if ( ! preg_match( '/^[a-z0-9_\-\/]{1,200}$/', $slug ) ) {
			return new WP_Error(
				'invalid_slug',
				__( 'Invalid page slug.', 'nvoos-docs-hub' ),
				array( 'status' => 400 )
			);
		}

		$cache   = new NV_oOS_Docs_Hub_Cache();
		$payload = $cache->get_page( $slug );

		if ( false === $payload ) {
			// When page cache is empty, schedule an async rebuild instead
			// of running the sync rebuild inline (same rationale as get_manifest).
			if ( current_user_can( 'manage_options' ) ) {
				NV_oOS_Docs_Hub_Rebuild_Job::enqueue_async();
			}
		}

		if ( ! is_array( $payload ) ) {
			return new WP_Error(
				'not_found',
				__( 'Page not found.', 'nvoos-docs-hub' ),
				array( 'status' => 404 )
			);
		}

		// Block .context/ pages for users without manage_options.
		if ( ! current_user_can( 'manage_options' ) && 'context' === ( $payload['source'] ?? '' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to view this documentation section.', 'nvoos-docs-hub' ),
				array( 'status' => 403 )
			);
		}

		$response = rest_ensure_response( $payload );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	/**
	 * GET /search — full-text search over the documentation.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function search( $request ) {
		$q     = sanitize_text_field( $request->get_param( 'q' ) );
		$limit = absint( $request->get_param( 'limit' ) );

		if ( '' === $q ) {
			return new WP_Error(
				'missing_query',
				__( 'Search query is required.', 'nvoos-docs-hub' ),
				array( 'status' => 400 )
			);
		}

		// Enforce maximum lengths.
		$q     = substr( $q, 0, 100 );
		$limit = min( $limit, 50 );
		if ( 0 === $limit ) {
			$limit = 20;
		}

		$cache        = new NV_oOS_Docs_Hub_Cache();
		$search_index = $cache->get_search_index();

		if ( ! is_array( $search_index ) ) {
			return rest_ensure_response(
				array(
					'results' => array(),
					'total'   => 0,
				)
			);
		}

		$results = self::run_search( $q, $limit, $search_index );

		$response = rest_ensure_response(
			array(
				'results' => $results,
				'total'   => count( $results ),
				'query'   => $q,
			)
		);
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	/**
	 * POST /rebuild — triggers a documentation rebuild.
	 *
	 * Async by default (returns 202-style queued response with the
	 * current job summary). Pass `?sync=1` to run inline — preserved
	 * for tests and CLI back-compat.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Async by default; sync via `?sync=1`.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rebuild( $request ) {
		// Verify nonce.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'invalid_nonce',
				__( 'Nonce verification failed.', 'nvoos-docs-hub' ),
				array( 'status' => 403 )
			);
		}

		$sync = filter_var( $request->get_param( 'sync' ), FILTER_VALIDATE_BOOLEAN );
		if ( $sync ) {
			$result = NV_oOS_Docs_Hub_Rebuild_Job::run();
			if ( ! $result['success'] ) {
				return new WP_Error(
					'rebuild_failed',
					! empty( $result['error'] ) ? (string) $result['error'] : __( 'Documentation rebuild failed.', 'nvoos-docs-hub' ),
					array( 'status' => 500 )
				);
			}
			return rest_ensure_response( $result );
		}

		$summary  = NV_oOS_Docs_Hub_Rebuild_Job::enqueue_async();
		$response = rest_ensure_response(
			array_merge(
				$summary,
				array( 'status' => 'queued' )
			)
		);
		$response->set_status( 202 );
		return $response;
	}

	/**
	 * GET /rebuild/status — returns the current rebuild progress snapshot.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function rebuild_status( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$response = rest_ensure_response( NV_oOS_Docs_Hub_Rebuild_State::to_summary() );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * POST /rebuild/cancel — cancels an in-flight rebuild.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rebuild_cancel( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		// Permission + wp_rest nonce already enforced by admin_permission()
		// + WordPress REST cookie-auth, identical to /rebuild.
		return rest_ensure_response( NV_oOS_Docs_Hub_Rebuild_Job::cancel_async() );
	}

	/**
	 * POST /rebuild/resume — resumes a stalled / failed rebuild.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rebuild_resume( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		// Permission + wp_rest nonce already enforced by admin_permission()
		// + WordPress REST cookie-auth, identical to /rebuild.
		return rest_ensure_response( NV_oOS_Docs_Hub_Rebuild_Job::resume_async() );
	}

	/**
	 * GET /health — returns system health information.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function health( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$cache    = new NV_oOS_Docs_Hub_Cache();
		$manifest = $cache->get_manifest();

		$total_pages  = is_array( $manifest ) ? ( $manifest['total_pages'] ?? 0 ) : 0;
		$broken_links = is_array( $manifest ) ? count( $manifest['broken_links'] ?? array() ) : 0;
		$last_built   = $cache->get_last_built();

		return rest_ensure_response(
			array(
				'total_pages'  => $total_pages,
				'broken_links' => $broken_links,
				'last_built'   => $last_built,
				'version'      => NVOOS_DOCS_HUB_VERSION,
				'rebuild'      => NV_oOS_Docs_Hub_Rebuild_State::to_summary(),
			)
		);
	}

	/**
	 * GET /remote/tree — list Markdown/txt files in a remote repo for the picker.
	 *
	 * Admin-only. When the optional `index` parameter points at a saved
	 * remote_repos entry, the persisted token is reused so the browser
	 * never has to round-trip a fresh PAT.
	 *
	 * @since 0.3.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function remote_tree( $request ) {
		try {
			$owner = (string) $request->get_param( 'owner' );
			$repo  = (string) $request->get_param( 'repo' );
			$ref   = (string) $request->get_param( 'ref' );
			$path  = (string) $request->get_param( 'path' );
			$index = (int) $request->get_param( 'index' );
			$force = (bool) $request->get_param( 'force' );

			// Reuse the persisted token (if any) instead of asking the browser to send it.
			$token    = '';
			$settings = NV_oOS_Docs_Hub_Plugin::get_settings();
			$repos    = isset( $settings['remote_repos'] ) && is_array( $settings['remote_repos'] )
				? $settings['remote_repos']
				: array();
			// Bounds-check the index against the saved repo list so a tampered request
			// can't reach into other array keys.
			if ( $index >= 0
				&& $index < count( $repos )
				&& isset( $repos[ $index ] )
				&& is_array( $repos[ $index ] )
				&& isset( $repos[ $index ]['token'] )
			) {
				$token = (string) $repos[ $index ]['token'];
			}

			$fetcher = new NV_oOS_Docs_Hub_Remote_Repo();
			$result  = $fetcher->fetch_tree_for_admin(
				array(
					'owner' => $owner,
					'repo'  => $repo,
					'ref'   => '' !== $ref ? $ref : 'HEAD',
					'path'  => $path,
					'token' => $token,
					'force' => $force,
				)
			);

			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 502 ) );
				return $result;
			}

			return rest_ensure_response( $result );
		} catch ( \Throwable $e ) {
			error_log(
				sprintf(
					'[NV oOS Docs Hub] remote_tree fatal: %s in %s:%d',
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				)
			);
			return new WP_Error(
				'nvoos_docs_hub_fetch_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Run a simple PHP-based search over the search index.
	 *
	 * @since 1.0.0
	 *
	 * @param string $q            Search query.
	 * @param int    $limit        Maximum results to return.
	 * @param array  $search_index Search index entries.
	 * @return array
	 */
	private static function run_search( $q, $limit, $search_index ) {
		$q_lower = strtolower( $q );
		$results = array();

		foreach ( $search_index as $entry ) {
			$title   = strtolower( $entry['title'] ?? '' );
			$excerpt = strtolower( $entry['excerpt'] ?? '' );
			$slug    = $entry['slug'] ?? '';

			$score = 0;

			if ( false !== strpos( $title, $q_lower ) ) {
				$score += 10;
			}

			if ( false !== strpos( $excerpt, $q_lower ) ) {
				$score += 5;
			}

			if ( $score > 0 ) {
				// Build a snippet around the match.
				$match_pos = strpos( $excerpt, $q_lower );
				$snippet   = $excerpt;
				if ( false !== $match_pos ) {
					$start   = max( 0, $match_pos - 60 );
					$snippet = substr( $excerpt, $start, 200 );
				}

				$results[] = array(
					'slug'        => $slug,
					'title'       => $entry['title'],
					'excerpt'     => $snippet,
					'plugin_name' => $entry['plugin_name'] ?? '',
					'source'      => $entry['source'] ?? '',
					'score'       => $score,
				);
			}

			if ( count( $results ) >= $limit * 3 ) {
				break;
			}
		}

		// Sort by score descending.
		usort(
			$results,
			function ( $a, $b ) {
				return $b['score'] - $a['score'];
			}
		);

		return array_slice( $results, 0, $limit );
	}
}
