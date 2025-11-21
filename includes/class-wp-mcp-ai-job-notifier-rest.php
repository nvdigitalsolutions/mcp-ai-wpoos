<?php
/**
 * REST API endpoints for job notification system.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers REST endpoints for SSE streaming and webhook management.
 */
class WP_MCP_AI_Job_Notifier_REST {
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Initialize REST routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public static function register_routes() {
		// SSE endpoint for streaming job status.
		// Updated regex to support dots in job IDs (e.g., veo_69203b5b2388f5.11575461 from uniqid).
		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs/(?P<job_id>[a-zA-Z0-9_.\-]+)/stream',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( __CLASS__, 'permissions_check_job_stream' ),
					'callback'            => array( __CLASS__, 'handle_job_stream' ),
					'args'                => array(
						'job_id'        => array(
							'description'       => __( 'Unique identifier for the job to stream.', 'wp-mcp-ai' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( __CLASS__, 'sanitize_job_id' ),
						),
						'max_duration'  => array(
							'description' => __( 'Maximum duration in seconds to keep the stream open.', 'wp-mcp-ai' ),
							'type'        => 'integer',
							'minimum'     => 10,
							'maximum'     => 600,
							'default'     => 300,
						),
						'poll_interval' => array(
							'description' => __( 'Interval in seconds between status checks.', 'wp-mcp-ai' ),
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 30,
							'default'     => 2,
						),
					),
				),
			),
			true
		);

		// Get job status (non-streaming).
		// Updated regex to support dots in job IDs (e.g., veo_69203b5b2388f5.11575461 from uniqid).
		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs/(?P<job_id>[a-zA-Z0-9_.\-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( __CLASS__, 'permissions_check_job_status' ),
					'callback'            => array( __CLASS__, 'handle_job_status' ),
					'args'                => array(
						'job_id' => array(
							'description'       => __( 'Unique identifier for the job to check status.', 'wp-mcp-ai' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( __CLASS__, 'sanitize_job_id' ),
						),
					),
				),
			),
			true
		);

		// Register webhook.
		// Updated regex to support dots in job IDs (e.g., veo_69203b5b2388f5.11575461 from uniqid).
		// Also supports wildcards (*) for pattern matching.
		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs/(?P<job_id>[a-zA-Z0-9_.*\-]+)/webhooks',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( __CLASS__, 'permissions_check_webhook_register' ),
					'callback'            => array( __CLASS__, 'handle_webhook_register' ),
					'args'                => array(
						'job_id'      => array(
							'description'       => __( 'Job identifier or wildcard pattern to register webhook for.', 'wp-mcp-ai' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( __CLASS__, 'sanitize_job_id' ),
						),
						'webhook_url' => array(
							'description'       => __( 'URL to POST webhook notifications to.', 'wp-mcp-ai' ),
							'type'              => 'string',
							'required'          => true,
							'format'            => 'uri',
							'sanitize_callback' => 'esc_url_raw',
						),
						'events'      => array(
							'description' => __( 'Array of event types to subscribe to.', 'wp-mcp-ai' ),
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'started', 'progress', 'completed', 'failed' ),
							),
							'default'     => array( 'completed', 'failed' ),
						),
					),
				),
			),
			true
		);
	}

	/**
	 * Permission check for job stream endpoint.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public static function permissions_check_job_stream( WP_REST_Request $request ) {
		// Check for bearer token authentication first.
		$bearer = $request->get_header( 'Authorization' );
		if ( ! empty( $bearer ) && preg_match( '/^Bearer\s+(.*)$/i', $bearer, $matches ) ) {
			// Token validation would go here.
			// For now, allow any bearer token.
			return true;
		}

		// Allow logged-in users with valid nonce.
		if ( is_user_logged_in() ) {
			$nonce = $request->get_header( 'X-WP-Nonce' );

			if ( empty( $nonce ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_nonce',
					__( 'Authentication nonce is required. Include the X-WP-Nonce header from wp_create_nonce( "wp_rest" ).', 'wp-mcp-ai' ),
					array( 'status' => 401 )
				);
			}

			if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new WP_Error(
					'rest_invalid_nonce',
					__( 'Could not verify the request nonce.', 'wp-mcp-ai' ),
					array( 'status' => 403 )
				);
			}

			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to stream job status.', 'wp-mcp-ai' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Permission check for job status endpoint.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public static function permissions_check_job_status( WP_REST_Request $request ) {
		return self::permissions_check_job_stream( $request );
	}

	/**
	 * Permission check for webhook registration.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public static function permissions_check_webhook_register( WP_REST_Request $request ) {
		// Verify nonce for logged-in users.
		if ( is_user_logged_in() ) {
			$nonce = $request->get_header( 'X-WP-Nonce' );

			if ( empty( $nonce ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_nonce',
					__( 'Authentication nonce is required. Include the X-WP-Nonce header from wp_create_nonce( "wp_rest" ).', 'wp-mcp-ai' ),
					array( 'status' => 401 )
				);
			}

			if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new WP_Error(
					'rest_invalid_nonce',
					__( 'Could not verify the request nonce.', 'wp-mcp-ai' ),
					array( 'status' => 403 )
				);
			}
		}

		// Only admin users can register webhooks.
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to register webhooks.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle SSE job stream request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function handle_job_stream( WP_REST_Request $request ) {
		$job_id        = $request->get_param( 'job_id' );
		$max_duration  = $request->get_param( 'max_duration' );
		$poll_interval = $request->get_param( 'poll_interval' );

		return WP_MCP_AI_SSE_Stream::stream_job_status( $job_id, $max_duration, $poll_interval );
	}

	/**
	 * Handle job status request (non-streaming).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_job_status( WP_REST_Request $request ) {
		$job_id = $request->get_param( 'job_id' );
		$status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		if ( ! $status ) {
			return new WP_Error(
				'job_not_found',
				__( 'Job status not found or expired.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $status );
	}

	/**
	 * Handle webhook registration request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_webhook_register( WP_REST_Request $request ) {
		$job_id      = $request->get_param( 'job_id' );
		$webhook_url = $request->get_param( 'webhook_url' );
		$events      = $request->get_param( 'events' );

		$result = WP_MCP_AI_Job_Notifier::register_webhook( $job_id, $webhook_url, $events );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success'     => true,
				'job_id'      => $job_id,
				'webhook_url' => $webhook_url,
				'events'      => $events,
				'message'     => __( 'Webhook registered successfully.', 'wp-mcp-ai' ),
			)
		);
	}

	/**
	 * Sanitize job ID parameter.
	 *
	 * Job IDs are generated using uniqid() with more_entropy=true, which produces
	 * IDs like 'veo_69203b5b2388f5.11575461'. This sanitization function preserves
	 * the dot character while blocking path traversal and other malicious input.
	 *
	 * This method matches the sanitization in WP_MCP_AI_REST_Tools_Controller::sanitize_job_id().
	 *
	 * @param string $job_id Job ID to sanitize.
	 * @return string Sanitized job ID.
	 */
	public static function sanitize_job_id( $job_id ) {
		// Remove any characters that aren't alphanumeric, underscore, dot, hyphen, or asterisk (for wildcards).
		$sanitized = preg_replace( '/[^a-zA-Z0-9_.*\-]/', '', $job_id );

		// Remove path traversal attempts (consecutive dots).
		$sanitized = preg_replace( '/\.\.+/', '', $sanitized );

		return $sanitized;
	}
}
