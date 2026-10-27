<?php
/**
 * REST API controller for annotation dataset export (Proposal 091, Track E).
 *
 *   GET /mcp-ai/v1/annotations[?assistant_id=] — export annotations as an
 *   eval dataset consumable by `run_assistant_eval`.
 *
 * Admin-only (`manage_options`) — annotations carry user feedback on chat
 * content.
 *
 * @package WP_MCP_AI
 * @since   1.2.13
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent fatal error if WP_REST_Controller is not available yet.
if ( ! class_exists( 'WP_REST_Controller' ) ) {
	return;
}

/**
 * Annotation export REST controller.
 *
 * @since 1.2.13
 */
class WP_MCP_AI_REST_Annotation_Controller extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @since 1.2.13
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Capability required for every route.
	 *
	 * @since 1.2.13
	 * @var string
	 */
	const REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @since 1.2.13
	 */
	public function __construct() {
		// Routes are registered by calling register_routes() from the main REST controller.
	}

	/**
	 * Register the annotation routes.
	 *
	 * @since 1.2.13
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/annotations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_export' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'assistant_id' => array(
						'description' => __( 'Optional assistant filter.', 'mcp-ai-wpoos' ),
						'type'        => 'integer',
						'minimum'     => 0,
						'required'    => false,
						'default'     => 0,
					),
				),
			)
		);
	}

	/**
	 * Permission callback: admin-only.
	 *
	 * @since 1.2.13
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public function check_permission( $request ) {
		if ( ! current_user_can( self::REQUIRED_CAPABILITY ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You are not allowed to export annotations.', 'mcp-ai-wpoos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Export annotations as an eval dataset.
	 *
	 * @since 1.2.13
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_export( $request ) {
		$assistant_id = absint( $request->get_param( 'assistant_id' ) );

		return rest_ensure_response(
			array(
				'success'     => true,
				'assistant'   => $assistant_id,
				'annotations' => WP_MCP_AI_Annotation_Store::export_dataset( $assistant_id ),
			)
		);
	}
}
