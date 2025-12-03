<?php
/**
 * REST API endpoints for Token Manager.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for token management endpoints.
 */
class WP_MCP_AI_REST_Token_Manager {

	/**
	 * API namespace.
	 */
	const NAMESPACE = 'mcp-ai/v1';

	/**
	 * Register REST API routes.
	 *
	 * Registers token management and budget control REST API endpoints:
	 * - GET /users/{id}/token-tier: Get user's token tier and associated limits
	 * - POST /users/{id}/token-tier: Update user's token tier (admin-only)
	 * - GET /users/{id}/token-usage: Get current token usage and remaining budget
	 * - GET /users/{id}/token-forecast: Get usage forecast and budget predictions
	 *
	 * Token tier system provides:
	 * - Free tier: Basic token limits for general tools
	 * - Pro tier: Enhanced limits for power users
	 * - Enterprise tier: Unlimited or very high limits
	 * - Per-tool budget constraints (e.g., Crawl4AI job limits)
	 * - Expiry dates for temporary tier upgrades
	 *
	 * Usage tracking includes:
	 * - Current period token consumption
	 * - Remaining tokens in budget
	 * - Per-tool usage breakdowns
	 * - Historical usage patterns
	 * - Reset dates and renewal information
	 *
	 * Permission model:
	 * - Users can view their own tier and usage
	 * - Only administrators can update tiers
	 * - Usage forecasts available to tier subscribers
	 *
	 * @since 1.0.0
	 */
	public static function register_routes() {
		// Get user's tier and limits.
		register_rest_route(
			self::NAMESPACE,
			'/users/(?P<id>\d+)/token-tier',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_user_tier' ),
				'permission_callback' => array( __CLASS__, 'check_user_access' ),
				'args'                => array(
					'id' => array(
						'required'    => true,
						'type'        => 'integer',
						'description' => __( 'User ID.', 'wp-mcp-ai' ),
					),
				),
			)
		);

		// Update user's tier.
		register_rest_route(
			self::NAMESPACE,
			'/users/(?P<id>\d+)/token-tier',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'update_user_tier' ),
				'permission_callback' => array( __CLASS__, 'check_admin_access' ),
				'args'                => array(
					'id'     => array(
						'required'    => true,
						'type'        => 'integer',
						'description' => __( 'User ID.', 'wp-mcp-ai' ),
					),
					'tier'   => array(
						'required'    => true,
						'type'        => 'string',
						'enum'        => array( 'free', 'pro', 'enterprise' ),
						'description' => __( 'Token tier to assign.', 'wp-mcp-ai' ),
					),
					'expiry' => array(
						'type'        => 'string',
						'format'      => 'date',
						'description' => __( 'Optional expiry date (YYYY-MM-DD).', 'wp-mcp-ai' ),
					),
				),
			)
		);

		// Get user's token usage.
		register_rest_route(
			self::NAMESPACE,
			'/users/(?P<id>\d+)/token-usage',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_user_usage' ),
				'permission_callback' => array( __CLASS__, 'check_user_access' ),
				'args'                => array(
					'id'        => array(
						'required'    => true,
						'type'        => 'integer',
						'description' => __( 'User ID.', 'wp-mcp-ai' ),
					),
					'tool'      => array(
						'type'        => 'string',
						'description' => __( 'Optional tool slug to filter by.', 'wp-mcp-ai' ),
					),
					'timeframe' => array(
						'type'        => 'string',
						'enum'        => array( 'hourly', 'daily', 'weekly', 'monthly' ),
						'default'     => 'daily',
						'description' => __( 'Timeframe for usage data.', 'wp-mcp-ai' ),
					),
				),
			)
		);

		// Get usage forecast.
		register_rest_route(
			self::NAMESPACE,
			'/users/(?P<id>\d+)/token-forecast',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_usage_forecast' ),
				'permission_callback' => array( __CLASS__, 'check_user_access' ),
				'args'                => array(
					'id'   => array(
						'required'    => true,
						'type'        => 'integer',
						'description' => __( 'User ID.', 'wp-mcp-ai' ),
					),
					'tool' => array(
						'required'    => true,
						'type'        => 'string',
						'description' => __( 'Tool slug to forecast.', 'wp-mcp-ai' ),
					),
				),
			)
		);
	}

	/**
	 * Get user's tier and limits.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public static function get_user_tier( $request ) {
		$user_id = absint( $request['id'] );

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_user',
				__( 'Invalid user ID.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		$tier         = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );
		$tier_expires = get_user_meta( $user_id, '_wp_mcp_ai_token_tier_expires', true );

		// Get sample limits for common tools.
		$tool_limits = array(
			'general_tools'    => WP_MCP_AI_Tool_Token_Limits::get_user_tool_limit( $user_id, 'general_tools' ),
			'run_crawl4ai_job' => WP_MCP_AI_Tool_Token_Limits::get_user_tool_limit( $user_id, 'run_crawl4ai_job' ),
			'search_content'   => WP_MCP_AI_Tool_Token_Limits::get_user_tool_limit( $user_id, 'search_content' ),
		);

		return rest_ensure_response(
			array(
				'user_id'      => $user_id,
				'tier'         => $tier,
				'tier_expires' => $tier_expires ? gmdate( 'Y-m-d H:i:s', $tier_expires ) : null,
				'tool_limits'  => $tool_limits,
			)
		);
	}

	/**
	 * Update user's tier.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public static function update_user_tier( $request ) {
		$user_id = absint( $request['id'] );
		$tier    = sanitize_key( $request['tier'] );
		$expiry  = isset( $request['expiry'] ) ? sanitize_text_field( $request['expiry'] ) : '';

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_user',
				__( 'Invalid user ID.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		$expiry_timestamp = 0;
		if ( ! empty( $expiry ) ) {
			$expiry_timestamp = strtotime( $expiry . ' 23:59:59' );
			if ( ! $expiry_timestamp ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_expiry',
					__( 'Invalid expiry date format. Use YYYY-MM-DD.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}
		}

		$success = WP_MCP_AI_Tool_Token_Limits::set_user_tier( $user_id, $tier, $expiry_timestamp );

		if ( ! $success ) {
			return new WP_Error(
				'wp_mcp_ai_tier_update_failed',
				__( 'Failed to update user tier.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success'      => true,
				'user_id'      => $user_id,
				'tier'         => $tier,
				'tier_expires' => $expiry_timestamp ? gmdate( 'Y-m-d H:i:s', $expiry_timestamp ) : null,
				'message'      => __( 'User tier updated successfully.', 'wp-mcp-ai' ),
			)
		);
	}

	/**
	 * Get user's token usage.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public static function get_user_usage( $request ) {
		$user_id   = absint( $request['id'] );
		$tool      = isset( $request['tool'] ) ? sanitize_key( $request['tool'] ) : '';
		$timeframe = isset( $request['timeframe'] ) ? sanitize_key( $request['timeframe'] ) : 'daily';

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_user',
				__( 'Invalid user ID.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		$usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $user_id );

		// Filter by tool if specified.
		if ( ! empty( $tool ) ) {
			if ( ! isset( $usage[ $tool ] ) ) {
				return rest_ensure_response(
					array(
						'user_id' => $user_id,
						'tool'    => $tool,
						'usage'   => array(),
					)
				);
			}
			$usage = array( $tool => $usage[ $tool ] );
		}

		// Format usage based on timeframe.
		$formatted_usage = array();
		foreach ( $usage as $tool_slug => $tool_data ) {
			$usage_data = array(
				'tool'         => $tool_slug,
				'total_tokens' => isset( $tool_data['total_tokens'] ) ? $tool_data['total_tokens'] : 0,
				'requests'     => isset( $tool_data['requests'] ) ? $tool_data['requests'] : 0,
				'first_used'   => isset( $tool_data['first_used'] ) ? $tool_data['first_used'] : '',
				'last_used'    => isset( $tool_data['last_used'] ) ? $tool_data['last_used'] : '',
			);

			if ( 'hourly' === $timeframe && isset( $tool_data['hourly'] ) ) {
				$usage_data['hourly'] = $tool_data['hourly'];
			} elseif ( 'daily' === $timeframe && isset( $tool_data['daily'] ) ) {
				$usage_data['daily'] = $tool_data['daily'];
			}

			$formatted_usage[] = $usage_data;
		}

		return rest_ensure_response(
			array(
				'user_id'   => $user_id,
				'timeframe' => $timeframe,
				'usage'     => $formatted_usage,
			)
		);
	}

	/**
	 * Get usage forecast for a user and tool.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public static function get_usage_forecast( $request ) {
		$user_id   = absint( $request['id'] );
		$tool_slug = sanitize_key( $request['tool'] );

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_user',
				__( 'Invalid user ID.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		$forecast = WP_MCP_AI_Tool_Token_Limits::forecast_limit_exhaustion( $user_id, $tool_slug );

		if ( null === $forecast ) {
			return rest_ensure_response(
				array(
					'user_id'  => $user_id,
					'tool'     => $tool_slug,
					'forecast' => null,
					'message'  => __( 'Insufficient data to generate forecast. Need at least 24 hours of usage history.', 'wp-mcp-ai' ),
				)
			);
		}

		return rest_ensure_response(
			array(
				'user_id'  => $user_id,
				'tool'     => $tool_slug,
				'forecast' => $forecast,
			)
		);
	}

	/**
	 * Permission callback for user access.
	 *
	 * User can access their own data or admins can access any.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if user can access, WP_Error otherwise.
	 */
	public static function check_user_access( $request ) {
		// Verify nonce for logged-in users.
		$current_user_id = get_current_user_id();
		if ( $current_user_id ) {
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
					array(
						'status'  => rest_authorization_required_code(),
						'actions' => array(
							'refresh_nonce' => __( 'Refresh your WordPress session to obtain a fresh nonce and retry the request.', 'wp-mcp-ai' ),
						),
					)
				);
			}
		}

		$user_id = absint( $request['id'] );

		// User can access their own data or admins can access any.
		if ( $user_id === $current_user_id || current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to access this resource.', 'wp-mcp-ai' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Permission callback for admin access.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if user is admin, WP_Error otherwise.
	 */
	public static function check_admin_access( $request ) {
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
					array(
						'status'  => rest_authorization_required_code(),
						'actions' => array(
							'refresh_nonce' => __( 'Refresh your WordPress session to obtain a fresh nonce and retry the request.', 'wp-mcp-ai' ),
						),
					)
				);
			}
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
