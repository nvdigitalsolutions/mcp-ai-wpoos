<?php
/**
 * REST API endpoints for Security Training System.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security Training REST API class.
 *
 * Provides REST endpoints for ISO 27001 security training management.
 */
class WP_MCP_AI_Security_Training_REST {
	/**
	 * Namespace for REST API.
	 *
	 * @var string
	 */
	const NAMESPACE = 'mcp-ai/v1';

	/**
	 * Initialize REST API routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// Get training modules.
		register_rest_route(
			self::NAMESPACE,
			'/training/modules',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_modules' ),
				'permission_callback' => array( $this, 'check_user_permission' ),
			)
		);

		// Get user completions.
		register_rest_route(
			self::NAMESPACE,
			'/training/completions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_user_completions' ),
				'permission_callback' => array( $this, 'check_user_permission' ),
			)
		);

		// Record completion.
		register_rest_route(
			self::NAMESPACE,
			'/training/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'record_completion' ),
				'permission_callback' => array( $this, 'check_user_permission' ),
				'args'                => array(
					'module_id' => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
					'score'     => array(
						'required'          => false,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param >= 0 && $param <= 100;
						},
					),
				),
			)
		);

		// Get training statistics (admin only).
		register_rest_route(
			self::NAMESPACE,
			'/training/statistics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_statistics' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);
	}

	/**
	 * Get training modules.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_modules( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API callback signature.
		$args = array(
			'post_type'      => 'mcp_ai_training',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		);

		$modules = get_posts( $args );
		$result  = array();

		// Get current user's role for filtering (optional role-based access).
		$user_id = get_current_user_id();

		foreach ( $modules as $module ) {
			$module_data = array(
				'id'        => $module->ID,
				'title'     => $module->post_title,
				'content'   => $module->post_content,
				'excerpt'   => $module->post_excerpt,
				'role'      => get_post_meta( $module->ID, '_training_role', true ),
				'type'      => get_post_meta( $module->ID, '_training_type', true ),
				'duration'  => get_post_meta( $module->ID, '_training_duration', true ),
				'mandatory' => get_post_meta( $module->ID, '_training_mandatory', true ) === '1',
			);

			// Note: Role-based filtering can be implemented here if needed.
			// For now, all logged-in users can see all modules (as per ISO 27001 awareness requirement).
			$result[] = $module_data;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'modules' => $result,
			),
			200
		);
	}

	/**
	 * Get user training completions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_user_completions( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API callback signature.
		$user_id     = get_current_user_id();
		$completions = WP_MCP_AI_Security_Training::get_instance()->get_user_completions( $user_id );

		return new WP_REST_Response(
			array(
				'success'     => true,
				'completions' => $completions,
			),
			200
		);
	}

	/**
	 * Record training completion.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function record_completion( $request ) {
		$user_id   = get_current_user_id();
		$module_id = $request->get_param( 'module_id' );
		$score     = $request->get_param( 'score' );

		// Verify module exists.
		$module = get_post( $module_id );
		if ( ! $module || 'mcp_ai_training' !== $module->post_type ) {
			return new WP_Error(
				'invalid_module',
				__( 'Invalid training module ID.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		// Record completion.
		$success = WP_MCP_AI_Security_Training::get_instance()->record_completion( $user_id, $module_id, $score );

		if ( $success ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Training completion recorded successfully.', 'mcp-ai-wpoos' ),
				),
				200
			);
		}

		return new WP_Error(
			'completion_failed',
			__( 'Failed to record training completion.', 'mcp-ai-wpoos' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Get training statistics.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_statistics( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API callback signature.
		$stats = WP_MCP_AI_Security_Training::get_instance()->get_training_statistics();

		return new WP_REST_Response(
			array(
				'success'    => true,
				'statistics' => $stats,
			),
			200
		);
	}

	/**
	 * Check if user has permission.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if user has permission, false otherwise.
	 */
	public function check_user_permission( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API permission callback signature.
		return is_user_logged_in();
	}

	/**
	 * Check if user has admin permission.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if user has permission, false otherwise.
	 */
	public function check_admin_permission( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API permission callback signature.
		return current_user_can( 'manage_options' );
	}
}

// Initialize REST API.
new WP_MCP_AI_Security_Training_REST();
