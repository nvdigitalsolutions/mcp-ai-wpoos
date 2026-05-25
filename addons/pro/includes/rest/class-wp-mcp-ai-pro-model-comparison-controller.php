<?php
/**
 * Pro Model Comparison REST Controller — Endpoint for multi-model
 * parallel generation and comparison.
 *
 * @package NV_oOS_Pro
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Pro_Model_Comparison_Controller
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Pro_Model_Comparison_Controller {

	/**
	 * Register REST routes.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'mcp-ai-pro/v1',
			'/threads/(?P<thread_id>\d+)/compare-models',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'compare_models' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'message' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'models'  => array(
						'type'     => 'array',
						'required' => false,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'provider' => array( 'type' => 'string' ),
								'model'    => array( 'type' => 'string' ),
							),
						),
					),
				),
			)
		);

		// Endpoint to get available alternative models.
		register_rest_route(
			'mcp-ai-pro/v1',
			'/model-alternatives',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_alternatives' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * @since 1.7.0
	 * @return bool|WP_Error
	 */
	public static function check_permission() {
		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Get available alternative models.
	 *
	 * GET /mcp-ai-pro/v1/model-alternatives
	 *
	 * @since 1.7.0
	 * @return WP_REST_Response
	 */
	public static function get_alternatives() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Parallel_Model_Dispatcher' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-parallel-model-dispatcher.php';
		}

		$dispatcher   = new WP_MCP_AI_Pro_Parallel_Model_Dispatcher();
		$alternatives = $dispatcher->get_available_alternatives();

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'alternatives' => $alternatives,
				),
			)
		);
	}

	/**
	 * Compare model responses for the same prompt.
	 *
	 * POST /mcp-ai-pro/v1/threads/{thread_id}/compare-models
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function compare_models( $request ) {
		$thread_id = absint( $request->get_param( 'thread_id' ) );
		$message   = $request->get_param( 'message' );
		$models    = $request->get_param( 'models' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Parallel_Model_Dispatcher' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-parallel-model-dispatcher.php';
		}

		$dispatcher = new WP_MCP_AI_Pro_Parallel_Model_Dispatcher();

		// Use provided models or fall back to defaults.
		if ( empty( $models ) || ! is_array( $models ) ) {
			$models = $dispatcher->get_available_alternatives();
		}

		// Build messages array.
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a helpful AI assistant. Provide clear, accurate, and well-structured responses.',
			),
			array(
				'role'    => 'user',
				'content' => $message,
			),
		);

		// If thread_id is provided, include thread context.
		if ( $thread_id > 0 && class_exists( 'WP_MCP_AI_Thread_Manager' ) ) {
			$thread_manager = new WP_MCP_AI_Thread_Manager();
			$context        = $thread_manager->get_thread_context( $thread_id, 10 );

			if ( ! empty( $context ) ) {
				// Insert context before the user message.
				array_splice( $messages, 1, 0, $context );
			}
		}

		$result = $dispatcher->dispatch(
			$messages,
			$models,
			array(
				'temperature' => 0.7,
				'max_tokens'  => 2048,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
