<?php
/**
 * Job Notifier REST controller for the Content Graph AI Platform addon.
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Job_Notifier_REST`
 * (Wave E2): byte-identical namespace/routes (`mcp-ai/v1` jobs stream,
 * status, and webhook registration), parameter schemas, permission
 * callbacks, error codes (`job_not_found`, `unauthorized`,
 * `wp_mcp_ai_auth_unavailable`, `wp_mcp_ai_missing_credentials`,
 * `rest_invalid_nonce`, `rest_forbidden`, …), authorization model, and
 * the dot-preserving `sanitize_job_id()`.
 *
 * Decoupling (documented, additive):
 * - Route registration is standalone-only via
 *   `Plugin::registerJobNotifierRest()` (boot-gated on
 *   `defined( 'WP_MCP_AI_PATH' )`) — the base plugin's
 *   `job-notifier-init.php` owns the same routes in monolith installs,
 *   and double registration would collide.
 * - The request authenticator resolves per install mode: base
 *   `WP_MCP_AI_REST_Authenticator` monolith / none standalone, so the
 *   mesh-key, local/bearer token, and guest-token branches return the
 *   byte-identical `wp_mcp_ai_auth_unavailable` (503) degradation in
 *   standalone mode; WordPress nonce authentication works in both modes.
 * - The SSE stream and notifier resolve per install mode (base classes
 *   monolith / this package's `Rest\SseStream` + `Queues\JobNotifier`
 *   standalone).
 * - Text domain `nvoos-content-graph-ai-platform` (platform convention).
 *
 * @package NvoosContentGraphAiPlatform\Rest
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Rest;

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
class JobNotifierRestController {
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Authenticator instance (lazy loaded).
	 *
	 * @var object|null
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
					'methods'             => \WP_REST_Server::READABLE,
					'permission_callback' => array( __CLASS__, 'permissions_check_job_stream' ),
					'callback'            => array( __CLASS__, 'handle_job_stream' ),
					'args'                => array(
						'job_id'        => array(
							'description'       => __( 'Unique identifier for the job to stream.', 'nvoos-content-graph-ai-platform' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( __CLASS__, 'sanitize_job_id' ),
						),
						'max_duration'  => array(
							'description' => __( 'Maximum duration in seconds to keep the stream open.', 'nvoos-content-graph-ai-platform' ),
							'type'        => 'integer',
							'minimum'     => 10,
							'maximum'     => 600,
							'default'     => 300,
						),
						'poll_interval' => array(
							'description' => __( 'Interval in seconds between status checks.', 'nvoos-content-graph-ai-platform' ),
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
					'methods'             => \WP_REST_Server::READABLE,
					'permission_callback' => array( __CLASS__, 'permissions_check_job_status' ),
					'callback'            => array( __CLASS__, 'handle_job_status' ),
					'args'                => array(
						'job_id' => array(
							'description'       => __( 'Unique identifier for the job to check status.', 'nvoos-content-graph-ai-platform' ),
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
					'methods'             => \WP_REST_Server::CREATABLE,
					'permission_callback' => array( __CLASS__, 'permissions_check_webhook_register' ),
					'callback'            => array( __CLASS__, 'handle_webhook_register' ),
					'args'                => array(
						'job_id'      => array(
							'description'       => __( 'Job identifier or wildcard pattern to register webhook for.', 'nvoos-content-graph-ai-platform' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( __CLASS__, 'sanitize_job_id' ),
						),
						'webhook_url' => array(
							'description'       => __( 'URL to POST webhook notifications to.', 'nvoos-content-graph-ai-platform' ),
							'type'              => 'string',
							'required'          => true,
							'format'            => 'uri',
							'sanitize_callback' => 'esc_url_raw',
						),
						'events'      => array(
							'description' => __( 'Array of event types to subscribe to.', 'nvoos-content-graph-ai-platform' ),
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
	 * Standalone installs have no authenticator port yet — the
	 * `wp_mcp_ai_auth_unavailable` degradation branches handle that
	 * byte-identically to the base when its class is absent. The
	 * resolution goes through `authenticator_class()` (late static
	 * binding) so tests can force the absent branch deterministically.
	 *
	 * @return object|null The authenticator instance or null if unavailable.
	 */
	protected static function get_authenticator() {
		if ( null === self::$authenticator ) {
			$class = static::authenticator_class();
			if ( null !== $class ) {
				self::$authenticator = new $class();
			}
		}
		return self::$authenticator;
	}

	/**
	 * Resolve the request authenticator class.
	 *
	 * The base plugin's authenticator owns credential validation in
	 * monolith installs (and resolves through the monorepo autoloader in
	 * the standalone test matrix); a real standalone install without the
	 * monorepo resolves null and the token branches degrade.
	 *
	 * @return string|null Fully-qualified class name, or null when unavailable.
	 */
	protected static function authenticator_class(): ?string {
		if ( class_exists( 'WP_MCP_AI_REST_Authenticator' ) ) {
			return 'WP_MCP_AI_REST_Authenticator';
		}

		return null;
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
	public static function permissions_check_job_stream( \WP_REST_Request $request ) {
		$authenticator = self::get_authenticator();

		if ( $authenticator ) {
			$authenticator->reset_auth_context();
		}

		// Check for mesh API key authentication.
		$mesh_key = $request->get_header( 'X-WP-MCP-AI-Mesh-Key' );
		if ( ! empty( $mesh_key ) ) {
			if ( ! $authenticator ) {
				// Authenticator not available - cannot validate mesh key securely.
				return new \WP_Error(
					'wp_mcp_ai_auth_unavailable',
					__( 'Authentication service is unavailable. Please try again later.', 'nvoos-content-graph-ai-platform' ),
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
				} elseif ( $local instanceof \WP_Error ) {
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
				return new \WP_Error(
					'wp_mcp_ai_invalid_bearer_token',
					__( 'The supplied bearer token could not be validated.', 'nvoos-content-graph-ai-platform' ),
					array( 'status' => 401 )
				);
			}

			// Authenticator not available - cannot validate bearer tokens securely.
			// Return an error instead of allowing unvalidated access.
			return new \WP_Error(
				'wp_mcp_ai_auth_unavailable',
				__( 'Authentication service is unavailable. Please try again later.', 'nvoos-content-graph-ai-platform' ),
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
			$guest_assistant = \WP_MCP_AI_Shortcode::validate_guest_token( $guest_token, 0, $request );

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
			return new \WP_Error(
				'wp_mcp_ai_missing_credentials',
				__( 'Authentication is required to view job status.', 'nvoos-content-graph-ai-platform' ),
				array(
					'status'  => 401,
					'actions' => array(
						'supply_bearer_token' => __( 'Include a bearer token using the Authorization: Bearer YOUR_TOKEN header.', 'nvoos-content-graph-ai-platform' ),
						'supply_guest_token'  => __( 'Include a guest token using the X-WP-MCP-AI-Guest header for public chat surfaces.', 'nvoos-content-graph-ai-platform' ),
						'include_rest_nonce'  => __( 'Include the X-WP-Nonce header from wp_create_nonce( "wp_rest" ) when calling this endpoint from WordPress.', 'nvoos-content-graph-ai-platform' ),
					),
				)
			);
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'rest_invalid_nonce',
				__( 'Could not verify the request nonce.', 'nvoos-content-graph-ai-platform' ),
				array(
					'status'  => rest_authorization_required_code(),
					'actions' => array(
						'refresh_nonce' => __( 'Refresh your WordPress session to obtain a fresh nonce and retry the request.', 'nvoos-content-graph-ai-platform' ),
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
	public static function permissions_check_job_status( \WP_REST_Request $request ) {
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
	public static function permissions_check_webhook_register( \WP_REST_Request $request ) {
		$authenticator = self::get_authenticator();

		if ( $authenticator ) {
			$authenticator->reset_auth_context();
		}

		// Check for mesh API key authentication.
		$mesh_key = $request->get_header( 'X-WP-MCP-AI-Mesh-Key' );
		if ( ! empty( $mesh_key ) ) {
			if ( ! $authenticator ) {
				// Authenticator not available - cannot validate mesh key securely.
				return new \WP_Error(
					'wp_mcp_ai_auth_unavailable',
					__( 'Authentication service is unavailable. Please try again later.', 'nvoos-content-graph-ai-platform' ),
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
				return new \WP_Error(
					'wp_mcp_ai_auth_unavailable',
					__( 'Authentication service is unavailable. Please try again later.', 'nvoos-content-graph-ai-platform' ),
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
				return new \WP_Error(
					'rest_forbidden',
					__( 'You do not have permission to register webhooks.', 'nvoos-content-graph-ai-platform' ),
					array( 'status' => 403 )
				);
			} elseif ( $local instanceof \WP_Error ) {
				return $local;
			}

			// Try Auth0 bearer token validation.
			$validated = $authenticator->validate_bearer_token( $token, $request );

			if ( true === $validated ) {
				// Bearer token authenticated - check capability.
				if ( current_user_can( 'manage_options' ) ) {
					return true;
				}
				return new \WP_Error(
					'rest_forbidden',
					__( 'You do not have permission to register webhooks.', 'nvoos-content-graph-ai-platform' ),
					array( 'status' => 403 )
				);
			} elseif ( is_wp_error( $validated ) ) {
				return $validated;
			}

			// Validation returned something unexpected - deny access.
			return new \WP_Error(
				'wp_mcp_ai_invalid_bearer_token',
				__( 'The supplied bearer token could not be validated.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 401 )
			);
		}

		// Check for WordPress nonce authentication.
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( empty( $nonce ) ) {
			return new \WP_Error(
				'wp_mcp_ai_missing_credentials',
				__( 'Authentication is required to register webhooks.', 'nvoos-content-graph-ai-platform' ),
				array(
					'status'  => 401,
					'actions' => array(
						'supply_bearer_token' => __( 'Include a bearer token using the Authorization: Bearer YOUR_TOKEN header.', 'nvoos-content-graph-ai-platform' ),
						'include_rest_nonce'  => __( 'Include the X-WP-Nonce header from wp_create_nonce( "wp_rest" ) when calling this endpoint from WordPress.', 'nvoos-content-graph-ai-platform' ),
					),
				)
			);
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'rest_invalid_nonce',
				__( 'Could not verify the request nonce.', 'nvoos-content-graph-ai-platform' ),
				array(
					'status'  => rest_authorization_required_code(),
					'actions' => array(
						'refresh_nonce' => __( 'Refresh your WordPress session to obtain a fresh nonce and retry the request.', 'nvoos-content-graph-ai-platform' ),
					),
				)
			);
		}

		// Only admin users can register webhooks.
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to register webhooks.', 'nvoos-content-graph-ai-platform' ),
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
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_job_stream( \WP_REST_Request $request ) {
		$job_id        = $request->get_param( 'job_id' );
		$max_duration  = $request->get_param( 'max_duration' );
		$poll_interval = $request->get_param( 'poll_interval' );

		// Get initial status to check authorization.
		$initial_status = self::notifier_class()::get_job_status( $job_id );

		if ( ! $initial_status ) {
			return new \WP_Error(
				'job_not_found',
				__( 'Job not found.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 404 )
			);
		}

		// Comprehensive authorization check across all entity types.
		$current_user_id = get_current_user_id();
		$job_metadata    = isset( $initial_status['metadata'] ) ? $initial_status['metadata'] : array();

		if ( ! self::is_user_authorized_for_job( $job_metadata, $current_user_id ) ) {
			return new \WP_Error(
				'unauthorized',
				__( 'You do not have permission to access this job. Authorization can be granted through user, assistant, team, profession, or agent ownership.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 403 )
			);
		}

		return self::sse_stream_class()::stream_job_status( $job_id, $max_duration, $poll_interval );
	}

	/**
	 * Check if current user is authorized to access a job.
	 *
	 * Authorization is granted if ANY of the following is true:
	 * - User is an admin (manage_options capability)
	 * - User ID matches job's user_id
	 * - User owns the assistant that created the job
	 * - User is member of the team that created the job
	 * - User owns the profession that created the job
	 * - User owns the agent that executed the job
	 * - User owns the virtual agent that executed the job
	 *
	 * @param array $job_metadata Job metadata containing various IDs.
	 * @param int   $current_user_id Current user ID making the request.
	 * @return bool True if authorized, false otherwise.
	 */
	private static function is_user_authorized_for_job( $job_metadata, $current_user_id ) {
		// Admin can access all jobs.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Check direct user ownership.
		if ( isset( $job_metadata['user_id'] ) && absint( $job_metadata['user_id'] ) === $current_user_id ) {
			return true;
		}

		// Check assistant ownership.
		if ( isset( $job_metadata['assistant_id'] ) ) {
			$assistant_id = absint( $job_metadata['assistant_id'] );
			if ( $assistant_id > 0 ) {
				$assistant = get_post( $assistant_id );
				if ( $assistant && absint( $assistant->post_author ) === $current_user_id ) {
					return true;
				}
			}
		}

		// Check team membership (teams are stored as posts with members in meta).
		if ( isset( $job_metadata['team_id'] ) ) {
			$team_id = absint( $job_metadata['team_id'] );
			if ( $team_id > 0 ) {
				$team = get_post( $team_id );
				// Check if user is team owner.
				if ( $team && absint( $team->post_author ) === $current_user_id ) {
					return true;
				}
				// Check if user is team member.
				$team_members = get_post_meta( $team_id, 'team_members', true );
				if ( is_array( $team_members ) && in_array( $current_user_id, array_map( 'absint', $team_members ), true ) ) {
					return true;
				}
			}
		}

		// Check profession/professional ownership.
		$profession_id = 0;
		if ( isset( $job_metadata['professional_id'] ) ) {
			$profession_id = absint( $job_metadata['professional_id'] );
		} elseif ( isset( $job_metadata['profession_id'] ) ) {
			$profession_id = absint( $job_metadata['profession_id'] );
		}

		if ( $profession_id > 0 ) {
			$profession = get_post( $profession_id );
			if ( $profession && absint( $profession->post_author ) === $current_user_id ) {
				return true;
			}
		}

		// Check agent ownership (agents may be associated with assistants/professions).
		if ( isset( $job_metadata['agent_id'] ) ) {
			// Agent IDs might be string identifiers like "agent-123".
			// Extract numeric ID if possible and check ownership.
			$agent_id_str = sanitize_text_field( $job_metadata['agent_id'] );
			if ( preg_match( '/(\d+)/', $agent_id_str, $matches ) ) {
				$agent_numeric_id = absint( $matches[1] );
				if ( $agent_numeric_id > 0 ) {
					$agent = get_post( $agent_numeric_id );
					if ( $agent && absint( $agent->post_author ) === $current_user_id ) {
						return true;
					}
				}
			}
		}

		// Check virtual agent ownership.
		// Virtual agents are dynamically created within team contexts,
		// so check if user has access to the team.
		if ( isset( $job_metadata['virtual_agent_id'] ) && isset( $job_metadata['team_id'] ) ) {
			$team_id = absint( $job_metadata['team_id'] );
			if ( $team_id > 0 ) {
				$team = get_post( $team_id );
				// Check if user is team owner.
				if ( $team && absint( $team->post_author ) === $current_user_id ) {
					return true;
				}
				// Check if user is team member.
				$team_members = get_post_meta( $team_id, 'team_members', true );
				if ( is_array( $team_members ) && in_array( $current_user_id, array_map( 'absint', $team_members ), true ) ) {
					return true;
				}
			}
		}

		// No authorization criteria met.
		return false;
	}

	/**
	 * Handle job status request (non-streaming).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_job_status( \WP_REST_Request $request ) {
		$job_id = $request->get_param( 'job_id' );
		$status = self::notifier_class()::get_job_status( $job_id );

		if ( ! $status ) {
			return new \WP_Error(
				'job_not_found',
				__( 'Job status not found or expired.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 404 )
			);
		}

		// Comprehensive authorization check across all entity types.
		$current_user_id = get_current_user_id();
		$job_metadata    = isset( $status['metadata'] ) ? $status['metadata'] : array();

		if ( ! self::is_user_authorized_for_job( $job_metadata, $current_user_id ) ) {
			return new \WP_Error(
				'unauthorized',
				__( 'You do not have permission to access this job. Authorization can be granted through user, assistant, team, profession, or agent ownership.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 403 )
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
	public static function handle_webhook_register( \WP_REST_Request $request ) {
		$job_id      = $request->get_param( 'job_id' );
		$webhook_url = $request->get_param( 'webhook_url' );
		$events      = $request->get_param( 'events' );

		$result = self::notifier_class()::register_webhook( $job_id, $webhook_url, $events );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success'     => true,
				'job_id'      => $job_id,
				'webhook_url' => $webhook_url,
				'events'      => $events,
				'message'     => __( 'Webhook registered successfully.', 'nvoos-content-graph-ai-platform' ),
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
	 * This method matches the sanitization in the base plugin's
	 * WP_MCP_AI_REST_Tools_Controller::sanitize_job_id().
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

	// ─── Seams ──────────────────────────────────────────────────────

	/**
	 * Resolve the job notifier class.
	 *
	 * The base WP_MCP_AI_Job_Notifier owns the status cache in monolith
	 * installs; standalone resolves this package's Queues\JobNotifier.
	 *
	 * @return string Fully-qualified class name.
	 */
	protected static function notifier_class(): string {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
			return 'WP_MCP_AI_Job_Notifier';
		}

		return \NvoosContentGraphAiPlatform\Queues\JobNotifier::class;
	}

	/**
	 * Resolve the SSE stream class.
	 *
	 * The base WP_MCP_AI_SSE_Stream owns the buffered stream builder in
	 * monolith installs; standalone resolves this package's Rest\SseStream.
	 *
	 * @return string Fully-qualified class name.
	 */
	protected static function sse_stream_class(): string {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_SSE_Stream' ) ) {
			return 'WP_MCP_AI_SSE_Stream';
		}

		return SseStream::class;
	}
}
