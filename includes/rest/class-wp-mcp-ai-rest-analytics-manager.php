<?php
/**
 * REST API Analytics Manager for WP oOS.
 *
 * Provides REST endpoints for analytics data including trends, patterns,
 * comparisons, and anomaly detection.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API Analytics Manager class.
 *
 * Exposes analytics endpoints for external consumption and AJAX requests.
 */
class WP_MCP_AI_REST_Analytics_Manager {

	/**
	 * Register REST API routes.
	 *
	 * Registers analytics-related REST API endpoints for data analysis and insights:
	 * - GET /analytics/trends/{user_id}: Get usage trends for a specific user over time
	 * - GET /analytics/patterns/{user_id}: Analyze usage patterns and behaviors for a user
	 * - GET /analytics/compare: Compare metrics across multiple users
	 * - GET /analytics/anomalies: Detect anomalous usage patterns requiring attention
	 * - GET /analytics/tools/compare: Compare tool usage and performance across tools
	 *
	 * All endpoints require appropriate analytics permissions:
	 * - Users can access their own analytics data
	 * - Administrators can access all analytics data
	 * - Capability: 'manage_options' for cross-user analytics
	 *
	 * Data returned includes:
	 * - Usage trends (tokens, costs, tool calls over time)
	 * - Behavior patterns (peak usage times, tool preferences)
	 * - Comparative metrics (multi-user comparisons)
	 * - Anomaly detection (unusual spikes, potential issues)
	 * - Tool performance analytics (execution times, success rates)
	 *
	 * @since 1.0.0
	 */
	public static function register_routes() {
		// Get user trends.
		register_rest_route(
			'mcp-ai/v1',
			'/analytics/trends/(?P<user_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_user_trends' ),
				'permission_callback' => array( __CLASS__, 'check_analytics_permission' ),
				'args'                => array(
					'user_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => array( __CLASS__, 'validate_user_id' ),
					),
					'days'    => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 30,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Get user patterns.
		register_rest_route(
			'mcp-ai/v1',
			'/analytics/patterns/(?P<user_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_user_patterns' ),
				'permission_callback' => array( __CLASS__, 'check_analytics_permission' ),
				'args'                => array(
					'user_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => array( __CLASS__, 'validate_user_id' ),
					),
				),
			)
		);

		// Compare users.
		register_rest_route(
			'mcp-ai/v1',
			'/analytics/compare',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'compare_users' ),
				'permission_callback' => array( __CLASS__, 'check_analytics_permission' ),
				'args'                => array(
					'user_ids' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( __CLASS__, 'validate_user_ids' ),
					),
					'days'     => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 30,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Get anomalies.
		register_rest_route(
			'mcp-ai/v1',
			'/analytics/anomalies',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_anomalies' ),
				'permission_callback' => array( __CLASS__, 'check_analytics_permission' ),
				'args'                => array(
					'user_id'   => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => array( __CLASS__, 'validate_user_id' ),
					),
					'severity'  => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
						'enum'              => array( '', 'low', 'medium', 'high', 'critical' ),
					),
					'threshold' => array(
						'required'          => false,
						'type'              => 'number',
						'default'           => 3.0,
						'sanitize_callback' => 'floatval',
					),
				),
			)
		);

		// Compare tools.
		register_rest_route(
			'mcp-ai/v1',
			'/analytics/tools/compare',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'compare_tools' ),
				'permission_callback' => array( __CLASS__, 'check_analytics_permission' ),
				'args'                => array(
					'tool_slugs' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( __CLASS__, 'validate_tool_slugs' ),
					),
					'days'       => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 30,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Get user trends endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public static function get_user_trends( $request ) {
		$user_id = $request->get_param( 'user_id' );
		$days    = $request->get_param( 'days' );

		if ( ! class_exists( 'WP_MCP_AI_Analytics_Engine' ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'analytics_unavailable',
					'message' => __( 'Analytics engine is not available.', 'wp-mcp-ai' ),
				),
				500
			);
		}

		$trends = WP_MCP_AI_Analytics_Engine::get_user_trends( $user_id, $days );

		return new WP_REST_Response(
			array(
				'success' => true,
				'user_id' => $user_id,
				'days'    => $days,
				'data'    => $trends,
			),
			200
		);
	}

	/**
	 * Get user patterns endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public static function get_user_patterns( $request ) {
		$user_id = $request->get_param( 'user_id' );

		if ( ! class_exists( 'WP_MCP_AI_Analytics_Engine' ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'analytics_unavailable',
					'message' => __( 'Analytics engine is not available.', 'wp-mcp-ai' ),
				),
				500
			);
		}

		$patterns = WP_MCP_AI_Analytics_Engine::detect_patterns( $user_id );

		return new WP_REST_Response(
			array(
				'success'  => true,
				'user_id'  => $user_id,
				'patterns' => $patterns,
			),
			200
		);
	}

	/**
	 * Compare users endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public static function compare_users( $request ) {
		$user_ids_str = $request->get_param( 'user_ids' );
		$days         = $request->get_param( 'days' );

		// Parse user IDs from comma-separated string.
		$user_ids = array_map( 'absint', explode( ',', $user_ids_str ) );

		if ( count( $user_ids ) !== 2 ) {
			return new WP_REST_Response(
				array(
					'error'   => 'invalid_params',
					'message' => __( 'Exactly two user IDs are required for comparison.', 'wp-mcp-ai' ),
				),
				400
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Analytics_Engine' ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'analytics_unavailable',
					'message' => __( 'Analytics engine is not available.', 'wp-mcp-ai' ),
				),
				500
			);
		}

		$comparison = WP_MCP_AI_Analytics_Engine::compare_users( $user_ids[0], $user_ids[1], $days );

		return new WP_REST_Response(
			array(
				'success'    => true,
				'user_id_1'  => $user_ids[0],
				'user_id_2'  => $user_ids[1],
				'days'       => $days,
				'comparison' => $comparison,
			),
			200
		);
	}

	/**
	 * Get anomalies endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public static function get_anomalies( $request ) {
		$user_id   = $request->get_param( 'user_id' );
		$severity  = $request->get_param( 'severity' );
		$threshold = $request->get_param( 'threshold' );

		if ( ! class_exists( 'WP_MCP_AI_Analytics_Engine' ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'analytics_unavailable',
					'message' => __( 'Analytics engine is not available.', 'wp-mcp-ai' ),
				),
				500
			);
		}

		if ( $user_id ) {
			// Single user anomalies.
			$anomalies = WP_MCP_AI_Analytics_Engine::detect_anomalies( $user_id, $threshold );

			// Filter by severity if specified.
			if ( ! empty( $severity ) ) {
				$anomalies = array_filter(
					$anomalies,
					function ( $anomaly ) use ( $severity ) {
						return $anomaly['severity'] === $severity;
					}
				);
			}

			return new WP_REST_Response(
				array(
					'success'   => true,
					'user_id'   => $user_id,
					'threshold' => $threshold,
					'severity'  => $severity,
					'anomalies' => array_values( $anomalies ),
				),
				200
			);
		} else {
			// All users anomalies.
			global $wpdb;

			$meta_key = WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY;
			$user_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 100",
					$meta_key
				)
			);

			$all_anomalies = array();

			foreach ( $user_ids as $uid ) {
				$anomalies = WP_MCP_AI_Analytics_Engine::detect_anomalies( $uid, $threshold );

				// Filter by severity if specified.
				if ( ! empty( $severity ) ) {
					$anomalies = array_filter(
						$anomalies,
						function ( $anomaly ) use ( $severity ) {
							return $anomaly['severity'] === $severity;
						}
					);
				}

				if ( ! empty( $anomalies ) ) {
					$all_anomalies[ $uid ] = array_values( $anomalies );
				}
			}

			return new WP_REST_Response(
				array(
					'success'   => true,
					'threshold' => $threshold,
					'severity'  => $severity,
					'users'     => count( $all_anomalies ),
					'anomalies' => $all_anomalies,
				),
				200
			);
		}
	}

	/**
	 * Compare tools endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public static function compare_tools( $request ) {
		$tool_slugs_str = $request->get_param( 'tool_slugs' );
		$days           = $request->get_param( 'days' );

		// Parse tool slugs from comma-separated string.
		$tool_slugs = array_map( 'sanitize_key', explode( ',', $tool_slugs_str ) );

		if ( count( $tool_slugs ) !== 2 ) {
			return new WP_REST_Response(
				array(
					'error'   => 'invalid_params',
					'message' => __( 'Exactly two tool slugs are required for comparison.', 'wp-mcp-ai' ),
				),
				400
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Analytics_Engine' ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'analytics_unavailable',
					'message' => __( 'Analytics engine is not available.', 'wp-mcp-ai' ),
				),
				500
			);
		}

		$comparison = WP_MCP_AI_Analytics_Engine::compare_tools( $tool_slugs[0], $tool_slugs[1], $days );

		return new WP_REST_Response(
			array(
				'success'     => true,
				'tool_slug_1' => $tool_slugs[0],
				'tool_slug_2' => $tool_slugs[1],
				'days'        => $days,
				'comparison'  => $comparison,
			),
			200
		);
	}

	/**
	 * Check analytics permission.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if user has permission.
	 */
	public static function check_analytics_permission( $request ) {
		// Require manage_options capability.
		return current_user_can( 'manage_options' );
	}

	/**
	 * Validate user ID.
	 *
	 * @param int             $user_id User ID.
	 * @param WP_REST_Request $request Request object.
	 * @param string          $param   Parameter name.
	 * @return bool True if valid.
	 */
	public static function validate_user_id( $user_id, $request, $param ) {
		if ( $user_id <= 0 ) {
			return false;
		}

		// Check if user exists.
		$user = get_userdata( $user_id );
		return ( $user !== false );
	}

	/**
	 * Validate user IDs string.
	 *
	 * @param string          $user_ids_str Comma-separated user IDs.
	 * @param WP_REST_Request $request      Request object.
	 * @param string          $param        Parameter name.
	 * @return bool True if valid.
	 */
	public static function validate_user_ids( $user_ids_str, $request, $param ) {
		$user_ids = array_map( 'absint', explode( ',', $user_ids_str ) );

		if ( count( $user_ids ) !== 2 ) {
			return false;
		}

		foreach ( $user_ids as $user_id ) {
			if ( $user_id <= 0 ) {
				return false;
			}

			$user = get_userdata( $user_id );
			if ( ! $user ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate tool slugs string.
	 *
	 * @param string          $tool_slugs_str Comma-separated tool slugs.
	 * @param WP_REST_Request $request        Request object.
	 * @param string          $param          Parameter name.
	 * @return bool True if valid.
	 */
	public static function validate_tool_slugs( $tool_slugs_str, $request, $param ) {
		$tool_slugs = explode( ',', $tool_slugs_str );

		if ( count( $tool_slugs ) !== 2 ) {
			return false;
		}

		foreach ( $tool_slugs as $slug ) {
			if ( empty( trim( $slug ) ) ) {
				return false;
			}
		}

		return true;
	}
}
