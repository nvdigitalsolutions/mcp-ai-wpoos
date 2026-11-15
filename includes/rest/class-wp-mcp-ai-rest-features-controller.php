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
 *
 * @license GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
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
	 * The map is site-level (identical for every logged-in user), so shared
	 * caches — the Complete Console's BFF micro-cache, the Velocity CDN —
	 * may hold it for 60 s and absorb the console's boot-time fetch.
	 * Browsers hold it for the same 60 s: a freshly activated addon's nav
	 * item can lag up to one minute, matching the status endpoints'
	 * staleness window.
	 *
	 * `endpoints` is runtime truth read from the REST router: it lets the
	 * console degrade a surface with a reason instead of firing a request
	 * that 404s. `endpoint_reasons` classifies every key (so a `false` reads
	 * as expected or as a regression) and `endpoint_gaps` lists the subset
	 * this runtime is meant to serve and does not — it must stay empty.
	 *
	 * @since 2.4.0
	 * @since 1.2.22 Serves the capability manifest (v2) alongside `features`.
	 * @return WP_REST_Response
	 */
	public function get_features() {
		$manifest = WP_MCP_AI_Endpoint_Capabilities::manifest();
		$response = rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'features'                  => WP_MCP_AI_Feature_Flags::all(),
					'endpoints'                 => (object) $manifest['endpoints'],
					'endpoint_contract_version' => $manifest['version'],
					'endpoint_reasons'          => (object) $manifest['reasons'],
					'endpoint_gaps'             => $manifest['gaps'],
					'backend'                   => 'wordpress',
					'contract'                  => 'mcp-ai/v1',
				),
			)
		);

		$response->header( 'Cache-Control', 'public, max-age=60, s-maxage=60' );

		return $response;
	}
}
