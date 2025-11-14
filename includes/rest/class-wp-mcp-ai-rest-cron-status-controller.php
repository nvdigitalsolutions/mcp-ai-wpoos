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
		// Reset auth context.
		$this->auth_context = array(
			'user_id'             => absint( get_current_user_id() ),
			'token_authenticated' => false,
		);

		// Check for mesh API key authentication.
		$mesh_key = $request->get_header( 'X-WP-MCP-AI-Mesh-Key' );
		if ( ! empty( $mesh_key ) && $this->authenticator ) {
			$mesh_validated = $this->authenticator->validate_mesh_key( $mesh_key );

			if ( true === $mesh_validated ) {
				$this->authenticator->mark_token_authenticated( 'mesh', array( 'mesh_authenticated' => true ) );
				$this->auth_context = $this->authenticator->get_auth_context();
				return true;
			} elseif ( is_wp_error( $mesh_validated ) ) {
				return $mesh_validated;
			}
		}

		// Check for bearer token authentication.
		$bearer = $request->get_header( 'Authorization' );
		if ( ! empty( $bearer ) && preg_match( '/^Bearer\s+(.*)$/i', $bearer, $matches ) ) {
			$token = trim( $matches[1] );
			
			if ( $this->authenticator ) {
				$local = $this->authenticator->validate_local_token( $token, $request );

				if ( true === $local ) {
					$this->auth_context = $this->authenticator->get_auth_context();
					return true;
				} elseif ( $local instanceof WP_Error ) {
					return $local;
				}

				$validated = $this->authenticator->validate_bearer_token( $token, $request );

				if ( is_wp_error( $validated ) ) {
					return $validated;
				}

				$this->auth_context = $this->authenticator->get_auth_context();
				return true;
			}
		}

		// Check for guest token authentication.
		if ( $this->authenticator ) {
			$guest_token = $this->authenticator->extract_guest_token( $request );
			if ( $guest_token && class_exists( 'WP_MCP_AI_Shortcode' ) ) {
				$guest_assistant = WP_MCP_AI_Shortcode::validate_guest_token( $guest_token, 0 );

				if ( $guest_assistant ) {
					// Guest users can view their own cron jobs (user_id = 0).
					$this->authenticator->set_authenticated_user_id( 0 );
					$this->auth_context = $this->authenticator->get_auth_context();
					return true;
				}
			}
		}

		// Check for WordPress nonce authentication.
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( empty( $nonce ) ) {
			return $this->error(
				'wp_mcp_ai_missing_credentials',
				__( 'Authentication is required to view cron status.', 'wp-mcp-ai' ),
				401,
				array(
					'supply_bearer_token' => __( 'Include a bearer token using the Authorization: Bearer YOUR_TOKEN header.', 'wp-mcp-ai' ),
					'supply_guest_token'  => __( 'Include a guest token using the X-WP-MCP-AI-Guest header for public chat surfaces.', 'wp-mcp-ai' ),
					'include_rest_nonce'  => __( 'Include the X-WP-Nonce header from wp_create_nonce( "wp_rest" ) when calling this endpoint from WordPress.', 'wp-mcp-ai' ),
				)
			);
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return $this->error(
				'rest_invalid_nonce',
				__( 'Could not verify the request nonce.', 'wp-mcp-ai' ),
				rest_authorization_required_code(),
				array(
					'refresh_nonce' => __( 'Refresh your WordPress session to obtain a fresh nonce and retry the request.', 'wp-mcp-ai' ),
				)
			);
		}

		// WordPress nonce is valid. Store current user in auth context.
		$this->auth_context['user_id'] = get_current_user_id();

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
