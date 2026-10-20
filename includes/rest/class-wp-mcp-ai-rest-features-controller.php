<?php
/**
 * REST controller for the feature-availability surface.
 *
 * Serves GET /mcp-ai/v1/features so headless surfaces (the Complete Console,
 * the standalone Pro SPA) can gate navigation on what the target WordPress
 * site actually has installed — e.g. hide the Docs item when docs-hub is
 * absent. The map is computed by WP_MCP_AI_Feature_Flags; each surface still
 * enforces its own capability server-side, so the read is available to any
 * logged-in user.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Features REST controller.
 */
class WP_MCP_AI_REST_Features_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	const ROUTE_BASE = '/features';

	/**
	 * Register the features route.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_features' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Permission check — feature presence is not secret, but the console is
	 * an authenticated surface: any logged-in user may read the map.
	 *
	 * @since 2.4.0
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You must be logged in to read feature availability.', 'mcp-ai-wpoos' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * GET /features — the server-side availability map.
	 *
	 * @since 2.4.0
	 * @return WP_REST_Response
	 */
	public function get_features() {
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'features' => WP_MCP_AI_Feature_Flags::all(),
				),
			)
		);
	}
}
