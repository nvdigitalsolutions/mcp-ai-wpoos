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
 *
 * Supports multiple authentication methods:
 * - Mesh key authentication (X-WP-MCP-AI-Mesh-Key header)
 * - Local token authentication (plugin-issued credential tokens)
 * - Guest token authentication (X-WP-MCP-AI-Guest header)
 * - Bearer token authentication (Auth0 JWT tokens)
 * - WordPress nonce authentication (X-WP-Nonce header)
 */
class WP_MCP_AI_Job_Notifier_REST {
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Authenticator instance (lazy loaded).
	 *
	 * @var WP_MCP_AI_REST_Authenticator|null
	 */
	protected static $authenticator = null;

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
	 * Get or create the authenticator instance.
	 *
	 * @return WP_MCP_AI_REST_Authenticator|null The authenticator instance or null if unavailable.
	 */
	protected static function get_authenticator() {
		if ( null === self::$authenticator ) {
			if ( class_exists( 'WP_MCP_AI_REST_Authenticator' ) ) {
				self::$authenticator = new WP_MCP_AI_REST_Authenticator();
			}
		}
		return self::$authenticator;
	}

	/**
	 * Permission check for job stream and status endpoints.
	 *
	 * Supports multiple authentication methods:
	 * - Mesh key authentication (X-WP-MCP-AI-Mesh-Key header)
	 * - Local token authentication (plugin-issued credential tokens)
	 * - Guest token authentication (X-WP-MCP-AI-Guest header)
	 * - Bearer token authentication (Auth0 JWT tokens)
	 * - WordPress nonce authentication (X-WP-Nonce header)
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public static function permissions_check_job_stream( WP_REST_Request $request ) {
		$authenticator = self::get_authenticator();

		if ( $authenticator ) {
			$authenticator->reset_auth_context();
		}

		// Check for mesh API key authentication.
		$mesh_key = $request->get_header( 'X-WP-MCP-AI-Mesh-Key' );
		if ( ! empty( $mesh_key ) ) {
			if ( ! $authenticator ) {
				// Authenticator not available - cannot validate mesh key securely.
				return new WP_Error(
					'wp_mcp_ai_auth_unavailable',
					__( 'Authentication service is unavailable. Please try again later.', 'wp-mcp-ai' ),
					array( 'status' => 503 )
				);
			}

			$mesh_validated = $authenticator->validate_mesh_key( $mesh_key );

			if ( true === $mesh_validated ) {
				$authenticator->mark_token_authenticated( 'mesh', array( 'mesh_authenticated' => true ) );
				return true;
			} elseif ( is_wp_error( $mesh_validated ) ) {
				return $mesh_validated;
			}
		}

		// Check for bearer token authentication.
		$bearer = $request->get_header( 'Authorization' );
		if ( ! empty( $bearer ) && preg_match( '/^Bearer\s+(.*)$/i', $bearer, $matches ) ) {
			$token = trim( $matches[1] );

			// Try local token validation first (requires authenticator).
			if ( $authenticator ) {
				$local = $authenticator->validate_local_token( $token, $request, 0 );

				if ( true === $local ) {
					return true;
				} elseif ( $local instanceof WP_Error ) {
					return $local;
				}

				// Try Auth0 bearer token validation.
				$validated = $authenticator->validate_bearer_token( $token, $request );

				if ( true === $validated ) {
					return true;
				} elseif ( is_wp_error( $validated ) ) {
					return $validated;
				}

				// Validation returned something unexpected - deny access.
				return new WP_Error(
					'wp_mcp_ai_invalid_bearer_token',
					__( 'The supplied bearer token could not be validated.', 'wp-mcp-ai' ),
					array( 'status' => 401 )
				);
			}

			// Authenticator not available - cannot validate bearer tokens securely.
			// Return an error instead of allowing unvalidated access.
			return new WP_Error(
				'wp_mcp_ai_auth_unavailable',
				__( 'Authentication service is unavailable. Please try again later.', 'wp-mcp-ai' ),
				array( 'status' => 503 )
			);
		}

		// Check for guest token authentication.
		$guest_token = '';
		if ( $authenticator ) {
			$guest_token = $authenticator->extract_guest_token( $request );
		} else {
			// Fallback extraction if authenticator not available.
			$guest_token = $request->get_header( 'X-WP-MCP-AI-Guest' );
			if ( ! $guest_token ) {
				$guest_token = $request->get_param( 'guest_token' );
			}
			$guest_token = is_string( $guest_token ) ? trim( $guest_token ) : '';
		}

		if ( $guest_token && class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			$guest_assistant = WP_MCP_AI_Shortcode::validate_guest_token( $guest_token, 0 );

			if ( $guest_assistant ) {
				// Guest users can view their own cron jobs (user_id = 0).
				if ( $authenticator ) {
					$authenticator->set_authenticated_user_id( 0 );
				}
				return true;
			}
		}

		// Check for WordPress nonce authentication.
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( empty( $nonce ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_credentials',
				__( 'Authentication is required to view job status.', 'wp-mcp-ai' ),
				array(
					'status'  => 401,
					'actions' => array(
						'supply_bearer_token' => __( 'Include a bearer token using the Authorization: Bearer YOUR_TOKEN header.', 'wp-mcp-ai' ),
						'supply_guest_token'  => __( 'Include a guest token using the X-WP-MCP-AI-Guest header for public chat surfaces.', 'wp-mcp-ai' ),
						'include_rest_nonce'  => __( 'Include the X-WP-Nonce header from wp_create_nonce( "wp_rest" ) when calling this endpoint from WordPress.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_invalid_nonce',
				__( 'Could not verify the request nonce.', 'wp-mcp-ai' ),
				array(
					'status'  => rest_authorization_required_code(),
					'actions' => array(
						'refresh_nonce' => __( 'Refresh your WordPress session to obtain a fresh nonce and retry the request.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		// Any authenticated user can view their own cron jobs.
		// The service layer will filter jobs by user ID.
		// A valid nonce proves the user is authenticated.
		if ( $authenticator ) {
			$authenticator->set_authenticated_user_id( get_current_user_id() );
		}

		return true;
	}

	/**
	 * Permission check for job status endpoint.
	 *
	 * Uses the same authentication methods as job stream.
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
	 * Requires admin capability (manage_options).
	 * Supports mesh key, bearer tokens, and WordPress nonce authentication.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public static function permissions_check_webhook_register( WP_REST_Request $request ) {
		$authenticator = self::get_authenticator();

		if ( $authenticator ) {
			$authenticator->reset_auth_context();
		}

		// Check for mesh API key authentication.
		$mesh_key = $request->get_header( 'X-WP-MCP-AI-Mesh-Key' );
		if ( ! empty( $mesh_key ) ) {
			if ( ! $authenticator ) {
				// Authenticator not available - cannot validate mesh key securely.
				return new WP_Error(
					'wp_mcp_ai_auth_unavailable',
					__( 'Authentication service is unavailable. Please try again later.', 'wp-mcp-ai' ),
					array( 'status' => 503 )
				);
			}

			$mesh_validated = $authenticator->validate_mesh_key( $mesh_key );

			if ( true === $mesh_validated ) {
				$authenticator->mark_token_authenticated( 'mesh', array( 'mesh_authenticated' => true ) );
				// Mesh authenticated requests are trusted for webhook registration.
				return true;
			} elseif ( is_wp_error( $mesh_validated ) ) {
				return $mesh_validated;
			}
		}

		// Check for bearer token authentication.
		$bearer = $request->get_header( 'Authorization' );
		if ( ! empty( $bearer ) && preg_match( '/^Bearer\s+(.*)$/i', $bearer, $matches ) ) {
			$token = trim( $matches[1] );

			if ( ! $authenticator ) {
				// Authenticator not available - cannot validate bearer token securely.
				return new WP_Error(
					'wp_mcp_ai_auth_unavailable',
					__( 'Authentication service is unavailable. Please try again later.', 'wp-mcp-ai' ),
					array( 'status' => 503 )
				);
			}

			// Try local token validation first.
			$local = $authenticator->validate_local_token( $token, $request, 0 );

			if ( true === $local ) {
				// Local token authenticated - check capability.
				if ( current_user_can( 'manage_options' ) ) {
					return true;
				}
				return new WP_Error(
					'rest_forbidden',
					__( 'You do not have permission to register webhooks.', 'wp-mcp-ai' ),
					array( 'status' => 403 )
				);
			} elseif ( $local instanceof WP_Error ) {
				return $local;
			}

			// Try Auth0 bearer token validation.
			$validated = $authenticator->validate_bearer_token( $token, $request );

			if ( true === $validated ) {
				// Bearer token authenticated - check capability.
				if ( current_user_can( 'manage_options' ) ) {
					return true;
				}
				return new WP_Error(
					'rest_forbidden',
					__( 'You do not have permission to register webhooks.', 'wp-mcp-ai' ),
					array( 'status' => 403 )
				);
			} elseif ( is_wp_error( $validated ) ) {
				return $validated;
			}

			// Validation returned something unexpected - deny access.
			return new WP_Error(
				'wp_mcp_ai_invalid_bearer_token',
				__( 'The supplied bearer token could not be validated.', 'wp-mcp-ai' ),
				array( 'status' => 401 )
			);
		}

		// Check for WordPress nonce authentication.
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( empty( $nonce ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_credentials',
				__( 'Authentication is required to register webhooks.', 'wp-mcp-ai' ),
				array(
					'status'  => 401,
					'actions' => array(
						'supply_bearer_token' => __( 'Include a bearer token using the Authorization: Bearer YOUR_TOKEN header.', 'wp-mcp-ai' ),
						'include_rest_nonce'  => __( 'Include the X-WP-Nonce header from wp_create_nonce( "wp_rest" ) when calling this endpoint from WordPress.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_invalid_nonce',
				__( 'Could not verify the request nonce.', 'wp-mcp-ai' ),
				array(
					'status'  => rest_authorization_required_code(),
					'actions' => array(
						'refresh_nonce' => __( 'Refresh your WordPress session to obtain a fresh nonce and retry the request.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		// Only admin users can register webhooks.
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to register webhooks.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		if ( $authenticator ) {
			$authenticator->set_authenticated_user_id( get_current_user_id() );
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
	 * IDs like 'veo_69203b5b2388f5_11575461'. This sanitization function preserves
	 * dots and underscores while blocking path traversal and other malicious input.
	 *
	 * Note: Dots in job IDs have been replaced with underscores to avoid filename
	 * confusion (e.g., veo-video-id_suffix.mp4 instead of veo-video-id.suffix.mp4).
	 *
	 * This method matches the sanitization in WP_MCP_AI_REST_Tools_Controller::sanitize_job_id().
	 *
	 * @param string $job_id Job ID to sanitize.
	 * @return string Sanitized job ID.
	 */
	public static function sanitize_job_id( $job_id ) {
		// Remove any characters that aren't alphanumeric, underscore, dot, hyphen, or asterisk (for wildcards).
		$sanitized = preg_replace( '/[^a-zA-Z0-9_.*\-]/', '', $job_id );

		// Remove path traversal attempts (2 or more consecutive dots).
		// This runs after removing slashes because '../../../' becomes '......' after slash removal.
		$sanitized = preg_replace( '/\.{2,}/', '', $sanitized );

		return $sanitized;
	}
}
