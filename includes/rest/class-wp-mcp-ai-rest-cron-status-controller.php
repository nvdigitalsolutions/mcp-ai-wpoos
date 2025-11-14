<?php
/**
 * Cron Status Controller for REST API
 *
 * Handles cron status endpoint for monitoring scheduled jobs.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cron Status Controller Class
 *
 * Manages cron status REST API endpoint with support for:
 * - Lightweight status queries for pending/completed jobs
 * - User-specific job filtering
 * - Admin access to all jobs
 */
class WP_MCP_AI_REST_Cron_Status_Controller extends WP_MCP_AI_REST_Controller_Base {
	/**
	 * Cron status service.
	 *
	 * @var WP_MCP_AI_Cron_Status_Service
	 */
	private $cron_status_service;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_REST_Authenticator|null $authenticator Authentication handler (optional, for DI).
	 * @param WP_MCP_AI_REST_Validator|null     $validator     Request validator (optional, for DI).
	 */
	public function __construct( $authenticator = null, $validator = null ) {
		parent::__construct( $authenticator, $validator );
	}

	/**
	 * Register cron status routes.
	 */
	public function register_routes() {
		// /cron-status - Get cron job status.
		register_rest_route(
			self::REST_NAMESPACE,
			'/cron-status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check_cron_status' ),
					'callback'            => array( $this, 'handle_cron_status' ),
					'args'                => array(
						'limit' => array(
							'description'       => __( 'Maximum number of jobs to return.', 'wp-mcp-ai' ),
							'type'              => 'integer',
							'required'          => false,
							'default'           => 10,
							'sanitize_callback' => 'absint',
							'minimum'           => 1,
							'maximum'           => 50,
						),
					),
				),
			),
			true
		);
	}

	/**
	 * Permission callback for cron status endpoint.
	 *
	 * Any authenticated user can view their own cron jobs.
	 * Admins can see all cron jobs.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function permissions_check_cron_status( WP_REST_Request $request ) {
		// Use the standard authenticated check from base controller.
		$auth_check = $this->permissions_check_authenticated( $request );

		if ( is_wp_error( $auth_check ) ) {
			return $auth_check;
		}

		// Any authenticated user can view their own cron jobs.
		// The service itself handles filtering based on user permissions.
		return true;
	}

	/**
	 * Handle cron status request.
	 *
	 * Returns lightweight status information about cron jobs.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function handle_cron_status( WP_REST_Request $request ) {
		try {
			// Get the cron status service.
			$service = $this->get_cron_status_service();

			// Get authenticated user ID from auth context.
			$user_id = $this->get_current_user_id();

			// If no user ID from auth context, fall back to current user.
			if ( ! $user_id ) {
				$user_id = get_current_user_id();
			}

			// Get limit parameter.
			$limit = $this->get_param( $request, 'limit', 10 );

			// Get status summary and counts.
			$jobs   = $service->get_status_summary( $user_id, $limit );
			$counts = $service->get_status_counts( $user_id );

			$response_data = array(
				'jobs'   => $jobs,
				'counts' => $counts,
			);

			return $this->success( $response_data );

		} catch ( Exception $e ) {
			$this->log( 'Cron status error: ' . $e->getMessage(), 'cron-status' );

			return $this->error(
				'cron_status_error',
				__( 'Failed to retrieve cron status.', 'wp-mcp-ai' ),
				500
			);
		}
	}

	/**
	 * Get cron status service instance.
	 *
	 * @return WP_MCP_AI_Cron_Status_Service Cron status service.
	 */
	private function get_cron_status_service() {
		if ( null === $this->cron_status_service ) {
			// Load the service class if not already loaded.
			if ( ! class_exists( 'WP_MCP_AI_Cron_Status_Service' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';
			}

			// Get service from container.
			$container                   = wp_mcp_ai_container();
			$this->cron_status_service = $container->get( 'service.cron_status' );
		}

		return $this->cron_status_service;
	}
}
