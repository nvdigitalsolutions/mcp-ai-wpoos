<?php
/**
 * NV oOS Canvas Toolkit — REST API Controller
 *
 * @package NV_oOS_Canvas_Toolkit
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for the NV oOS Canvas Toolkit addon.
 *
 * @since 0.1.0
 */
class NV_oOS_Canvas_Toolkit_REST {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'nvoos-canvas-toolkit/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'health' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);
	}

	/**
	 * Manage_options gate.
	 *
	 * @return bool|WP_Error
	 */
	public static function admin_permission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'forbidden', __( 'You do not have permission to access this endpoint.', 'nvoos-canvas-toolkit' ), array( 'status' => 403 ) );
	}

	/**
	 * Health endpoint.
	 *
	 * @return WP_REST_Response
	 */
	public static function health() {
		return rest_ensure_response(
			array(
				'status'  => 'ok',
				'version' => defined( 'NVOOS_CANVAS_TOOLKIT_VERSION' ) ? NVOOS_CANVAS_TOOLKIT_VERSION : 'unknown',
			)
		);
	}
}
