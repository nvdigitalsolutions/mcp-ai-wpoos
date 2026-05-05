<?php
/**
 * NV oOS Skote — REST Bridge
 *
 * Minimal Phase-1 stubs for the bridge surfaces. These return clear
 * `not_implemented` errors so the SPA can render placeholder UI today and we
 * can swap in real proxies in Phase 3 / Phase 4 without breaking shape.
 *
 * Routes:
 *   GET  /bridge/wp/users        — Phase 3 — proxy of `wp/v2/users`.
 *   GET  /bridge/wc/(?P<resource>[a-z\-]+)
 *                                — Phase 4 — WooCommerce read proxy.
 *   GET  /bridge/jet/cct/(?P<slug>[a-z0-9_-]+)
 *                                — Phase 4 — JetEngine CCT read proxy
 *                                  (table prefix `jet_cct_` UNDERSCORES).
 *   GET  /bridge/cpt/(?P<post_type>[a-z0-9_-]+)
 *                                — Phase 3 — generic CPT CRUD; restricted to
 *                                  the site allowlist option
 *                                  `nvoos_skote_allowed_cpts`.
 *
 * @package NV_oOS_Skote
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridge controller.
 *
 * @since 0.1.0
 */
class NVOOS_Skote_REST_Bridge extends NVOOS_Skote_REST_Base {

	/**
	 * Register bridge routes.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			NVOOS_SKOTE_REST_NAMESPACE,
			'/bridge/wp/users',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_users' ),
				'permission_callback' => self::require_cap( 'list_users' ),
			)
		);

		register_rest_route(
			NVOOS_SKOTE_REST_NAMESPACE,
			'/bridge/wc/(?P<resource>[a-z\-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_wc_resource' ),
				'permission_callback' => self::require_cap( 'manage_woocommerce' ),
				'args'                => array(
					'resource' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( __CLASS__, 'sanitize_slug' ),
					),
				),
			)
		);

		register_rest_route(
			NVOOS_SKOTE_REST_NAMESPACE,
			'/bridge/jet/cct/(?P<slug>[a-z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_jet_cct' ),
				'permission_callback' => self::require_cap( 'edit_posts' ),
				'args'                => array(
					'slug' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( __CLASS__, 'sanitize_slug' ),
					),
				),
			)
		);

		register_rest_route(
			NVOOS_SKOTE_REST_NAMESPACE,
			'/bridge/cpt/(?P<post_type>[a-z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_cpt' ),
				'permission_callback' => array( __CLASS__, 'check_cpt_permission' ),
				'args'                => array(
					'post_type' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( __CLASS__, 'sanitize_slug' ),
					),
				),
			)
		);
	}

	/**
	 * Permission callback for the generic CPT bridge.
	 *
	 * Requires the post type to be in the allowlist option AND the current
	 * user to hold the post type's `edit_posts` cap.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return true|WP_Error
	 */
	public static function check_cpt_permission( $request ) {
		$post_type = self::sanitize_slug( $request['post_type'] );
		$allowed   = self::get_allowed_post_types();
		if ( ! in_array( $post_type, $allowed, true ) ) {
			return self::error(
				'nvoos_skote_cpt_not_allowed',
				__( 'This post type is not exposed to the Skote bridge.', 'nvoos-skote' ),
				rest_authorization_required_code()
			);
		}
		$pt_obj = get_post_type_object( $post_type );
		if ( ! $pt_obj || ! current_user_can( $pt_obj->cap->edit_posts ) ) {
			return self::error(
				'nvoos_skote_forbidden',
				__( 'You do not have permission for this post type.', 'nvoos-skote' ),
				rest_authorization_required_code()
			);
		}
		// Cookie-auth nonce sanity-check.
		$cookie_check = rest_cookie_check_errors( null );
		if ( is_wp_error( $cookie_check ) ) {
			return $cookie_check;
		}
		return true;
	}

	/**
	 * Resolve the post-type allowlist from the option.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public static function get_allowed_post_types() {
		$raw = get_option( NV_oOS_Skote::OPTION_ALLOWED_CPTS, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$clean = array();
		foreach ( $raw as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' !== $slug ) {
				$clean[] = $slug;
			}
		}
		/**
		 * Filters the post-type allowlist exposed via the bridge.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $clean Sanitised allowlist from the option.
		 */
		return array_values( array_unique( (array) apply_filters( 'nvoos_skote_allowed_cpts', $clean ) ) );
	}

	// -------------------------------------------------------------------
	// Phase-1 callbacks: respond with informative `not_implemented` errors
	// instead of fabricating data, so the SPA shows a useful empty state.
	// -------------------------------------------------------------------

	/**
	 * GET /bridge/wp/users.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_users( $request ) {
		unset( $request );
		return self::success(
			array(),
			array( 'phase' => 'phase-1-stub' )
		);
	}

	/**
	 * GET /bridge/wc/{resource}.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_wc_resource( $request ) {
		if ( ! NV_oOS_Skote::is_woocommerce_active() ) {
			return self::error(
				'nvoos_skote_wc_inactive',
				__( 'WooCommerce is not active on this site.', 'nvoos-skote' ),
				404
			);
		}
		$resource = self::sanitize_slug( $request['resource'] );
		return self::success(
			array(),
			array(
				'phase'    => 'phase-1-stub',
				'resource' => $resource,
			)
		);
	}

	/**
	 * GET /bridge/jet/cct/{slug}.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_jet_cct( $request ) {
		if ( ! NV_oOS_Skote::is_jetengine_active() ) {
			return self::error(
				'nvoos_skote_jetengine_inactive',
				__( 'JetEngine is not active on this site.', 'nvoos-skote' ),
				404
			);
		}
		$slug = self::sanitize_slug( $request['slug'] );
		return self::success(
			array(),
			array(
				'phase' => 'phase-1-stub',
				'slug'  => $slug,
			)
		);
	}

	/**
	 * GET /bridge/cpt/{post_type}.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_cpt( $request ) {
		$post_type = self::sanitize_slug( $request['post_type'] );
		$query     = new WP_Query(
			array(
				'post_type'      => $post_type,
				'posts_per_page' => 20,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$ids = array_map( 'intval', $query->posts );
		return self::success(
			$ids,
			array(
				'phase'      => 'phase-1-stub',
				'post_type'  => $post_type,
				'count'      => count( $ids ),
			)
		);
	}
}
