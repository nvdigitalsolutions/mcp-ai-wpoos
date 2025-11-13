<?php
/**
 * Admin Controller for REST API
 *
 * Handles admin-related endpoints including cron status.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Controller Class
 *
 * Manages admin-related REST API endpoints:
 * - GET /cron-status - Dashboard cron status
 */
class WP_MCP_AI_REST_Admin_Controller extends WP_MCP_AI_REST_Controller_Base {
	/**
	 * Reference to the main REST controller for shared functionality.
	 *
	 * @var WP_MCP_AI_REST
	 */
	private $main_controller;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_REST                    $main_controller Main REST controller.
	 * @param WP_MCP_AI_REST_Authenticator|null $authenticator   Authentication handler (optional, for DI).
	 * @param WP_MCP_AI_REST_Validator|null     $validator       Request validator (optional, for DI).
	 */
	public function __construct( $main_controller = null, $authenticator = null, $validator = null ) {
		parent::__construct( $authenticator, $validator );
		$this->main_controller = $main_controller;
	}

	/**
	 * Register admin routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/cron-status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'handle_cron_status_request' ),
					'args'                => array(
						'limit' => array(
							'description'       => __( 'Number of jobs to return (default 10).', 'wp-mcp-ai' ),
							'type'              => 'integer',
							'required'          => false,
							'default'           => 10,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Handle GET /cron-status - Dashboard cron status.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_cron_status_request( WP_REST_Request $request ) {
		// Load the cron status service.
		if ( ! class_exists( 'WP_MCP_AI_Cron_Status_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';
		}

		$service = $this->main_controller->get_cron_status_service();
		$user_id = get_current_user_id();
		$limit   = $request->get_param( 'limit' );
		if ( ! $limit ) {
			$limit = 10;
		}

		// Get status summary and counts.
		$jobs   = $service->get_status_summary( $user_id, $limit );
		$counts = $service->get_status_counts( $user_id );

		$response = array(
			'jobs'   => $jobs,
			'counts' => $counts,
		);

		return rest_ensure_response( $response );
	}
}
